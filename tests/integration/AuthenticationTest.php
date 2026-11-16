<?php

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in real HTTP tests. Uses synthetic API responses and an isolated users
 * table in the configured MySQL database. Never touches existing users/students.
 * Run with TECHRESERVE_AUTH_INTEGRATION=1; see AUTHENTICATION.md.
 */
final class AuthenticationTest extends TestCase
{
    private static BaseConnection $db;
    private static string $prefix;
    private static string $directory;
    private static string $url;
    private static array $processes = [];
    private static bool $created = false;
    private static bool $enabled = false;
    private $browser;
    private string $password;

    public static function setUpBeforeClass(): void
    {
        self::$enabled = getenv('TECHRESERVE_AUTH_INTEGRATION') === '1';
        if (!self::$enabled) {
            return;
        }
        self::$prefix = 'phase2_test_' . bin2hex(random_bytes(6)) . '_';
        $config = config('Database')->default;
        $config['DBPrefix'] = self::$prefix;
        self::$db = Database::connect($config, false);
        $forge = Database::forge(self::$db);
        require_once APPPATH . 'Database/Migrations/2026-11-16-164844_CreateUsersTable.php';
        require_once APPPATH . 'Database/Migrations/2026-11-17-000001_AddAcademicFieldsToUsers.php';
        (new \App\Database\Migrations\CreateUsersTable($forge))->up();
        self::$created = true;
        (new \App\Database\Migrations\AddAcademicFieldsToUsers($forge))->up();

        self::$directory = sys_get_temp_dir() . '/techreserve-auth-' . bin2hex(random_bytes(6));
        mkdir(self::$directory, 0700);
        mkdir(self::$directory . '/sessions', 0700);
        mkdir(self::$directory . '/cache', 0700);
        $env = array_merge(getenv(), $_ENV);
        $env['CI_ENVIRONMENT'] = 'development';
        $env['AUTH_TEST_API_KEY'] = bin2hex(random_bytes(24));
        $env['AUTH_TEST_DB_PREFIX'] = self::$prefix;
        $apiPort = self::availablePort();
        self::start(['-S', '127.0.0.1:' . $apiPort, TESTPATH . '_support/student-records-router.php'], $env);
        self::waitForPort($apiPort);
        $port = self::availablePort();
        self::$url = 'http://127.0.0.1:' . $port;
        foreach ($config as $key => $value) {
            if (is_scalar($value)) {
                $env['database.default.' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        $env['app.baseURL'] = self::$url . '/';
        $env['app.indexPage'] = '';
        $env['studentRecords.baseUrl'] = 'http://127.0.0.1:' . $apiPort;
        $env['studentRecords.apiKey'] = $env['AUTH_TEST_API_KEY'];
        $env['session.savePath'] = self::$directory . '/sessions';
        $env['cache.file.storePath'] = self::$directory . '/cache/';
        self::start(['-S', '127.0.0.1:' . $port, '-t', PUBLICPATH, TESTPATH . '_support/auth-app-router.php'], $env);
        self::waitForPort($port);
    }

    protected function setUp(): void
    {
        if (!self::$enabled) {
            $this->markTestSkipped('Set TECHRESERVE_AUTH_INTEGRATION=1 to run isolated MySQL/HTTP authentication checks.');
        }
        // Only files in this run's private temporary cache directory are removed.
        foreach (glob(self::$directory . '/cache/*') as $cacheFile) {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
        self::$db->table('users')->where('id >', 0)->delete();
        $this->browser = curl_init();
        curl_setopt_array($this->browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
        $this->password = bin2hex(random_bytes(12));
    }

    protected function tearDown(): void
    {
        if ($this->browser) {
            curl_close($this->browser);
        }
    }

    private function request(string $path, ?array $data = null): array
    {
        $headers = [];
        curl_setopt_array($this->browser, [
            CURLOPT_URL => self::$url . $path,
            CURLOPT_POST => $data !== null,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($data !== null) {
            curl_setopt($this->browser, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = curl_exec($this->browser);
        $this->assertNotFalse($body, 'HTTP transport failed (no credentials included).');
        return ['status' => curl_getinfo($this->browser, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers];
    }

    private function submit(string $path, array $data): array
    {
        $form = $this->request($path);
        $this->assertSame(200, $form['status'], 'Form did not render.');
        $this->assertFalse(str_contains($form['body'], 'debugbar_loader'), 'Authentication pages must not collect debug toolbar data.');
        $this->assertSame(1, preg_match('/name="csrf_test_name" value="([^"]+)"/', $form['body'], $match), 'CSRF field missing.');
        return $this->request($path, $data + ['csrf_test_name' => html_entity_decode($match[1])]);
    }

    private function registration(string $id, array $extra = []): array
    {
        return $this->submit('/register', array_merge([
            'student_id' => $id, 'password' => $this->password, 'password_confirm' => $this->password,
        ], $extra));
    }

    private function seedBorrower(): void
    {
        self::$db->table('users')->insert([
            'student_id' => 'TEST-ENROLLED', 'full_name' => 'Synthetic Student', 'email' => 'synthetic@example.test',
            'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'role' => 'borrower', 'status' => 'active',
            'program' => 'Information Technology', 'program_code' => 'BSITWMA', 'department' => 'Computing',
        ]);
    }

    private function assertRedirect(array $response, string $path): void
    {
        $this->assertContains($response['status'], [302, 303]);
        $this->assertSame($path, str_replace('/index.php/', '/', parse_url($response['headers']['location'] ?? '', PHP_URL_PATH)));
    }

    public function testEnrolledRegistrationAndAcademicSnapshot(): void
    {
        $this->assertRedirect($this->registration('TEST-ENROLLED'), '/login');
        $user = self::$db->table('users')->where('student_id', 'TEST-ENROLLED')->get()->getRowArray();
        $this->assertNotNull($user);
        $this->assertTrue(password_verify($this->password, $user['password_hash']));
        $this->assertNotSame($this->password, $user['password_hash']);
        $this->assertSame('borrower', $user['role']);
        $this->assertSame('active', $user['status']);
        $this->assertSame('Synthetic Student', $user['full_name']);
        $this->assertSame('synthetic@example.test', $user['email']);
        $this->assertSame('BSITWMA', $user['program_code']);
        $this->assertSame('Web and Mobile Applications', $user['specialization']);
        $this->assertSame('Computing', $user['department']);
    }

    public function testRejectedRegistrationNeverCreatesAccounts(): void
    {
        $before = self::$db->table('users')->countAllResults();
        foreach ([
            'TEST-INACTIVE' => 'Only currently enrolled students',
            'TEST-UNKNOWN' => 'Student record not found',
            'TEST-OFFLINE' => 'temporarily unavailable',
            'TEST-TIMEOUT' => 'temporarily unavailable',
            'TEST-MISMATCH' => 'incomplete identity details',
        ] as $id => $message) {
            $this->assertRedirect($this->registration($id), '/register');
            $this->assertStringContainsString($message, $this->request('/register')['body']);
        }
        $this->assertSame($before, self::$db->table('users')->countAllResults());
    }

    public function testDuplicateStudentAndEmailAreRejected(): void
    {
        $this->seedBorrower();
        $before = self::$db->table('users')->countAllResults();
        $this->assertRedirect($this->registration('TEST-ENROLLED'), '/register');
        $this->assertStringContainsString('already has an account', $this->request('/register')['body']);
        $this->assertRedirect($this->registration('TEST-SAMEEMAIL'), '/register');
        $this->assertStringContainsString('already uses this institutional email', $this->request('/register')['body']);
        $this->assertSame($before, self::$db->table('users')->countAllResults());
    }

    public function testAdministratorAndIdentityFieldsCannotBeSelfAssigned(): void
    {
        $this->assertRedirect($this->registration('TEST-TAMPER', [
            'role' => 'admin', 'status' => 'inactive', 'full_name' => 'Forged Name',
            'email' => 'forged@example.test', 'program_code' => 'FORGED',
        ]), '/login');
        $user = self::$db->table('users')->where('student_id', 'TEST-TAMPER')->get()->getRowArray();
        $this->assertSame('borrower', $user['role']);
        $this->assertSame('active', $user['status']);
        $this->assertSame('Synthetic Student', $user['full_name']);
        $this->assertSame('BSITWMA', $user['program_code']);
        $this->assertSame(404, $this->request('/admin/register')['status']);
    }

    public function testValidationAndCsrfProtection(): void
    {
        $before = self::$db->table('users')->countAllResults();
        $this->assertSame(403, $this->request('/register', ['student_id' => 'TEST-UNKNOWN'])['status']);
        foreach ([
            ['student_id' => ''], ['password' => 'short'], ['password_confirm' => 'not-matching'],
            ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)],
            ['student_id' => ['array-input']],
        ] as $extra) {
            $this->assertRedirect($this->registration('TEST-VALIDATION', $extra), '/register');
        }
        $this->assertSame($before, self::$db->table('users')->countAllResults());
        $this->assertSame(403, $this->request('/login', ['student_id' => 'TEST-UNKNOWN', 'password' => $this->password])['status']);
    }

    public function testLoginRegeneratesSessionAndLogoutInvalidatesIt(): void
    {
        $this->seedBorrower();
        $user = self::$db->table('users')->where('student_id', 'TEST-ENROLLED')->get()->getRowArray();
        self::$db->table('users')->where('id', $user['id'])->update(['password_hash' => password_hash($this->password, PASSWORD_DEFAULT)]);
        $this->assertRedirect($this->request('/borrower/dashboard'), '/login');
        $this->assertRedirect($this->submit('/login', ['student_id' => 'TEST-ENROLLED', 'password' => 'incorrect-password']), '/login');
        $this->assertRedirect($this->request('/borrower/dashboard'), '/login');
        $before = curl_getinfo($this->browser, CURLINFO_COOKIELIST);
        $this->assertRedirect($this->submit('/login', ['student_id' => 'TEST-ENROLLED', 'password' => $this->password]), '/borrower/dashboard');
        $after = curl_getinfo($this->browser, CURLINFO_COOKIELIST);
        $this->assertNotSame($before, $after, 'Session ID must change after login.');
        $dashboard = $this->request('/borrower/dashboard');
        $this->assertSame(200, $dashboard['status']);
        $this->assertFalse(str_contains($dashboard['body'], 'debugbar_loader'));
        $this->assertStringContainsString('Synthetic Student', $dashboard['body']);
        $this->assertStringContainsString('no-store', $dashboard['headers']['cache-control']);
        $this->assertSame(403, $this->request('/admin/dashboard')['status']);
        $this->assertSame(403, $this->request('/logout', [])['status']);
        $this->assertSame(1, preg_match('/name="csrf_test_name" value="([^"]+)"/', $dashboard['body'], $match));
        $this->assertRedirect($this->request('/logout', ['csrf_test_name' => $match[1]]), '/login');
        $this->assertRedirect($this->request('/borrower/dashboard'), '/login');
        foreach (glob(self::$directory . '/sessions/*') as $sessionFile) {
            $this->assertFalse(str_contains(file_get_contents($sessionFile), $this->password), 'Passwords must not be stored in session files.');
        }
        // Replaying the previously authenticated cookie must also fail.
        curl_setopt($this->browser, CURLOPT_COOKIELIST, 'ALL');
        foreach ($after as $cookie) {
            curl_setopt($this->browser, CURLOPT_COOKIELIST, $cookie);
        }
        $this->assertRedirect($this->request('/borrower/dashboard'), '/login');
    }

    public function testAdministratorIsolationAndInactiveAccounts(): void
    {
        self::$db->table('users')->insert([
            'student_id' => 'TEST-ADMIN', 'full_name' => 'Synthetic Office Admin', 'email' => 'admin@example.test',
            'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->assertRedirect($this->submit('/login', ['student_id' => 'TEST-ADMIN', 'password' => $this->password]), '/admin/dashboard');
        $this->assertSame(200, $this->request('/admin/dashboard')['status']);
        $this->assertSame(403, $this->request('/borrower/dashboard')['status']);
        self::$db->table('users')->where('student_id', 'TEST-ADMIN')->update(['status' => 'inactive']);
        $this->assertRedirect($this->request('/admin/dashboard'), '/login');
        $this->assertRedirect($this->submit('/login', ['student_id' => 'TEST-ADMIN', 'password' => $this->password]), '/login');
    }

    public function testBasicLoginRateLimit(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertRedirect($this->submit('/login', ['student_id' => 'TEST-RATELIMIT', 'password' => $this->password]), '/login');
        }
        $response = $this->submit('/login', ['student_id' => 'TEST-RATELIMIT', 'password' => $this->password]);
        $this->assertSame(429, $response['status']);
        $this->assertGreaterThan(0, (int) $response['headers']['retry-after']);
    }

    public function testBasicRegistrationRateLimit(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertRedirect($this->registration(''), '/register');
        }
        $this->assertSame(429, $this->registration('')['status']);
    }

    private static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!$socket) {
            throw new RuntimeException('Cannot allocate a test server port.');
        }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    private static function start(array $arguments, array $env): void
    {
        $log = self::$directory . '/server-' . count(self::$processes) . '.log';
        // Populate $_ENV so CI's dotenv loader preserves inherited test overrides.
        $process = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS'], $arguments), [
            0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a'],
        ], $pipes, ROOTPATH, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch isolated test server.');
        }
        fclose($pipes[0]);
        self::$processes[] = $process;
    }

    private static function waitForPort(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Isolated server startup timed out.');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        if (self::$created && preg_match('/\Aphase2_test_[a-f0-9]{12}_\z/', self::$prefix) && self::$db->DBPrefix === self::$prefix) {
            Database::forge(self::$db)->dropTable('users', true);
        }
        // Keep temporary runtime logs for diagnosis; no project data is removed.
    }
}
