<?php

use App\Models\UserModel;
use App\Services\AdministratorProvisioningService;
use Config\Database;
use PHPUnit\Framework\TestCase;

/** Real HTTP, session, CSRF and MySQL tests; only randomly prefixed tables are changed. */
final class ReservationManagementTest extends TestCase
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
        if (getenv('TECHRESERVE_RESERVATION_INTEGRATION') !== '1') {
            return;
        }
        // Keep the prefix short enough for MySQL foreign-key constraint names.
        self::$prefix = 'p4_' . bin2hex(random_bytes(6)) . '_';
        $config = config('Database')->default;
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
                ['2026-11-17-000003_AddReservationBookingFields', 'AddReservationBookingFields', null],
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
        if (getenv('TECHRESERVE_RESERVATION_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set TECHRESERVE_RESERVATION_INTEGRATION=1 for isolated resource tests.');
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

    private function borrower(): int
    {
        return (int) self::$db->table('users')->where('role', 'borrower')->get()->getRowArray()['id'];
    }

    private function admin(): int
    {
        return (int) self::$db->table('users')->where('role', 'admin')->get()->getRowArray()['id'];
    }

    private function booking(array $ids, string $start = '09:00', string $end = '10:00', array $quantities = []): array
    {
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        return ['date' => $date, 'end_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'purpose' => 'Synthetic academic activity', 'submission_key' => \App\Services\BookingRules::key(),
            'items' => array_map(static fn($id) => ['resource_id' => (string) $id, 'quantity' => (string) ($quantities[$id] ?? 1)], $ids)];
    }

    private function service(): \App\Services\ReservationService
    {
        return new \App\Services\ReservationService(self::$db);
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected booking policy rejection.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testScheduleRulesAndManilaUtcMidnightConversion(): void
    {
        $rules = new \App\Services\BookingRules();
        $now = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $input = ['date' => '2030-01-02', 'end_date' => '2030-01-02', 'start_time' => '09:00', 'end_time' => '09:10'];
        $this->assertSame(10, $rules->schedule($input, $now)['minutes']);
        foreach (['09:00', '09:10', '09:20', '09:30', '09:40', '09:50'] as $time) {
            $valid = array_replace($input, ['start_time' => $time, 'end_time' => '10:00']);
            $this->assertGreaterThanOrEqual(10, $rules->schedule($valid, $now)['minutes']);
        }
        $this->assertSame(480, $rules->schedule(array_replace($input, ['end_time' => '17:00']), $now)['minutes']);
        $this->assertSame(10, $rules->schedule(array_replace($input, ['date' => '2030-01-31', 'end_date' => '2030-01-31', 'start_time' => '08:00', 'end_time' => '08:10']), $now)['minutes']);
        foreach ([['end_time' => '09:05'], ['start_time' => '09:05', 'end_time' => '09:15'], ['end_time' => '09:00'],
            ['end_time' => '08:50'], ['end_time' => '17:10'], ['date' => '2029-12-31'], ['date' => '2030-02-30'],
            ['date' => '2030-02-01', 'end_date' => '2030-02-01'], ['start_time' => ['array']],
            ['start_time' => '09:00:01'], ['date' => '2030-01-31', 'end_date' => '2030-01-31', 'start_time' => '08:10', 'end_time' => '08:20'],
        ] as $invalid) {
            $this->reject(fn() => $rules->schedule(array_replace($input, $invalid), $now));
        }
        $cross = $rules->schedule(array_replace($input, ['start_time' => '23:50', 'end_date' => '2030-01-03', 'end_time' => '00:10']), $now);
        $this->assertSame('2030-01-02 15:50:00', $cross['start_datetime']);
        $this->assertSame('2030-01-02 16:10:00', $cross['end_datetime']);
        $this->assertSame(20, $cross['minutes']);
        $this->assertSame('2030-01-03 00:10', \App\Services\BookingRules::display($cross['end_datetime']));
    }

    public function testExactMoneyHalfUpLineRoundingAndOverflow(): void
    {
        $this->assertSame(4000, \App\Services\BookingRules::fee('60.00', 20, 2));
        $this->assertSame(1, \App\Services\BookingRules::fee('0.03', 10, 1));
        $this->assertSame(0, \App\Services\BookingRules::fee('0.01', 10, 1));
        $this->assertSame(0, \App\Services\BookingRules::fee('0.00', 480, 4294967295));
        $this->reject(fn() => \App\Services\BookingRules::fee('99999999.99', 480, 4294967295));
        $this->reject(fn() => \App\Services\BookingRules::fee('-1.00', 10, 1));
        $first = $this->resource(['rate_per_hour' => '0.03']);
        $second = $this->resource(['rate_per_hour' => '0.03']);
        $quote = $this->service()->preview($this->borrower(), $this->booking([$first, $second], '09:00', '09:10'));
        $this->assertSame('0.02', $quote['total_amount']);
        self::$db->table('resources')->whereIn('id', [$first, $second])->update(['rate_per_hour' => '99999999.99']);
        $this->reject(fn() => $this->service()->submit($this->borrower(), $this->booking([$first, $second])));
        $this->assertSame(0, self::$db->table('reservations')->countAllResults());
    }

    public function testMultipleResourceSubmissionIdempotencyAndRateSnapshots(): void
    {
        $facility = $this->resource(['rate_per_hour' => '60.00']);
        $equipment = $this->resource(['resource_type' => 'equipment', 'quantity' => '4', 'capacity' => null, 'rate_per_hour' => '60.00']);
        $input = $this->booking([$facility, $equipment], '09:00', '09:20', [$equipment => 2]);
        $id = $this->service()->submit($this->borrower(), $input);
        $row = self::$db->table('reservations')->where('id', $id)->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('60.00', $row['total_amount']);
        $this->assertSame('PHP', $row['currency']);
        $this->assertSame(2, self::$db->table('reservation_items')->where('reservation_id', $id)->countAllResults());
        $this->assertSame($id, $this->service()->submit($this->borrower(), $input));
        $this->reject(fn() => $this->service()->submit($this->borrower(), array_replace($input, ['purpose' => 'Changed'])));
        self::$db->table('resources')->whereIn('id', [$facility, $equipment])->update(['rate_per_hour' => '500.00']);
        $this->service()->decide($this->admin(), $id, 'approve');
        $this->assertSame('60.00', self::$db->table('reservations')->where('id', $id)->get()->getRowArray()['total_amount']);
        $this->assertSame('60.00', self::$db->table('reservation_items')->where('reservation_id', $id)->get()->getRowArray()['rate_per_hour']);
        $this->assertSame(1, self::$db->table('reservations')->countAllResults());
    }

    public function testInvalidQuantitiesDuplicateResourcesAndTamperedPrices(): void
    {
        $facility = $this->resource();
        $equipment = $this->resource(['resource_type' => 'equipment', 'quantity' => '3', 'capacity' => null]);
        foreach ([$this->booking([$facility], quantities: [$facility => 2]), $this->booking([$equipment], quantities: [$equipment => 0]),
            $this->booking([$equipment], quantities: [$equipment => 4]), $this->booking([$facility, $facility]),
            array_replace($this->booking([$facility]), ['total_amount' => '0.00']),
            array_replace($this->booking([$facility]), ['user_id' => $this->admin()]),
            array_replace($this->booking([$facility]), ['status' => 'approved']),
            array_replace($this->booking([$facility]), ['submission_key' => 'invalid']),
        ] as $invalid) {
            $this->reject(fn() => $this->service()->submit($this->borrower(), $invalid));
        }
        $input = $this->booking([$facility]);
        $input['items'][0]['rate_per_hour'] = '0.00';
        $this->reject(fn() => $this->service()->submit($this->borrower(), $input));
        $this->reject(fn() => $this->service()->submit($this->admin(), $this->booking([$facility])));
        foreach (['unavailable', 'maintenance'] as $status) {
            self::$db->table('resources')->where('id', $facility)->update(['status' => $status]);
            $this->reject(fn() => $this->service()->submit($this->borrower(), $this->booking([$facility])));
        }
        self::$db->table('resources')->where('id', $facility)->update(['status' => 'available', 'deleted_at' => gmdate('Y-m-d H:i:s')]);
        $this->reject(fn() => $this->service()->submit($this->borrower(), $this->booking([$facility])));
        $this->assertSame(0, self::$db->table('reservations')->countAllResults());
    }

    public function testFacilityConflictsBackToBackAndNonConsumingStatuses(): void
    {
        $resource = $this->resource();
        $one = $this->service()->submit($this->borrower(), $this->booking([$resource]));
        $two = $this->service()->submit($this->borrower(), $this->booking([$resource]));
        $this->service()->decide($this->admin(), $one, 'approve');
        $this->reject(fn() => $this->service()->decide($this->admin(), $two, 'approve'));
        $this->reject(fn() => $this->service()->submit($this->borrower(), $this->booking([$resource], '09:30', '10:30')));
        $back = $this->service()->submit($this->borrower(), $this->booking([$resource], '10:00', '11:00'));
        $this->service()->decide($this->admin(), $back, 'approve');
        $this->service()->decide($this->admin(), $one, 'cancel', 'Synthetic cancellation');
        $this->service()->decide($this->admin(), $two, 'approve');
        $row = self::$db->table('reservations')->where('id', $two)->get()->getRowArray();
        $this->assertSame('approved', $row['status']);
        $this->assertSame($this->admin(), (int) $row['admin_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->reject(fn() => $this->service()->decide($this->admin(), $two, 'approve'));
    }

    public function testEquipmentPeakUsesTimeSegmentsInsteadOfSumOfOverlaps(): void
    {
        $id = $this->resource(['resource_type' => 'equipment', 'quantity' => '4', 'capacity' => null]);
        foreach ([['09:00', '10:00'], ['10:00', '11:00']] as [$start, $end]) {
            $reservation = $this->service()->submit($this->borrower(), $this->booking([$id], $start, $end, [$id => 2]));
            $this->service()->decide($this->admin(), $reservation, 'approve');
        }
        $wide = $this->service()->submit($this->borrower(), $this->booking([$id], '09:00', '11:00', [$id => 2]));
        $this->service()->decide($this->admin(), $wide, 'approve');
        $this->reject(fn() => $this->service()->submit($this->borrower(), $this->booking([$id], '09:30', '10:30')));
        $this->assertSame(3, self::$db->table('reservations')->where('status', 'approved')->countAllResults());
    }

    public function testDecisionsCancellationCompletionAndBorrowerRevalidation(): void
    {
        $resource = $this->resource();
        $id = $this->service()->submit($this->borrower(), $this->booking([$resource]));
        $this->reject(fn() => $this->service()->decide($this->admin(), $id, 'reject'));
        $this->service()->decide($this->admin(), $id, 'reject', 'Synthetic rejection reason');
        $row = self::$db->table('reservations')->where('id', $id)->get()->getRowArray();
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Synthetic rejection reason', $row['admin_remarks']);
        $id = $this->service()->submit($this->borrower(), $this->booking([$resource]));
        $this->service()->decide($this->borrower(), $id, 'borrower_cancel');
        $this->reject(fn() => $this->service()->decide($this->borrower(), $id, 'borrower_cancel'));
        $id = $this->service()->submit($this->borrower(), $this->booking([$resource]));
        self::$db->table('users')->where('id', $this->borrower())->update(['status' => 'inactive']);
        $this->reject(fn() => $this->service()->decide($this->admin(), $id, 'approve'));
        self::$db->table('users')->where('id', $this->borrower())->update(['status' => 'active']);
        $this->service()->decide($this->admin(), $id, 'approve');
        $this->reject(fn() => $this->service()->decide($this->borrower(), $id, 'borrower_cancel'));
        $this->reject(fn() => $this->service()->decide($this->admin(), $id, 'complete'));
        self::$db->table('reservations')->where('id', $id)->update(['start_datetime' => gmdate('Y-m-d H:i:s', time() - 3600), 'end_datetime' => gmdate('Y-m-d H:i:s', time() - 600)]);
        $this->reject(fn() => $this->service()->decide($this->admin(), $id, 'cancel', 'Too late'));
        $this->service()->decide($this->admin(), $id, 'complete');
        $this->assertSame('completed', self::$db->table('reservations')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame(1, self::$db->table('reservation_items')->where('reservation_id', $id)->countAllResults());
    }

    public function testApprovalRechecksOperationalStatusAndResourceEditGuards(): void
    {
        $id = $this->resource(['resource_type' => 'equipment', 'quantity' => '5', 'capacity' => null]);
        $pending = $this->service()->submit($this->borrower(), $this->booking([$id], quantities: [$id => 3]));
        self::$db->table('resources')->where('id', $id)->update(['status' => 'maintenance']);
        $this->reject(fn() => $this->service()->decide($this->admin(), $pending, 'approve'));
        self::$db->table('resources')->where('id', $id)->update(['status' => 'available', 'deleted_at' => gmdate('Y-m-d H:i:s')]);
        $this->reject(fn() => $this->service()->decide($this->admin(), $pending, 'approve'));
        self::$db->table('resources')->where('id', $id)->update(['deleted_at' => null]);
        $this->service()->decide($this->admin(), $pending, 'approve');
        $guard = new \App\Services\ResourceBookingGuard(self::$db);
        [$data, $errors] = (new \App\Services\ResourceService())->validate($this->data(['resource_type' => 'equipment', 'capacity' => '', 'quantity' => '5']));
        $this->assertSame([], $errors);
        $this->reject(fn() => $guard->change($id, array_replace($data, ['quantity' => 2])));
        $this->reject(fn() => $guard->change($id, array_replace($data, ['status' => 'unavailable'])));
        $this->reject(fn() => $guard->change($id, array_replace($data, ['resource_type' => 'facility', 'quantity' => 1])));
        $this->reject(fn() => $guard->change($id, null));
        $guard->change($id, array_replace($data, ['quantity' => 3, 'rate_per_hour' => '200.00']));
        $this->assertSame(3, (int) self::$db->table('resources')->where('id', $id)->get()->getRowArray()['quantity']);
        $this->service()->decide($this->admin(), $pending, 'cancel', 'Release stock');
        $guard->change($id, null);
        $this->assertNotNull(self::$db->table('resources')->where('id', $id)->get()->getRowArray()['deleted_at']);
    }

    public function testDatabaseFailureRollsBackHeaderAndItemsAndRetriesAreBounded(): void
    {
        $one = $this->resource();
        $two = $this->resource();
        $trigger = self::$prefix . 'item_failure';
        self::$db->query('CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . self::$db->prefixTable('reservation_items')
            . " FOR EACH ROW BEGIN IF NEW.resource_id = " . $two . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic item failure'; END IF; END");
        try {
            try {
                $this->service()->submit($this->borrower(), $this->booking([$one, $two]));
                $this->fail('Expected injected database failure.');
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException $exception) {
                $this->assertSame(0, self::$db->table('reservations')->countAllResults());
                $this->assertSame(0, self::$db->table('reservation_items')->countAllResults());
            }
        } finally {
            self::$db->query('DROP TRIGGER ' . $trigger);
        }
        $attempts = 0;
        $result = (new \App\Services\BookingTransactions(self::$db))->run(function () use (&$attempts) {
            if (++$attempts < 3) { throw new RuntimeException('Synthetic deadlock retry', 1213); }
            return 'retried';
        });
        $this->assertSame('retried', $result);
        $this->assertSame(3, $attempts);
    }

    private function concurrent(array $jobs, int $resource): array
    {
        $env = array_merge(getenv(), $_ENV);
        $config = config('Database')->default;
        $config['DBPrefix'] = self::$prefix;
        foreach (['hostname', 'username', 'password', 'database', 'DBDriver', 'DBPrefix', 'port'] as $key) {
            $env['database.default.' . $key] = (string) $config[$key];
        }
        $workers = [];
        self::$db->transBegin();
        self::$db->query('SELECT id FROM ' . self::$db->prefixTable('resources') . ' WHERE id = ? FOR UPDATE', [$resource]);
        try {
            foreach ($jobs as $job) {
                $process = proc_open([PHP_BINARY, TESTPATH . '_support/booking-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::$directory . '/workers.log', 'a']], $pipes, ROOTPATH, $env, ['bypass_shell' => true]);
                fwrite($pipes[0], json_encode($job) . "\n");
                $this->assertSame('READY', trim(fgets($pipes[1])));
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
            usleep(150000);
            self::$db->transCommit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode(trim(fgets($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
                fclose($pipes[0]); fclose($pipes[1]);
                $this->assertSame(0, proc_close($process));
            }
            $workers = [];
            return $results;
        } finally {
            self::$db->transRollback();
            foreach ($workers as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
        }
    }

    public function testConcurrentApprovalsCannotDoubleAllocateFacilitiesOrEquipment(): void
    {
        self::$db->table('users')->insert(['student_id' => 'OFFICE-SECOND', 'full_name' => 'Second synthetic admin', 'email' => 'second@example.test', 'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'role' => 'admin', 'status' => 'active']);
        $secondAdmin = (int) self::$db->insertID();
        foreach ([['facility', 1, 1], ['equipment', 3, 2]] as [$type, $stock, $quantity]) {
            $resource = $this->resource(['resource_type' => $type, 'quantity' => (string) $stock, 'capacity' => null]);
            $one = $this->service()->submit($this->borrower(), $this->booking([$resource], quantities: [$resource => $quantity]));
            $two = $this->service()->submit($this->borrower(), $this->booking([$resource], quantities: [$resource => $quantity]));
            $results = $this->concurrent([['operation' => 'approve', 'actor' => $this->admin(), 'id' => $one], ['operation' => 'approve', 'actor' => $secondAdmin, 'id' => $two]], $resource);
            $counts = array_count_values(array_column($results, 'result'));
            $this->assertSame(1, $counts['success'] ?? 0);
            $this->assertSame(1, $counts['rejected'] ?? 0);
            $this->assertSame(1, self::$db->table('reservations')->whereIn('id', [$one, $two])->where('status', 'approved')->countAllResults());
        }
    }

    public function testConcurrentDuplicateSubmissionsStoreOneReservation(): void
    {
        $resource = $this->resource();
        $job = ['operation' => 'submit', 'actor' => $this->borrower(), 'input' => $this->booking([$resource])];
        $results = $this->concurrent([$job, $job], $resource);
        $this->assertSame(['success', 'success'], array_column($results, 'result'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, self::$db->table('reservations')->countAllResults());
        $this->assertSame(1, self::$db->table('reservation_items')->countAllResults());
    }

    public function testHttpPreviewSubmitHistoryOwnershipRolesCsrfAndDecisions(): void
    {
        $resource = $this->resource();
        $input = $this->booking([$resource], '09:00', '09:20');
        $guest = $this->request('/borrower/reservations');
        $this->assertSame(302, $guest['status']);
        $this->login('borrower');
        $form = $this->request('/borrower/reservations/new');
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('value="09:10"', $form['body']);
        $this->assertStringNotContainsString('value="09:05"', $form['body']);
        $this->assertStringNotContainsString('debugbar_loader', $form['body']);
        $this->assertSame(403, $this->request('/borrower/reservations', $input)['status']);
        $preview = $this->postForm('/borrower/reservations/new', '/borrower/reservations/preview', $input);
        $this->assertSame(200, $preview['status']);
        $this->assertStringContainsString('Estimated total', $preview['body']);
        $this->assertSame(0, self::$db->table('reservations')->countAllResults());
        $submit = $this->postForm('/borrower/reservations/new', '/borrower/reservations', $input);
        $this->assertContains($submit['status'], [302, 303]);
        $id = (int) self::$db->table('reservations')->get()->getRowArray()['id'];
        $detail = $this->request('/borrower/reservations/' . $id);
        $this->assertSame(200, $detail['status']);
        $this->assertStringContainsString('20 minutes', $detail['body']);
        $this->assertStringContainsString('no-store', $detail['headers']['cache-control']);
        $history = $this->request('/borrower/reservations');
        $this->assertStringContainsString('#' . $id, $history['body']);
        $this->assertStringContainsString('data-row-link="' . self::$url . '/borrower/reservations/' . $id . '"', $history['body']);
        $this->assertStringContainsString('tabindex="0" aria-label="Open reservation #' . $id . '"', $history['body']);
        $this->assertSame(403, $this->request('/admin/reservations')['status']);
        $this->assertSame(403, $this->request('/admin/reservations/' . $id . '/decision', ['action' => 'approve', 'csrf_test_name' => $this->token('/borrower/reservations/' . $id)])['status']);
        self::$db->table('users')->insert(['student_id' => 'OTHER-STUDENT', 'full_name' => 'Other synthetic student', 'email' => 'other-student@example.test', 'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'role' => 'borrower', 'status' => 'active']);
        $other = (int) self::$db->insertID();
        self::$db->table('reservations')->where('id', $id)->update(['user_id' => $other]);
        $this->assertSame(404, $this->request('/borrower/reservations/' . $id)['status']);
        $this->assertSame(404, $this->request('/borrower/reservations/' . $id . '/cancel', ['csrf_test_name' => $this->token('/borrower/reservations')])['status']);
        self::$db->table('reservations')->where('id', $id)->update(['user_id' => $this->borrower()]);
        curl_setopt($this->browser, CURLOPT_COOKIELIST, 'ALL');
        $this->login();
        $this->assertSame(403, $this->request('/borrower/reservations')['status']);
        $this->assertSame(403, $this->request('/admin/reservations/' . $id . '/decision', ['action' => 'approve'])['status']);
        $queue = $this->request('/admin/reservations?status=pending&from=' . $input['date'] . '&to=' . $input['date']);
        $this->assertSame(200, $queue['status']);
        $this->assertStringContainsString('data-row-link="' . self::$url . '/admin/reservations/' . $id . '"', $queue['body']);
        $this->assertStringContainsString('name="status"', $queue['body']);
        $decision = $this->postForm('/admin/reservations/' . $id, '/admin/reservations/' . $id . '/decision', ['action' => 'approve', 'total_amount' => '0.00']);
        $this->assertContains($decision['status'], [302, 303]);
        $this->assertSame('pending', self::$db->table('reservations')->where('id', $id)->get()->getRowArray()['status']);
        $decision = $this->postForm('/admin/reservations/' . $id, '/admin/reservations/' . $id . '/decision', ['action' => 'approve']);
        $this->assertContains($decision['status'], [302, 303]);
        $this->assertSame('approved', self::$db->table('reservations')->where('id', $id)->get()->getRowArray()['status']);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (isset(self::$prefix) && preg_match('/\Ap4_[a-f0-9]{12}_\z/', self::$prefix) && self::$db->DBPrefix === self::$prefix) {
            foreach (array_reverse(self::$created) as $table) {
                Database::forge(self::$db)->dropTable($table, true);
            }
        }
    }
}
