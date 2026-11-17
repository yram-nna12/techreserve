<?php

use App\Libraries\EmailDeliveryResult;
use App\Libraries\EmailRetryPolicy;
use App\Libraries\NotificationEmailRenderer;
use App\Libraries\NotificationEmailTransport;
use App\Libraries\NotificationEventFactory;
use App\Services\EmailOutboxStoreInterface;
use App\Services\EmailOutboxWorker;
use Config\Notifications;
use PHPUnit\Framework\TestCase;

final class NotificationEmailAndWorkerTest extends TestCase
{
    public function testEmailTemplatesRenderHtmlAndPlainTextWithoutUnapprovedFields(): void
    {
        $event = (new NotificationEventFactory())->reservationStatusChanged(
            7,
            31,
            'approved',
            ['schedule' => '2026-12-02 09:00 to 2026-12-02 10:00', 'amount' => '25.00', 'currency' => 'PHP']
        );
        $rendered = (new NotificationEmailRenderer())->render($event);

        $this->assertStringContainsString('TechReserve', $rendered['html_body']);
        $this->assertStringContainsString('RES-31', $rendered['html_body']);
        $this->assertStringContainsString('RES-31', $rendered['text_body']);
        $expectedUrl = rtrim(config('App')->baseURL, '/') . '/borrower/reservations/31';
        $this->assertStringContainsString($expectedUrl, $rendered['text_body']);
        $this->assertStringNotContainsString('password', strtolower($rendered['html_body']));
        $this->assertStringNotContainsString('account_number', strtolower($rendered['text_body']));
    }

    public function testRetryPolicyStopsPermanentFailuresAndBacksOffTemporaryFailures(): void
    {
        $policy = new EmailRetryPolicy();
        $now = new DateTimeImmutable('2026-11-17 08:00:00', new DateTimeZone('UTC'));

        $temporary = $policy->failure(2, false, 5, $now);
        $this->assertSame('pending', $temporary['status']);
        $this->assertSame('2026-11-17 08:02:00', $temporary['available_at']);
        $this->assertSame('failed', $policy->failure(1, true, 5, $now)['status']);
        $this->assertSame('failed', $policy->failure(5, false, 5, $now)['status']);
    }

    public function testWorkerUsesFakeTransportAndRecordsSuccessTemporaryAndPermanentResults(): void
    {
        $store = new FakeEmailOutboxStore([
            ['id' => 1, 'attempt_count' => 1],
            ['id' => 2, 'attempt_count' => 1],
            ['id' => 3, 'attempt_count' => 1],
        ]);
        $transport = new FakeNotificationTransport([
            EmailDeliveryResult::success(),
            EmailDeliveryResult::temporaryFailure('smtp_temporary'),
            EmailDeliveryResult::permanentFailure('smtp_permanent'),
        ]);
        $settings = new Notifications();
        $settings->enabled = true;
        $settings->emailDeliveryEnabled = true;
        $worker = new EmailOutboxWorker($store, $transport, new EmailRetryPolicy(), $settings);

        $report = $worker->run(3);

        $this->assertSame(['processed' => 3, 'sent' => 1, 'retrying' => 1, 'failed' => 1, 'disabled' => false], $report);
        $this->assertSame([1], $store->sent);
        $this->assertSame(['pending', 'failed'], array_column($store->failures, 'status'));
        $this->assertSame(3, $transport->calls);
    }

    public function testWorkerDoesNotReadOutboxOrSendWhenDeliveryIsDisabled(): void
    {
        $store = new FakeEmailOutboxStore([['id' => 1, 'attempt_count' => 0]]);
        $transport = new FakeNotificationTransport([]);
        $settings = new Notifications();
        $settings->enabled = true;
        $worker = new EmailOutboxWorker($store, $transport, new EmailRetryPolicy(), $settings);

        $report = $worker->run();

        $this->assertTrue($report['disabled']);
        $this->assertSame(0, $store->claims);
        $this->assertSame(0, $transport->calls);
    }
}

final class FakeEmailOutboxStore implements EmailOutboxStoreInterface
{
    private array $messages;
    public array $sent = [];
    public array $failures = [];
    public int $claims = 0;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function claimNext(int $maxAttempts, int $leaseSeconds, string $now): ?array
    {
        $this->claims++;
        if (!$this->messages) {
            return null;
        }
        return array_shift($this->messages);
    }

    public function markSent(int $id, string $sentAt): void
    {
        $this->sent[] = $id;
    }

    public function markFailure(
        int $id,
        string $status,
        ?string $availableAt,
        string $errorCode,
        string $updatedAt
    ): void {
        $this->failures[] = compact('id', 'status', 'availableAt', 'errorCode');
    }
}

final class FakeNotificationTransport implements NotificationEmailTransport
{
    private array $results;
    public int $calls = 0;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function send(array $message): EmailDeliveryResult
    {
        $this->calls++;
        return array_shift($this->results) ?? EmailDeliveryResult::temporaryFailure('missing_fake_result');
    }
}
