<?php

namespace App\Libraries;

use DateTimeImmutable;
use InvalidArgumentException;

final class EmailRetryPolicy
{
    public function failure(
        int $attemptCount,
        bool $permanent,
        int $maxAttempts,
        DateTimeImmutable $now,
        int $baseDelaySeconds = 60,
        int $maxDelaySeconds = 3600
    ): array {
        if ($attemptCount < 1 || $maxAttempts < 1 || $baseDelaySeconds < 1 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException('Invalid email retry policy values.');
        }

        if ($permanent || $attemptCount >= $maxAttempts) {
            return ['status' => 'failed', 'available_at' => null];
        }

        $delay = min($maxDelaySeconds, $baseDelaySeconds * (2 ** min($attemptCount - 1, 20)));

        return [
            'status' => 'pending',
            'available_at' => $now->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s'),
        ];
    }
}
