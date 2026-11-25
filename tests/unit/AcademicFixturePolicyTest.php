<?php

use App\Services\AcademicFixturePolicy;
use PHPUnit\Framework\TestCase;

final class AcademicFixturePolicyTest extends TestCase
{
    public function testOnlyTheApprovedFixtureSubmissionMatches(): void
    {
        $borrower = ['student_id' => '202310001', 'email' => 'student-202310001@example.invalid'];
        $purpose = 'Academic demonstration fixture: coursework';
        $key = '3a9b8f36-a271-46d9-9a73-05197ed96bbc';
        self::assertTrue(AcademicFixturePolicy::matches($borrower, $purpose, $key));
        self::assertFalse(AcademicFixturePolicy::matches($borrower, $purpose, '00000000-0000-4000-8000-000000000000'));
        self::assertFalse(AcademicFixturePolicy::matches($borrower, 'Ordinary reservation', $key));
        $borrower['email'] = 'someone@example.invalid';
        self::assertFalse(AcademicFixturePolicy::matches($borrower, $purpose, $key));
    }
}
