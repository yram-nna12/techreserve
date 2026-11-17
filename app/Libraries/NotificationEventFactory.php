<?php

namespace App\Libraries;

use InvalidArgumentException;

final class NotificationEventFactory
{
    public function reservationSubmitted(int $reservationId, array $context): array
    {
        $this->assertReservationId($reservationId);
        $reference = 'RES-' . $reservationId;

        return [
            'event_key' => 'reservation:' . $reservationId . ':submitted',
            'notification_type' => 'reservation_submitted',
            'recipient_role' => 'admin',
            'recipient_user_id' => null,
            'reservation_id' => $reservationId,
            'title' => 'New reservation requires review',
            'message' => 'Reservation ' . $reference . ' was submitted and is waiting for review.',
            'subject' => 'TechReserve: reservation ' . $reference . ' requires review',
            'path' => 'admin/reservations/' . $reservationId,
            'details' => $this->details($reference, 'Pending review', $context),
        ];
    }

    public function reservationStatusChanged(int $userId, int $reservationId, string $status, array $context): array
    {
        $this->assertReservationId($reservationId);
        $this->assertUserId($userId);
        $messages = [
            'approved' => ['reservation_approved', 'Reservation approved', 'was approved'],
            'rejected' => ['reservation_rejected', 'Reservation rejected', 'was rejected'],
            'cancelled' => ['reservation_cancelled', 'Reservation cancelled', 'was cancelled'],
            'completed' => ['reservation_completed', 'Reservation completed', 'was completed'],
        ];
        if (!isset($messages[$status])) {
            throw new InvalidArgumentException('Unsupported reservation notification status.');
        }

        [$type, $title, $description] = $messages[$status];
        $reference = 'RES-' . $reservationId;

        return [
            'event_key' => 'reservation:' . $reservationId . ':' . $status,
            'notification_type' => $type,
            'recipient_role' => 'borrower',
            'recipient_user_id' => $userId,
            'reservation_id' => $reservationId,
            'title' => $title,
            'message' => 'Your reservation ' . $reference . ' ' . $description . '.',
            'subject' => 'TechReserve: ' . $reference . ' ' . $status,
            'path' => 'borrower/reservations/' . $reservationId,
            'details' => $this->details($reference, ucfirst($status), $context),
        ];
    }

    public function paymentStatusChanged(
        int $userId,
        int $reservationId,
        int $paymentId,
        string $status,
        array $context
    ): ?array {
        $this->assertReservationId($reservationId);
        $this->assertUserId($userId);
        if ($paymentId < 1) {
            throw new InvalidArgumentException('A positive payment identifier is required.');
        }
        if ($status === 'pending') {
            return null;
        }

        $messages = [
            'paid' => ['payment_paid', 'Payment confirmed', 'was confirmed as paid'],
            'failed' => ['payment_failed', 'Payment failed', 'was confirmed as failed'],
            'expired' => ['payment_expired', 'Payment expired', 'expired'],
        ];
        if (!isset($messages[$status])) {
            throw new InvalidArgumentException('Unsupported payment notification status.');
        }

        [$type, $title, $description] = $messages[$status];
        $reference = 'RES-' . $reservationId;

        return [
            'event_key' => 'payment:' . $paymentId . ':' . $status,
            'notification_type' => $type,
            'recipient_role' => 'borrower',
            'recipient_user_id' => $userId,
            'reservation_id' => $reservationId,
            'title' => $title,
            'message' => 'Payment for reservation ' . $reference . ' ' . $description . '.',
            'subject' => 'TechReserve: payment for ' . $reference . ' ' . $status,
            'path' => 'borrower/reservations/' . $reservationId,
            'details' => $this->details($reference, 'Payment ' . $status, $context),
        ];
    }

    private function details(string $reference, string $status, array $context): array
    {
        $details = [
            ['label' => 'Reservation reference', 'value' => $reference],
            ['label' => 'Status', 'value' => $status],
        ];

        if (is_string($context['schedule'] ?? null) && $context['schedule'] !== '') {
            $details[] = ['label' => 'Schedule', 'value' => $context['schedule']];
        }
        if (is_string($context['amount'] ?? null) && is_string($context['currency'] ?? null)) {
            $details[] = ['label' => 'Total', 'value' => $context['currency'] . ' ' . $context['amount']];
        }

        return $details;
    }

    private function assertReservationId(int $reservationId): void
    {
        if ($reservationId < 1) {
            throw new InvalidArgumentException('A positive reservation identifier is required.');
        }
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A positive recipient identifier is required.');
        }
    }
}
