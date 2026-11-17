<?php

use App\Models\UserModel;
use App\Services\AdministratorProvisioningService;
use Config\Database;
use PHPUnit\Framework\TestCase;

/** Real HTTP, session, CSRF and MySQL tests; only randomly prefixed tables are changed. */
final class ResourceManagementTest extends TestCase
{
    private static $db;
    private static string $prefix;
    private static string $directory;
    private static string $url;
    private static $server;
    private static array $created = [];
    private $browser;
    private string $password;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TECHRESERVE_RESOURCE_INTEGRATION') !== '1') {
            return;
        }
        // Keep the prefix short enough for MySQL foreign-key constraint names.
        self::$prefix = 'p3_' . bin2hex(random_bytes(6)) . '_';
        $config = config('Database')->tests;
        Tests\Support\IsolatedMySqlDatabase::verify($config, config('Database')->default, 'techreserve_test_db');
        $config['DBPrefix'] = self::$prefix;
        self::$db = Database::connect($config, false);
        $forge = Database::forge(self::$db);
        try {
            foreach ([
                ['2026-11-16-164844_CreateUsersTable', 'CreateUsersTable', 'users'],
                ['2026-11-17-000001_AddAcademicFieldsToUsers', 'AddAcademicFieldsToUsers', null],
                ['2026-11-16-165159_CreateResourcesTable', 'CreateResourcesTable', 'resources'],
                ['2026-11-17-000002_AddResourceDetails', 'AddResourceDetails', null],
                ['2026-11-16-165426_CreateReservationsTable', 'CreateReservationsTable', 'reservations'],
                ['2026-11-16-165525_CreateReservationItemsTable', 'CreateReservationItemsTable', 'reservation_items'],
            ] as [$file, $class, $table]) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . $class;
                (new $class($forge))->up();
                if ($table) {
                    self::$created[] = $table;
                }
            }
            self::$directory = sys_get_temp_dir() . '/techreserve-resources-' . bin2hex(random_bytes(6));
            mkdir(self::$directory, 0700);
            mkdir(self::$directory . '/sessions', 0700);
            mkdir(self::$directory . '/cache', 0700);
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            self::$url = 'http://127.0.0.1:' . $port;
            $env = array_merge(getenv(), $_ENV);
            foreach ($config as $key => $value) {
                if (is_scalar($value)) {
                    $env['database.default.' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
            }
            $env['AUTH_TEST_DB_PREFIX'] = self::$prefix;
            $env['CI_ENVIRONMENT'] = 'development';
            $env['app.baseURL'] = self::$url . '/';
            $env['app.indexPage'] = '';
            $env['session.savePath'] = self::$directory . '/sessions';
            $env['cache.file.storePath'] = self::$directory . '/cache/';
            // Resource management and administrator provisioning must not need this API.
            $env['studentRecords.baseUrl'] = 'http://127.0.0.1:1';
            self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', PUBLICPATH, TESTPATH . '_support/auth-app-router.php'], [
                0 => ['pipe', 'r'], 1 => ['file', self::$directory . '/server.log', 'a'], 2 => ['file', self::$directory . '/server.log', 'a'],
            ], $pipes, ROOTPATH, $env, ['bypass_shell' => true]);
            if (!is_resource(self::$server)) {
                throw new RuntimeException('Cannot start isolated resource test server.');
            }
            fclose($pipes[0]);
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
                if ($socket) {
                    fclose($socket);
                    return;
                }
                usleep(100000);
            }
            throw new RuntimeException('Resource test server startup timed out.');
        } catch (Throwable $exception) {
            self::tearDownAfterClass();
            throw $exception;
        }
    }

    protected function setUp(): void
    {
        if (getenv('TECHRESERVE_RESOURCE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set TECHRESERVE_RESOURCE_INTEGRATION=1 for isolated resource tests.');
        }
        foreach (array_reverse(self::$created) as $table) {
            self::$db->table($table)->where('id >', 0)->delete();
        }
        foreach (glob(self::$directory . '/cache/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->password = bin2hex(random_bytes(12));
        foreach (['admin' => 'OFFICE-TEST-01', 'borrower' => 'RESOURCE-STUDENT'] as $role => $identifier) {
            self::$db->table('users')->insert([
                'student_id' => $identifier, 'full_name' => 'Synthetic ' . $role, 'email' => $role . '@example.test',
                'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active',
            ]);
        }
        $this->browser = curl_init();
        curl_setopt_array($this->browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
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
        curl_setopt_array($this->browser, [CURLOPT_URL => self::$url . $path, CURLOPT_POST => $data !== null,
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
        $this->assertNotFalse($body, 'HTTP transport failed.');
        return ['status' => curl_getinfo($this->browser, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers];
    }

    private function token(string $path): string
    {
        $response = $this->request($path);
        $this->assertSame(200, $response['status'], 'Expected form page.');
        $this->assertSame(1, preg_match('/name="csrf_test_name" value="([^"]+)"/', $response['body'], $match));
        return html_entity_decode($match[1]);
    }

    private function postForm(string $form, string $target, array $data): array
    {
        return $this->request($target, $data + ['csrf_test_name' => $this->token($form)]);
    }

    private function login(string $role = 'admin'): void
    {
        $response = $this->postForm('/login', '/login', [
            'student_id' => $role === 'admin' ? 'OFFICE-TEST-01' : 'RESOURCE-STUDENT', 'password' => $this->password,
        ]);
        $this->assertContains($response['status'], [302, 303]);
        $this->assertStringContainsString('/' . $role . '/dashboard', $response['headers']['location']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Synthetic Seminar Room', 'description' => 'Academic use', 'resource_type' => 'facility',
            'quantity' => '1', 'rate_per_hour' => '125.50', 'status' => 'available', 'location' => 'Building A', 'capacity' => '30',
        ], $overrides);
    }

    private function resource(array $overrides = []): int
    {
        self::$db->table('resources')->insert($this->data($overrides));
        return (int) self::$db->insertID();
    }

    public function testAdministratorCreatesViewsAndEditsResources(): void
    {
        $this->login();
        $response = $this->postForm('/admin/resources/new', '/admin/resources', $this->data(['id' => '90000', 'deleted_at' => '2020-01-01 00:00:00']));
        $this->assertContains($response['status'], [302, 303]);
        $resource = self::$db->table('resources')->get()->getRowArray();
        $id = $resource['id'];
        $this->assertNotSame('90000', (string) $id);
        $this->assertNull($resource['deleted_at']);
        $this->assertSame('125.50', $resource['rate_per_hour']);
        $this->assertSame(200, $this->request('/admin/resources/' . $id)['status']);
        $this->assertStringContainsString('Synthetic Seminar Room', $this->request('/admin/resources')['body']);
        $response = $this->postForm('/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/edit', $this->data([
            'name' => 'Synthetic Projectors', 'resource_type' => 'equipment', 'quantity' => '8', 'capacity' => '', 'rate_per_hour' => '0', 'status' => 'maintenance',
        ]));
        $this->assertContains($response['status'], [302, 303]);
        $updated = self::$db->table('resources')->where('id', $id)->get()->getRowArray();
        $this->assertSame('equipment', $updated['resource_type']);
        $this->assertSame(8, (int) $updated['quantity']);
        $this->assertNull($updated['capacity']);
        $this->assertSame('0.00', $updated['rate_per_hour']);
        $this->assertSame('maintenance', $updated['status']);
    }

    public function testValidationRejectsInvalidResourcesWithoutWriting(): void
    {
        $this->login();
        foreach ([['name' => ' '], ['resource_type' => 'vehicle'], ['quantity' => '2'], ['quantity' => '0'],
            ['resource_type' => 'equipment', 'capacity' => '', 'quantity' => '-1'],
            ['resource_type' => 'equipment', 'capacity' => '', 'quantity' => '1.5'],
            ['quantity' => '4294967296'], ['rate_per_hour' => '-1'], ['rate_per_hour' => '1.234'],
            ['rate_per_hour' => '100000000.00'], ['rate_per_hour' => '1e3'], ['rate_per_hour' => ['array']],
            ['capacity' => '0'], ['capacity' => '4294967296'], ['status' => 'archived'], ['location' => str_repeat('x', 151)],
            ['capacity' => '-1'], ['capacity' => '1.5'], ['capacity' => 'invalid'], ['capacity' => ['array']],
        ] as $invalid) {
            $this->assertSame(422, $this->postForm('/admin/resources/new', '/admin/resources', $this->data($invalid))['status']);
        }
        $this->assertSame(0, self::$db->table('resources')->countAllResults());
        $id = $this->resource();
        $this->assertSame(422, $this->postForm('/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/edit', $this->data(['quantity' => '0']))['status']);
        $this->assertSame(1, (int) self::$db->table('resources')->where('id', $id)->get()->getRowArray()['quantity']);
    }

    public function testEquipmentCapacityIsAlwaysNullOnCreateAndEdit(): void
    {
        $this->login();
        foreach (['0', '30', '', ['unexpected-array']] as $capacity) {
            $response = $this->postForm('/admin/resources/new', '/admin/resources', $this->data([
                'resource_type' => 'equipment', 'quantity' => '4', 'capacity' => $capacity,
            ]));
            $this->assertContains($response['status'], [302, 303]);
        }
        $this->assertSame(4, self::$db->table('resources')->countAllResults());
        $this->assertSame(0, self::$db->table('resources')->where('capacity IS NOT NULL', null, false)->countAllResults());

        $id = $this->resource(['capacity' => '40']);
        $response = $this->postForm('/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/edit', $this->data([
            'resource_type' => 'equipment', 'quantity' => '2', 'capacity' => '40',
        ]));
        $this->assertContains($response['status'], [302, 303]);
        $this->assertNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['capacity']);
        $form = $this->request('/admin/resources/' . $id . '/edit');
        $document = new DOMDocument();
        @$document->loadHTML($form['body']);
        $this->assertTrue($document->getElementById('capacity-field')->hasAttribute('hidden'));
        $this->assertTrue($document->getElementById('capacity')->hasAttribute('disabled'));
        $this->assertStringContainsString('/js/resource-form.js', $form['body']);

        // Convert back to a facility: an omitted capacity stays optional.
        $data = $this->data();
        unset($data['capacity']);
        $response = $this->postForm('/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/edit', $data);
        $this->assertContains($response['status'], [302, 303]);
        $this->assertNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['capacity']);
        $response = $this->postForm('/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/edit', $this->data(['capacity' => '25']));
        $this->assertContains($response['status'], [302, 303]);
        $this->assertSame(25, (int) self::$db->table('resources')->where('id', $id)->get()->getRowArray()['capacity']);
        $form = $this->request('/admin/resources/' . $id . '/edit');
        @$document->loadHTML($form['body']);
        $this->assertFalse($document->getElementById('capacity-field')->hasAttribute('hidden'));
        $this->assertFalse($document->getElementById('capacity')->hasAttribute('disabled'));
        $this->assertFalse($document->getElementById('capacity')->hasAttribute('required'));
    }

    public function testArchivingRequiresConfirmationAndPreservesReservationReferences(): void
    {
        $this->login();
        $id = $this->resource();
        $borrower = self::$db->table('users')->where('role', 'borrower')->get()->getRowArray();
        self::$db->table('reservations')->insert(['user_id' => $borrower['id'], 'purpose' => 'Synthetic reference fixture', 'start_datetime' => '2030-01-01 09:00:00', 'end_datetime' => '2030-01-01 10:00:00']);
        self::$db->table('reservation_items')->insert(['reservation_id' => self::$db->insertID(), 'resource_id' => $id, 'quantity' => 1, 'rate_per_hour' => '125.50', 'subtotal' => '125.50']);
        $response = $this->postForm('/admin/resources/' . $id . '/archive', '/admin/resources/' . $id . '/archive', []);
        $this->assertContains($response['status'], [302, 303]);
        $this->assertNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['deleted_at']);
        $response = $this->postForm('/admin/resources/' . $id . '/archive', '/admin/resources/' . $id . '/archive', ['confirm_archive' => 'yes']);
        $this->assertContains($response['status'], [302, 303]);
        $this->assertNotNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['deleted_at']);
        $this->assertSame(1, self::$db->table('reservation_items')->where('resource_id', $id)->countAllResults());
        foreach (['', '/edit', '/archive'] as $suffix) {
            $this->assertSame(404, $this->request('/admin/resources/' . $id . $suffix)['status']);
        }
        $this->assertStringNotContainsString('Synthetic Seminar Room', $this->request('/admin/resources')['body']);
        $this->assertStringNotContainsString('Synthetic Seminar Room', $this->request('/admin/resources?q=Synthetic')['body']);
        foreach (['/edit', '/archive'] as $suffix) {
            $token = $this->token('/admin/resources');
            $this->assertSame(404, $this->request('/admin/resources/' . $id . $suffix, $this->data(['csrf_test_name' => $token, 'confirm_archive' => 'yes']))['status']);
        }
        curl_setopt($this->browser, CURLOPT_COOKIELIST, 'ALL');
        $this->login('borrower');
        $this->assertSame(404, $this->request('/borrower/resources/' . $id)['status']);
        $this->assertStringNotContainsString('Synthetic Seminar Room', $this->request('/borrower/resources')['body']);
    }

    public function testBorrowerCannotAccessAnyAdministratorResourceAction(): void
    {
        $id = $this->resource();
        $this->login('borrower');
        foreach (['', '/new', '/' . $id, '/' . $id . '/edit', '/' . $id . '/archive'] as $suffix) {
            $this->assertSame(403, $this->request('/admin/resources' . $suffix)['status']);
        }
        foreach (['', '/' . $id . '/edit', '/' . $id . '/archive'] as $suffix) {
            $token = $this->token('/borrower/resources');
            $this->assertSame(403, $this->request('/admin/resources' . $suffix, $this->data(['csrf_test_name' => $token, 'confirm_archive' => 'yes']))['status']);
        }
        $resource = self::$db->table('resources')->where('id', $id)->get()->getRowArray();
        $this->assertNull($resource['deleted_at']);
        $this->assertSame(1, self::$db->table('resources')->countAllResults());
        $this->assertSame(404, $this->request('/borrower/resources', ['csrf_test_name' => $this->token('/borrower/resources')])['status']);
    }

    public function testGuestsAreRedirectedAndInactiveAdministratorsAreBlocked(): void
    {
        $id = $this->resource();
        foreach (['/admin/resources', '/admin/resources/new', '/admin/resources/' . $id, '/borrower/resources', '/borrower/resources/' . $id] as $path) {
            $response = $this->request($path);
            $this->assertSame(302, $response['status']);
            $this->assertStringContainsString('/login', $response['headers']['location']);
        }
        $this->login();
        self::$db->table('users')->where('student_id', 'OFFICE-TEST-01')->update(['status' => 'inactive']);
        $this->assertSame(302, $this->request('/admin/resources')['status']);
    }

    public function testCsrfProtectsAllResourceWrites(): void
    {
        $id = $this->resource();
        $this->login();
        foreach (['/admin/resources', '/admin/resources/' . $id . '/edit', '/admin/resources/' . $id . '/archive'] as $path) {
            $this->assertSame(403, $this->request($path, $this->data(['confirm_archive' => 'yes']))['status']);
        }
        $this->assertSame(1, self::$db->table('resources')->countAllResults());
        $this->assertNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['deleted_at']);
    }

    public function testCatalogIncludesAllStatusesAndSupportsFiltersAndPagination(): void
    {
        foreach (['available', 'unavailable', 'maintenance'] as $status) {
            $this->resource(['name' => 'Status ' . $status, 'status' => $status]);
        }
        $this->resource(['name' => 'Equipment Kit', 'resource_type' => 'equipment', 'quantity' => '4', 'capacity' => null, 'location' => 'Library']);
        $this->login('borrower');
        $response = $this->request('/borrower/resources');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('no-store', $response['headers']['cache-control']);
        $this->assertStringNotContainsString('debugbar_loader', $response['body']);
        foreach (['Status available', 'Status unavailable', 'Status maintenance', 'Equipment Kit'] as $name) {
            $this->assertStringContainsString($name, $response['body']);
        }
        $this->assertStringNotContainsString('Create resource', $response['body']);
        $response = $this->request('/borrower/resources?resource_type=equipment&status=available&q=Library');
        $this->assertStringContainsString('Equipment Kit', $response['body']);
        $this->assertStringNotContainsString('Status maintenance', $response['body']);
        $this->assertStringContainsString('No resources match', $this->request('/borrower/resources?q=NoSuchResource')['body']);
        $this->assertSame(200, $this->request('/borrower/resources?q[]=array&status[]=array')['status']);
        for ($n = 0; $n < 12; $n++) {
            $this->resource(['name' => 'Paged ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
        }
        $first = $this->request('/borrower/resources?q=Paged');
        $second = $this->request('/borrower/resources?q=Paged&page=2');
        $this->assertSame(10, substr_count($first['body'], 'data-resource-id='));
        $this->assertSame(2, substr_count($second['body'], 'data-resource-id='));
        $this->assertStringContainsString('Paged 10', $second['body']);
    }

    public function testDescriptionsAreEscapedAndMissingResourcesReturn404(): void
    {
        $this->login();
        $id = $this->resource(['description' => '<script>alert(1)</script>']);
        $response = $this->request('/admin/resources/' . $id);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response['body']);
        $this->assertStringContainsString('&lt;script&gt;', $response['body']);
        $this->assertSame(404, $this->request('/admin/resources/999999')['status']);
        $this->assertSame(404, $this->request('/admin/create')['status']);
    }

    public function testAdministratorListingFiltersAndPagination(): void
    {
        $this->login();
        for ($number = 0; $number < 12; $number++) {
            $this->resource(['name' => 'AdminPaged ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'status' => 'maintenance']);
        }
        $this->resource(['name' => 'Excluded Equipment', 'resource_type' => 'equipment', 'capacity' => null]);
        $query = '?q=AdminPaged&resource_type=facility&status=maintenance';
        $first = $this->request('/admin/resources' . $query);
        $second = $this->request('/admin/resources' . $query . '&page=2');
        $this->assertSame(10, substr_count($first['body'], 'data-resource-id='));
        $this->assertSame(2, substr_count($second['body'], 'data-resource-id='));
        $this->assertStringNotContainsString('Excluded Equipment', $first['body']);
        $this->assertStringContainsString('AdminPaged 10', $second['body']);
    }

    public function testAdministratorProvisioningHashesAndRejectsDuplicatesWithoutChangingBorrowers(): void
    {
        $service = new AdministratorProvisioningService(new UserModel(self::$db));
        $id = $service->create('OFFICE-NEW-01', 'Synthetic Office', 'new-office@example.test', $this->password, $this->password);
        $user = self::$db->table('users')->where('id', $id)->get()->getRowArray();
        $this->assertSame('admin', $user['role']);
        $this->assertSame('active', $user['status']);
        $this->assertNull($user['program']);
        $this->assertTrue(password_verify($this->password, $user['password_hash']));
        foreach ([['OFFICE-NEW-01', 'other@example.test'], ['OFFICE-NEW-02', 'new-office@example.test'], ['OFFICE-NEW-03', 'borrower@example.test']] as [$identifier, $email]) {
            try {
                $service->create($identifier, 'Synthetic Office', $email, $this->password, $this->password);
                $this->fail('Duplicate account must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('already uses', $exception->getMessage());
            }
        }
        $this->assertSame('borrower', self::$db->table('users')->where('email', 'borrower@example.test')->get()->getRowArray()['role']);
        foreach ([['BAD-ID', $this->password, $this->password], ['OFFICE-BAD', 'short', 'short'], ['OFFICE-BAD', $this->password, 'not-matching']] as [$identifier, $password, $confirmation]) {
            try {
                $service->create($identifier, 'Synthetic Office', 'invalid@example.test', $password, $confirmation);
                $this->fail('Invalid administrator input must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('matching passwords', $exception->getMessage());
            }
        }
        $this->assertSame(3, self::$db->table('users')->countAllResults());
    }

    public function testAdministratorCommandRefusesProductionAndRedirectedInput(): void
    {
        foreach (['production' => 'only through CLI in development mode', 'development' => 'interactive terminal'] as $mode => $message) {
            $code = '$_ENV["CI_ENVIRONMENT"] = $_SERVER["CI_ENVIRONMENT"] = "' . $mode . '"; putenv("CI_ENVIRONMENT=' . $mode . '"); require "spark";';
            $process = proc_open([PHP_BINARY, '-r', $code, 'admin:create'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, null, ['bypass_shell' => true]);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertNotSame(0, proc_close($process));
            $this->assertStringContainsString($message, $output);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (isset(self::$prefix) && preg_match('/\Ap3_[a-f0-9]{12}_\z/', self::$prefix) && self::$db->DBPrefix === self::$prefix) {
            foreach (array_reverse(self::$created) as $table) {
                Database::forge(self::$db)->dropTable($table, true);
            }
        }
    }
}
