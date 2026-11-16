<?php

// PHP normalizes dotted environment names in superglobals on Windows.
// Preserve the exact inherited test keys before CodeIgniter loads .env.
foreach ([
    'CI_ENVIRONMENT', 'app.baseURL', 'app.indexPage', 'studentRecords.baseUrl', 'studentRecords.apiKey',
    'session.savePath', 'cache.file.storePath',
    'database.default.hostname', 'database.default.username', 'database.default.password',
    'database.default.database', 'database.default.DBDriver', 'database.default.DBPrefix',
    'database.default.port', 'database.default.DBDebug',
] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
$prefix = (string) getenv('AUTH_TEST_DB_PREFIX');
if (!preg_match('/\A(?:phase2_test_|p3_)[a-f0-9]{12}_\z/', $prefix)
    || ($_ENV['database.default.DBPrefix'] ?? '') !== $prefix) {
    http_response_code(500);
    exit('Isolated test database configuration missing; request refused.');
}
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/rewrite.php';
