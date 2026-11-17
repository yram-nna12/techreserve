<?php

namespace App\Commands;

use App\Services\EmailOutboxWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class SendNotificationEmails extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:send-emails';
    protected $description = 'Deliver a bounded batch of queued TechReserve notification emails.';
    protected $usage = 'notifications:send-emails';

    public function run(array $params)
    {
        $settings = config('Notifications');
        if (!$settings->enabled || !$settings->emailDeliveryEnabled) {
            CLI::write('Notification email delivery is disabled; no outbox records were read or changed.', 'yellow');
            return;
        }

        $report = (new EmailOutboxWorker())->run();
        CLI::write(sprintf(
            'Processed %d; sent %d; retrying %d; permanently failed %d.',
            $report['processed'],
            $report['sent'],
            $report['retrying'],
            $report['failed']
        ));
    }
}
