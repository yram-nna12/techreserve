<?php

use App\Services\ReservationDocumentPolicy;
use PHPUnit\Framework\TestCase;

final class ReservationDocumentPolicyTest extends TestCase
{
    public function testOnlyLatestPendingFormOfPendingReservationCanBeReviewed(): void
    {
        ReservationDocumentPolicy::assertCanReview('pending', ['id' => 9, 'status' => 'pending'], 9);

        foreach ([
            ['approved', ['id' => 9, 'status' => 'pending'], 9],
            ['pending', ['id' => 8, 'status' => 'pending'], 9],
            ['pending', ['id' => 9, 'status' => 'rejected'], 9],
        ] as [$reservationStatus, $document, $latestId]) {
            try {
                ReservationDocumentPolicy::assertCanReview($reservationStatus, $document, $latestId);
                $this->fail('A form outside the reviewable state was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testInitialSubmissionAndRejectedFormReplacementRequireUnpaidPendingReservation(): void
    {
        ReservationDocumentPolicy::assertCanResubmit('pending', null, false);
        ReservationDocumentPolicy::assertCanResubmit('pending', ['status' => 'rejected'], false);

        foreach ([
            ['approved', ['status' => 'rejected'], false],
            ['pending', ['status' => 'pending'], false],
            ['pending', ['status' => 'accepted'], false],
            ['pending', ['status' => 'rejected'], true],
            ['cancelled', null, false],
            ['pending', null, true],
        ] as [$reservationStatus, $latest, $hasPayments]) {
            try {
                ReservationDocumentPolicy::assertCanResubmit($reservationStatus, $latest, $hasPayments);
                $this->fail('An ineligible form resubmission was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }
}
