<?php

namespace Tests\Support;

use RuntimeException;
use mysqli;
use Throwable;

final class IsolatedMySqlDatabase
{
    public static function verify(array $test, array $development, string $expectedDatabase): void
    {
        $developmentDatabase = (string) env('database.default.database', $development['database'] ?? '');
        $developmentUser = (string) env('database.default.username', $development['username'] ?? '');
        if (ENVIRONMENT !== 'testing' || ($test['DBDriver'] ?? '') !== 'MySQLi'
            || ($test['database'] ?? '') !== $expectedDatabase || $developmentDatabase === ''
            || $developmentDatabase === $expectedDatabase || ($test['username'] ?? '') === ''
            || $test['username'] === $developmentUser || ($test['password'] ?? '') === '') {
            throw new RuntimeException('Integration tests require a dedicated MySQL test schema and restricted test-only credentials.');
        }

        if (!class_exists(mysqli::class)) {
            throw new RuntimeException('The MySQLi extension is required for isolated integration tests.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = null;
        try {
            $connection = new mysqli(
                (string) $test['hostname'],
                (string) $test['username'],
                (string) $test['password'],
                $expectedDatabase,
                (int) $test['port']
            );
            $target = $connection->query('SELECT DATABASE() AS selected_database')->fetch_assoc();
            if (($target['selected_database'] ?? null) !== $expectedDatabase) {
                throw new RuntimeException('The MySQL connection did not select the expected isolated test schema.');
            }

            $tables = $connection->query(
                'SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->fetch_assoc();
            if ((int) ($tables['table_count'] ?? -1) !== 0) {
                throw new RuntimeException('The isolated TechReserve test schema must be empty before integration tests.');
            }

            $grants = $connection->query('SHOW GRANTS');
            while ($grant = $grants->fetch_row()) {
                $statement = (string) ($grant[0] ?? '');
                if (preg_match('/\AGRANT\s+USAGE\s+ON\s+\*\.\*/i', $statement)) {
                    continue;
                }
                if (stripos($statement, 'WITH GRANT OPTION') !== false
                    || !preg_match('/\bON\s+(`?[^ ]+`?\.`?[^ ]+`?)\s+TO\b/i', $statement, $match)) {
                    throw new RuntimeException('The test database user has grants broader than the isolated test schema.');
                }
                if (strtolower(str_replace('`', '', $match[1])) !== strtolower($expectedDatabase . '.*')) {
                    throw new RuntimeException('The test database user has access outside the isolated test schema.');
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Unable to verify the isolated MySQL test connection and grants.');
        } finally {
            if ($connection instanceof mysqli) {
                $connection->close();
            }
            mysqli_report(MYSQLI_REPORT_OFF);
        }
    }
}
