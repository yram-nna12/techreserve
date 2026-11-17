<?php

// Independent database connection/process for actual concurrent-transaction tests.
foreach (['hostname', 'username', 'password', 'database', 'DBDriver', 'DBPrefix', 'port'] as $field) {
    $key = 'database.default.' . $field;
    $value = getenv($key);
    if ($value !== false) {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
require_once __DIR__ . '/TestReservationDocumentStorage.php';
$config = config('Database')->default;
if (!preg_match('/\Ap4_[a-f0-9]{12}_\z/', $config['DBPrefix'])) {
    exit(1);
}
$db = \Config\Database::connect($config, false);
$job = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
echo "READY\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') {
    exit(1);
}
try {
    $service = new TestReservationService($db);
    if ($job['operation'] === 'submit') {
        $id = $service->submit($job['actor'], $job['input']);
    } else {
        $service->decide($job['actor'], $job['id'], 'approve');
        $id = $job['id'];
    }
    echo json_encode(['result' => 'success', 'id' => $id]) . PHP_EOL;
} catch (DomainException $exception) {
    echo json_encode(['result' => 'rejected', 'message' => $exception->getMessage()]) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['result' => 'error', 'class' => $exception::class]) . PHP_EOL;
}
