<?php

namespace App\Services;

use App\Libraries\PaymentGatewayException;
use Config\PaymentGateway;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

final class PaymentReconciliationWorker
{
    private PaymentReconciliationStoreInterface $store;
    private PaymentStatusProviderInterface $gateway;
    private PaymentStateApplierInterface $stateUpdater;
    private PaymentGateway $settings;
    private \Closure $sleep;

    public function __construct(
        ?PaymentReconciliationStoreInterface $store = null,
        ?PaymentStatusProviderInterface $gateway = null,
        ?PaymentStateApplierInterface $stateUpdater = null,
        ?PaymentGateway $settings = null,
        ?callable $sleep = null
    ) {
        $this->store = $store ?? new DatabasePaymentReconciliationStore();
        $this->gateway = $gateway ?? new PaymentGatewayClient();
        $this->stateUpdater = $stateUpdater ?? new PaymentStateUpdater();
        $this->settings = $settings ?? config('PaymentGateway');
        $this->sleep = $sleep === null
            ? static fn(int $milliseconds) => usleep($milliseconds * 1000)
            : \Closure::fromCallable($sleep);
    }

    public function run(?int $limit = null): array
    {
        $limit ??= $this->settings->reconciliationBatchLimit;
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Payment reconciliation limit must be between 1 and 100.');
        }

        $report = [
            'checked' => 0,
            'paid' => 0,
            'failed' => 0,
            'expired' => 0,
            'pending' => 0,
            'errors' => 0,
            'conflicts' => 0,
            'deferred' => 0,
            'overlapping' => false,
        ];
        if (!$this->store->acquireLock()) {
            $report['overlapping'] = true;
            return $report;
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $staleBefore = $now
                ->modify('-' . $this->settings->reconciliationStaleMinutes . ' minutes')
                ->format('Y-m-d H:i:s');
            $cutoff = new DateTimeImmutable($staleBefore, new DateTimeZone('UTC'));
            $payments = $this->store->findStalePending($staleBefore, $limit);

            foreach ($payments as $index => $payment) {
                if (!PaymentReconciliationPolicy::isStale($payment, $cutoff)) {
                    $report['deferred']++;
                    continue;
                }
                $report['checked']++;
                $expected = [
                    'transaction_reference' => $payment['transaction_reference'],
                    'amount' => $payment['amount'],
                    'currency' => $payment['currency'],
                    'payment_method' => $payment['payment_method'],
                ];
                $gatewayData = $this->statusWithRetries($expected);
                if ($gatewayData instanceof PaymentGatewayException) {
                    $this->store->recordAttempt((int) $payment['id'], gmdate('Y-m-d H:i:s'));
                    $report['errors']++;
                    log_message('warning', 'Payment reconciliation status check failed ({failureCode}).', [
                        'failureCode' => $gatewayData->failureCode(),
                    ]);
                    if ($this->shouldStopBatch($gatewayData)) {
                        $report['deferred'] += count($payments) - $index - 1;
                        break;
                    }
                    continue;
                }

                try {
                    $result = $this->stateUpdater->apply(
                        (int) $payment['reservation_id'],
                        (int) $payment['id'],
                        $gatewayData
                    );
                } catch (UnexpectedValueException) {
                    $report['conflicts']++;
                    log_message('notice', 'Payment reconciliation encountered a terminal-state conflict.');
                    continue;
                }

                $status = $result['payment']['payment_status'] ?? null;
                if (in_array($status, ['paid', 'failed', 'expired', 'pending'], true)) {
                    $report[$status]++;
                } else {
                    throw new UnexpectedValueException('The payment state updater returned an invalid status.');
                }
            }
        } finally {
            $this->store->releaseLock();
        }

        return $report;
    }

    private function statusWithRetries(array $expected): array|PaymentGatewayException
    {
        for ($attempt = 1; $attempt <= $this->settings->reconciliationMaxAttempts; $attempt++) {
            try {
                return $this->gateway->status($expected);
            } catch (PaymentGatewayException $exception) {
                if (!$exception->isRetryable() || $attempt === $this->settings->reconciliationMaxAttempts) {
                    return $exception;
                }
                ($this->sleep)($this->settings->reconciliationRetryDelayMilliseconds);
            }
        }

        throw new UnexpectedValueException('Payment reconciliation ended without a gateway result.');
    }

    private function shouldStopBatch(PaymentGatewayException $exception): bool
    {
        return $exception->isRetryable() || $exception->failureCode() !== 'http_404';
    }
}
