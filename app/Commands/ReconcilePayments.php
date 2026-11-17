<?php

namespace App\Commands;

use App\Services\PaymentReconciliationWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

final class ReconcilePayments extends BaseCommand
{
    protected $group = 'Payments';
    protected $name = 'payments:reconcile';
    protected $description = 'Reconcile a bounded batch of stale pending TechReserve payments.';
    protected $usage = 'payments:reconcile [limit]';

    public function run(array $params)
    {
        if (count($params) > 1 || ($params !== [] && filter_var($params[0], FILTER_VALIDATE_INT) === false)) {
            CLI::error('Provide at most one integer batch limit between 1 and 100.');
            return EXIT_ERROR;
        }

        $limit = $params === [] ? null : (int) $params[0];
        try {
            $report = (new PaymentReconciliationWorker())->run($limit);
        } catch (InvalidArgumentException $exception) {
            CLI::error($exception->getMessage());
            return EXIT_ERROR;
        }

        if ($report['overlapping']) {
            CLI::write('Another payment reconciliation worker is active; no payments were checked.', 'yellow');
            return EXIT_SUCCESS;
        }

        CLI::write(sprintf(
            'Checked %d; paid %d; failed %d; expired %d; still pending %d; errors %d; conflicts %d; deferred %d.',
            $report['checked'],
            $report['paid'],
            $report['failed'],
            $report['expired'],
            $report['pending'],
            $report['errors'],
            $report['conflicts'],
            $report['deferred']
        ));

        return $report['errors'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
