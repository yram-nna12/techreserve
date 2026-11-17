<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;
use Throwable;

final class PaymentService
{
    private BaseConnection $db;
    private BookingTransactions $transactions;
    private PaymentGatewayClient $gateway;
    private NotificationService $notifications;
    private PaymentStateUpdater $stateUpdater;

    public function __construct(
        ?BaseConnection $db = null,
        ?PaymentGatewayClient $gateway = null,
        ?NotificationService $notifications = null
    )
    {
        $this->db = $db ?? Database::connect();
        $this->transactions = new BookingTransactions($this->db);
        $this->gateway = $gateway ?? new PaymentGatewayClient();
        $this->notifications = $notifications ?? new NotificationService(new DatabaseNotificationStore($this->db));
        $this->stateUpdater = new PaymentStateUpdater($this->db, $this->notifications);
    }

    public function initiate(int $borrowerId, int $reservationId, string $method): array
    {
        if (!in_array($method, ['card', 'gcash', 'bank_transfer'], true)) {
            throw new DomainException('Choose Mock Card, GCash, or Bank Transfer.');
        }

        $attempt = $this->transactions->run(function () use ($borrowerId, $reservationId, $method): array {
            $reservation = $this->db->query(
                'SELECT reservations.*, (SELECT documents.status FROM ' . $this->db->prefixTable('reservation_documents')
                    . ' documents WHERE documents.reservation_id = reservations.id ORDER BY documents.id DESC LIMIT 1) AS form_status'
                    . ' FROM ' . $this->db->prefixTable('reservations') . ' reservations WHERE reservations.id = ? FOR UPDATE',
                [$reservationId]
            )->getRowArray();
            if (!$reservation || (int) $reservation['user_id'] !== $borrowerId) {
                throw new DomainException('Reservation not found.');
            }
            PaymentRules::assertCanInitiate(
                $reservation['status'],
                $reservation['total_amount'],
                $reservation['currency'],
                $reservation['form_status']
            );

            $borrower = $this->db->query(
                'SELECT student_id, full_name, role, status FROM ' . $this->db->prefixTable('users') . ' WHERE id = ?',
                [$borrowerId]
            )->getRowArray();
            if (!$borrower || $borrower['role'] !== 'borrower' || $borrower['status'] !== 'active') {
                throw new DomainException('An active borrower account is required.');
            }

            $payments = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('payments')
                    . ' WHERE reservation_id = ? ORDER BY id DESC FOR UPDATE',
                [$reservationId]
            )->getResultArray();
            foreach ($payments as $payment) {
                if ($payment['payment_status'] === 'paid') {
                    throw new DomainException('This reservation has already been paid.');
                }
            }

            $pending = array_values(array_filter($payments, static fn(array $payment): bool => $payment['payment_status'] === 'pending'));
            if (count($pending) > 1) {
                throw new RuntimeException('Reservation has multiple unresolved payment attempts; manual review is required.');
            }
            if ($pending) {
                $attempt = $pending[0];
                $payload = json_decode((string) ($attempt['request_payload'] ?? ''), true);
                if (!is_array($payload)) {
                    throw new DomainException('This pending attempt cannot be resumed safely. Contact an administrator.');
                }
                $this->assertStoredPayload($attempt, $payload);
                return ['id' => (int) $attempt['id'], 'payload' => $payload, 'record' => $attempt];
            }

            PaymentRules::assertCanInitiate(
                'approved',
                $reservation['total_amount'],
                $reservation['currency'],
                $reservation['form_status']
            );
            $items = $this->db->query(
                'SELECT CASE WHEN resources.catalog_group IS NULL THEN resources.name ELSE resources.catalog_group END AS name,'
                    . ' resources.resource_type AS type, SUM(reservation_items.quantity) AS quantity'
                    . ' FROM ' . $this->db->prefixTable('reservation_items') . ' reservation_items'
                    . ' JOIN ' . $this->db->prefixTable('resources') . ' resources ON resources.id = reservation_items.resource_id'
                    . ' WHERE reservation_items.reservation_id = ?'
                    . ' GROUP BY resources.resource_type, resources.category, resources.rate_per_hour, resources.catalog_group,'
                    . ' CASE WHEN resources.catalog_group IS NULL THEN resources.id ELSE 0 END'
                    . ' ORDER BY MIN(reservation_items.id)',
                [$reservationId]
            )->getResultArray();
            if (count($items) < 1 || count($items) > 20) {
                throw new DomainException('The reservation items cannot be sent to the mock gateway.');
            }

            $payload = [
                'transaction_reference' => 'TRX-' . bin2hex(random_bytes(16)),
                'reservation_reference' => 'RES-' . $reservationId,
                'student_id' => $borrower['student_id'],
                'student_name' => $borrower['full_name'],
                'rented_items' => array_map(static fn(array $item): array => [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'quantity' => (int) $item['quantity'],
                ], $items),
                'amount' => $reservation['total_amount'],
                'currency' => $reservation['currency'],
                'payment_method' => $method,
            ];
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('payments')->insert([
                'reservation_id' => $reservationId,
                'transaction_reference' => $payload['transaction_reference'],
                'amount' => $payload['amount'],
                'currency' => $payload['currency'],
                'payment_status' => 'pending',
                'payment_method' => $method,
                'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'active_reservation_id' => $reservationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int) $this->db->insertID();
            return [
                'id' => $id,
                'payload' => $payload,
                'record' => [
                    'id' => $id,
                    'reservation_id' => $reservationId,
                    'transaction_reference' => $payload['transaction_reference'],
                    'gateway_transaction_id' => null,
                    'amount' => $payload['amount'],
                    'currency' => $payload['currency'],
                    'payment_method' => $method,
                    'payment_status' => 'pending',
                    'paid_at' => null,
                    'expires_at' => null,
                ],
            ];
        });

        $gatewayData = $this->gateway->initiate($attempt['payload']);
        $this->saveGatewayInitiation($attempt['id'], $gatewayData);

        if ($gatewayData['status'] !== 'pending') {
            return $this->verifyPayment($borrowerId, $reservationId, $attempt['id']);
        }

        return $this->payment($attempt['id']);
    }

    public function verify(int $borrowerId, int $reservationId, int $paymentId): array
    {
        return $this->verifyPayment($borrowerId, $reservationId, $paymentId);
    }

    public function verifyByReference(int $borrowerId, string $reference): array
    {
        if (!preg_match('/\ATRX-[A-Fa-f0-9]{32}\z/', $reference)) {
            throw new DomainException('Payment reference was not found.');
        }
        $row = $this->db->table('payments')
            ->select('payments.id, payments.reservation_id')
            ->join('reservations', 'reservations.id = payments.reservation_id')
            ->where('payments.transaction_reference', $reference)
            ->where('reservations.user_id', $borrowerId)->get()->getRowArray();
        if (!$row) {
            throw new DomainException('Payment reference was not found.');
        }

        return $this->verifyPayment($borrowerId, (int) $row['reservation_id'], (int) $row['id']);
    }

    public function applyWebhook(array $event, string $payloadHash): array
    {
        $payment = $this->db->table('payments')
            ->select('id, reservation_id')
            ->where('transaction_reference', $event['transaction_reference'])
            ->get()->getRowArray();
        if (!$payment) {
            throw new \App\Libraries\PaymentWebhookException(
                404,
                'payment_not_found',
                'The payment event could not be matched.'
            );
        }

        return $this->stateUpdater->apply(
            (int) $payment['reservation_id'],
            (int) $payment['id'],
            $event,
            [
                'event_id' => $event['event_id'],
                'payload_hash' => $payloadHash,
                'event_type' => $event['event_type'],
            ]
        );
    }

    public function forReservation(int $reservationId): array
    {
        return $this->db->table('payments')->where('reservation_id', $reservationId)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    private function verifyPayment(int $borrowerId, int $reservationId, int $paymentId): array
    {
        $payment = $this->ownedPayment($borrowerId, $reservationId, $paymentId);
        $expected = [
            'transaction_reference' => $payment['transaction_reference'],
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
            'payment_method' => $payment['payment_method'],
        ];
        $gatewayData = $this->gateway->status($expected);
        return $this->stateUpdater->apply($reservationId, $paymentId, $gatewayData)['payment'];
    }

    private function saveGatewayInitiation(int $paymentId, array $gatewayData): void
    {
        $this->transactions->run(function () use ($paymentId, $gatewayData): void {
            $current = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('payments') . ' WHERE id = ? FOR UPDATE',
                [$paymentId]
            )->getRowArray();
            if (!$current) {
                throw new RuntimeException('The local payment attempt could not be found.');
            }
            if ($current['gateway_transaction_id'] !== null
                && $current['gateway_transaction_id'] !== $gatewayData['gateway_transaction_id']) {
                throw new RuntimeException('The gateway returned a different transaction for this payment reference.');
            }
            $this->db->table('payments')->where('id', $paymentId)->update([
                'gateway_transaction_id' => $gatewayData['gateway_transaction_id'],
                'checkout_url' => $gatewayData['checkout_url'],
                'expires_at' => $gatewayData['expires_at'],
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    private function ownedPayment(int $borrowerId, int $reservationId, int $paymentId): array
    {
        $payment = $this->db->table('payments')->select('payments.*')
            ->join('reservations', 'reservations.id = payments.reservation_id')
            ->where('payments.id', $paymentId)->where('payments.reservation_id', $reservationId)
            ->where('reservations.user_id', $borrowerId)->get()->getRowArray();
        if (!$payment) {
            throw new DomainException('Payment reference was not found.');
        }

        return $payment;
    }

    private function payment(int $paymentId): array
    {
        $payment = $this->db->table('payments')->where('id', $paymentId)->get()->getRowArray();
        if (!$payment) {
            throw new RuntimeException('The local payment attempt could not be found.');
        }
        return $payment;
    }

    private function assertStoredPayload(array $payment, array $payload): void
    {
        foreach ([
            'transaction_reference' => 'transaction_reference',
            'amount' => 'amount',
            'currency' => 'currency',
            'payment_method' => 'payment_method',
        ] as $field => $column) {
            if (!is_string($payload[$field] ?? null) || $payload[$field] !== $payment[$column]) {
                throw new DomainException('This pending attempt has an inconsistent request snapshot. Contact an administrator.');
            }
        }
        if (!is_string($payload['reservation_reference'] ?? null)
            || $payload['reservation_reference'] !== 'RES-' . (int) $payment['reservation_id']) {
            throw new DomainException('This pending attempt has an inconsistent reservation reference. Contact an administrator.');
        }
    }
}
