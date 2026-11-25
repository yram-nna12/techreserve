<?php

namespace App\Services;

use RuntimeException;

final class EligibilityNonceStore
{
    public static function consume(string $directory, string $nonce): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $nonce)) return false;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Eligibility replay protection unavailable.');
        }
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $nonce);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) return false;
            throw new RuntimeException('Eligibility replay protection unavailable.');
        }
        fclose($handle);
        if (random_int(1, 100) === 1) {
            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - 120) @unlink($file);
            }
        }
        return true;
    }
}
