<?php

use App\Libraries\NotificationEventFactory;
use PHPUnit\Framework\TestCase;

final class NotificationEventFactoryTest extends TestCase
{
    public function testReservationEventsAreDirectedToTheCorrectRoles(): void
    {
        $factory = new NotificationEventFactory();

        $submitted = $factory->reservationSubmitted(41, ['schedule' => '2026-12-01 09:00 to 2026-12-01 10:00']);
        $this->assertSame('admin', $submitted['recipient_role']);
        $this->assertSame('reservation:41:submitted', $submitted['event_key']);
        $this->assertSame('admin/reservations/41', $submitted['path']);

        foreach (['approved', 'rejected', 'cancelled', 'completed'] as $status) {
            $event = $factory->reservationStatusChanged(9, 41, $status, []);
            $this->assertSame('borrower', $event['recipient_role']);
            $this->assertSame('borrower/reservations/41', $event['path']);
            $this->assertSame('reservation:41:' . $status, $event['event_key']);
        }
    }

    public function testPaymentEventsExcludePendingAndUseStablePerAttemptKeys(): void
    {
        $factory = new NotificationEventFactory();

        $this->assertNull($factory->paymentStatusChanged(9, 41, 12, 'pending', []));
        foreach (['paid', 'failed', 'expired'] as $status) {
            $event = $factory->paymentStatusChanged(9, 41, 12, $status, ['amount' => '12.00', 'currency' => 'PHP']);
            $this->assertSame('borrower', $event['recipient_role']);
            $this->assertSame('payment:12:' . $status, $event['event_key']);
            $this->assertStringContainsString('PHP 12.00', $event['details'][2]['value']);
        }
    }
}
