<?php

namespace App\Services;

use App\Libraries\PaymentWebhookException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use UnexpectedValueException;

final class PaymentStateUpdater implements PaymentStateApplierInterface
{
    private BaseConnection $db;
    private BookingTransactions $transactions;
    private NotificationService $notifications;

    public function __construct(
        ?BaseConnection $db = null,
        ?NotificationService $notifications = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->transactions = new BookingTransactions($this->db);
        $this->notifications = $notifications ?? new NotificationService(new DatabaseNotificationStore($this->db));
    }

    public function apply(
        int $reservationId,
        int $paymentId,
        array $gatewayData,
        ?array $receipt = null
    ): array {
        $result = $this->transactions->run(function () use ($reservationId, $paymentId, $gatewayData, $receipt): array {
            $reservation = $this->db->query(
                'SELECT id, user_id FROM ' . $this->db->prefixTable('reservations') . ' WHERE id = ? FOR UPDATE',
                [$reservationId]
            )->getRowArray();
            $payment = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('payments')
                    . ' WHERE id = ? AND reservation_id = ? FOR UPDATE',
                [$paymentId, $reservationId]
            )->getRowArray();

            if (!$reservation || !$payment) {
                if ($receipt !== null) {
                    throw new PaymentWebhookException(404, 'payment_not_found', 'The payment event could not be matched.');
                }
                throw new RuntimeException('The payment attempt could not be found.');
            }

            if ($receipt !== null) {
                $existing = $this->db->table('payment_webhook_receipts')
                    ->where('event_id', $receipt['event_id'])
                    ->get()->getRowArray();
                if ($existing !== null) {
                    if (PaymentWebhookReceiptPolicy::isDuplicate(
                        $existing,
                        $paymentId,
                        $receipt['payload_hash']
                    )) {
                        return [
                            'payment' => $payment,
                            'duplicate' => true,
                        ];
                    }
                }
            }

            if ($payment['transaction_reference'] !== ($gatewayData['transaction_reference'] ?? null)
                || $payment['amount'] !== ($gatewayData['amount'] ?? null)
                || $payment['currency'] !== ($gatewayData['currency'] ?? null)
                || $payment['payment_method'] !== ($gatewayData['payment_method'] ?? null)
                || ($receipt !== null
                    && ($gatewayData['reservation_reference'] ?? null) !== 'RES-' . $reservationId)
                || ($payment['gateway_transaction_id'] !== null
                    && $payment['gateway_transaction_id'] !== ($gatewayData['gateway_transaction_id'] ?? null))) {
                if ($receipt !== null) {
                    throw new PaymentWebhookException(409, 'payment_event_mismatch', 'The payment event does not match the saved payment.');
                }
                throw new RuntimeException('The gateway response did not match the saved payment.');
            }

            try {
                $nextStatus = PaymentRules::nextStatus($payment['payment_status'], $gatewayData['status']);
            } catch (UnexpectedValueException $exception) {
                if ($receipt !== null) {
                    throw new PaymentWebhookException(409, 'payment_state_conflict', 'The payment event conflicts with the saved payment state.');
                }
                throw $exception;
            }

            $now = gmdate('Y-m-d H:i:s');
            $changed = $nextStatus !== $payment['payment_status'];
            $update = [
                'gateway_transaction_id' => $gatewayData['gateway_transaction_id'],
                'expires_at' => $gatewayData['expires_at'],
                'last_verified_at' => $now,
                'updated_at' => $now,
            ];
            if ($nextStatus !== 'pending') {
                $update['payment_status'] = $nextStatus;
                $update['active_reservation_id'] = null;
                $update['paid_at'] = $nextStatus === 'paid' ? $gatewayData['paid_at'] : null;
            }

            $this->db->table('payments')->where('id', $paymentId)->update($update);

            if ($receipt !== null) {
                $this->db->table('payment_webhook_receipts')->insert([
                    'event_id' => $receipt['event_id'],
                    'payment_id' => $paymentId,
                    'payload_hash' => $receipt['payload_hash'],
                    'event_type' => $receipt['event_type'],
                    'payment_status' => $nextStatus,
                    'processing_result' => $changed ? 'applied' : 'already_applied',
                    'received_at' => $now,
                ]);
            }

            if ($changed && in_array($nextStatus, ['paid', 'failed', 'expired'], true)) {
                $this->notifications->persistPaymentStatusChanged(
                    (int) $reservation['user_id'],
                    $reservationId,
                    $paymentId,
                    $nextStatus,
                    [
                        'amount' => $payment['amount'],
                        'currency' => $payment['currency'],
                    ]
                );
            }

            return [
                'payment' => array_merge($payment, $update),
                'duplicate' => false,
            ];
        });

        return [
            'payment' => $result['payment'],
            'duplicate' => $result['duplicate'],
        ];
    }
}
