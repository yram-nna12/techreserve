<?php

namespace App\Services;

/** Restricts fixture-only email suppression to the approved demonstration submissions. */
final class AcademicFixturePolicy
{
    public static function matches(array $borrower, string $purpose, string $key): bool
    {
        $id = (string) ($borrower['student_id'] ?? '');
        if (!in_array($id, ['202310001', '202310002', '202310010', '202310006', '202510005', '202310003', '202310007', '202410007', '202410009', '202610008'], true)
            || strtolower((string) ($borrower['email'] ?? '')) !== 'student-' . $id . '@example.invalid'
            || !str_starts_with($purpose, 'Academic demonstration fixture:')) {
            return false;
        }

        $namespace = hex2bin('8cd8f9132c48471190f1a2407d5ee2bd');
        foreach ([0, 1] as $slot) {
            $bytes = substr(sha1($namespace . $id . '-' . $slot, true), 0, 16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            $expected = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
            if (hash_equals($expected, $key)) {
                return true;
            }
        }
        return false;
    }
}
