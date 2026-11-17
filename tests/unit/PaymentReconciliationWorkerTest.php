<?php

use App\Libraries\PaymentGatewayException;
use App\Services\PaymentReconciliationStoreInterface;
use App\Services\PaymentReconciliationWorker;
use App\Services\PaymentStateApplierInterface;
use App\Services\PaymentStatusProviderInterface;
use Config\PaymentGateway;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationWorkerTest extends TestCase
{
    public function testProcessesOnlyTheRequestedBatchAndAppliesPendingAndTerminalStates(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1), $this->payment(2)]);
        $gateway = new FakePaymentStatusProvider([
            ['status' => 'paid'],
            ['status' => 'pending'],
        ]);
        $updater = new FakePaymentStateApplier();
        $worker = $this->worker($store, $gateway, $updater);

        $report = $worker->run(2);

        $this->assertSame(2, $store->requestedLimit);
        $this->assertSame(2, $report['checked']);
        $this->assertSame(1, $report['paid']);
        $this->assertSame(1, $report['pending']);
        $this->assertSame('paid', $updater->states[1]);
        $this->assertSame('pending', $updater->states[2]);
        $this->assertTrue($store->released);
    }

    public function testAppliesEachSupportedTerminalGatewayStatus(): void
    {
        $store = new FakePaymentReconciliationStore([
            $this->payment(1),
            $this->payment(2),
            $this->payment(3),
        ]);
        $gateway = new FakePaymentStatusProvider([
            ['status' => 'paid'],
            ['status' => 'failed'],
            ['status' => 'expired'],
        ]);

        $report = $this->worker($store, $gateway, new FakePaymentStateApplier())->run(3);

        $this->assertSame(1, $report['paid']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, $report['expired']);
    }

    public function testOverlappingWorkerDoesNotReadOrPollPayments(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1)]);
        $store->lockAvailable = false;
        $gateway = new FakePaymentStatusProvider([['status' => 'paid']]);

        $report = $this->worker($store, $gateway, new FakePaymentStateApplier())->run();

        $this->assertTrue($report['overlapping']);
        $this->assertSame(0, $report['checked']);
        $this->assertSame(0, $gateway->calls);
        $this->assertSame(0, $store->queries);
        $this->assertFalse($store->released);
    }

    public function testRetriesTemporaryFailureWithinTheConfiguredBound(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1)]);
        $gateway = new FakePaymentStatusProvider([
            new PaymentGatewayException('temporary', true, 'network_error'),
            ['status' => 'pending'],
        ]);
        $delays = [];

        $report = $this->worker(
            $store,
            $gateway,
            new FakePaymentStateApplier(),
            static function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            }
        )->run();

        $this->assertSame(2, $gateway->calls);
        $this->assertSame([250], $delays);
        $this->assertSame(1, $report['pending']);
        $this->assertSame(0, $report['errors']);
    }

    public function testStopsBatchAfterRetryableGatewayFailureAndRecordsCooldown(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1), $this->payment(2)]);
        $gateway = new FakePaymentStatusProvider([
            new PaymentGatewayException('temporary', true, 'http_503'),
            new PaymentGatewayException('temporary', true, 'http_503'),
        ]);

        $report = $this->worker($store, $gateway, new FakePaymentStateApplier())->run();

        $this->assertSame(2, $gateway->calls);
        $this->assertSame(1, $report['errors']);
        $this->assertSame(1, $report['deferred']);
        $this->assertSame([1], $store->attemptedPaymentIds);
    }

    public function testNotFoundDoesNotChangePaymentStateOrRetryTheRequest(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1)]);
        $gateway = new FakePaymentStatusProvider([
            new PaymentGatewayException('not found', false, 'http_404'),
        ]);
        $updater = new FakePaymentStateApplier();

        $report = $this->worker($store, $gateway, $updater)->run();

        $this->assertSame(1, $gateway->calls);
        $this->assertSame(1, $report['errors']);
        $this->assertSame('pending', $updater->states[1] ?? 'pending');
        $this->assertSame([1], $store->attemptedPaymentIds);
    }

    public function testReconciliationCannotOverwriteAConflictingWebhookTerminalState(): void
    {
        $store = new FakePaymentReconciliationStore([$this->payment(1)]);
        $gateway = new FakePaymentStatusProvider([['status' => 'failed']]);
        $updater = new FakePaymentStateApplier([1 => 'paid']);

        $report = $this->worker($store, $gateway, $updater)->run();

        $this->assertSame(1, $report['conflicts']);
        $this->assertSame('paid', $updater->states[1]);
    }

    public function testRejectsBatchLimitsOutsideTheSupportedRange(): void
    {
        $worker = $this->worker(
            new FakePaymentReconciliationStore([]),
            new FakePaymentStatusProvider([]),
            new FakePaymentStateApplier()
        );

        $this->expectException(InvalidArgumentException::class);
        $worker->run(101);
    }

    private function worker(
        FakePaymentReconciliationStore $store,
        FakePaymentStatusProvider $gateway,
        FakePaymentStateApplier $updater,
        ?callable $sleep = null
    ): PaymentReconciliationWorker {
        $settings = new PaymentGateway();
        $settings->reconciliationStaleMinutes = 10;
        $settings->reconciliationBatchLimit = 25;
        $settings->reconciliationMaxAttempts = 2;
        $settings->reconciliationRetryDelayMilliseconds = 250;

        return new PaymentReconciliationWorker($store, $gateway, $updater, $settings, $sleep);
    }

    private function payment(int $id): array
    {
        return [
            'id' => $id,
            'reservation_id' => 100 + $id,
            'transaction_reference' => 'TRX-' . str_pad((string) $id, 32, '0', STR_PAD_LEFT),
            'amount' => '100.00',
            'currency' => 'PHP',
            'payment_method' => 'gcash',
            'last_verified_at' => null,
            'updated_at' => '2020-01-01 00:00:00',
            'created_at' => '2020-01-01 00:00:00',
        ];
    }
}

final class FakePaymentReconciliationStore implements PaymentReconciliationStoreInterface
{
    public bool $lockAvailable = true;
    public bool $released = false;
    public int $queries = 0;
    public int $requestedLimit = 0;
    public array $attemptedPaymentIds = [];

    public function __construct(private array $payments)
    {
    }

    public function acquireLock(): bool
    {
        return $this->lockAvailable;
    }

    public function releaseLock(): void
    {
        $this->released = true;
    }

    public function findStalePending(string $staleBefore, int $limit): array
    {
        $this->queries++;
        $this->requestedLimit = $limit;
        return array_slice($this->payments, 0, $limit);
    }

    public function recordAttempt(int $paymentId, string $attemptedAt): void
    {
        $this->attemptedPaymentIds[] = $paymentId;
    }
}

final class FakePaymentStatusProvider implements PaymentStatusProviderInterface
{
    public int $calls = 0;

    public function __construct(private array $responses)
    {
    }

    public function status(array $expected): array
    {
        $this->calls++;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

final class FakePaymentStateApplier implements PaymentStateApplierInterface
{
    public array $states;

    public function __construct(array $states = [])
    {
        $this->states = $states;
    }

    public function apply(
        int $reservationId,
        int $paymentId,
        array $gatewayData,
        ?array $receipt = null
    ): array {
        $current = $this->states[$paymentId] ?? 'pending';
        $this->states[$paymentId] = \App\Services\PaymentRules::nextStatus($current, $gatewayData['status']);

        return ['payment' => ['id' => $paymentId, 'reservation_id' => $reservationId, 'payment_status' => $this->states[$paymentId]]];
    }
}
