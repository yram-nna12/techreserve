<?php

namespace App\Services;

use DomainException;

final class ReservationDocumentPolicy
{
    public static function assertCanReview(string $reservationStatus, array $document, int $latestDocumentId): void
    {
        if ($reservationStatus !== 'pending' || $document['status'] !== 'pending'
            || (int) $document['id'] !== $latestDocumentId) {
            throw new DomainException('Only the latest pending form for a pending reservation can be reviewed.');
        }
    }

    public static function assertCanResubmit(string $reservationStatus, ?array $latestDocument, bool $hasPayments): void
    {
        if ($reservationStatus !== 'pending' || $hasPayments
            || ($latestDocument !== null && $latestDocument['status'] !== 'rejected')) {
            throw new DomainException('A form can be submitted only for an unpaid pending reservation with no prior form or a rejected latest form.');
        }
    }
}
