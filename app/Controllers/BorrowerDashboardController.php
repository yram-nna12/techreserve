<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\NotificationService;
use Config\Database;

final class BorrowerDashboardController extends BaseController
{
    public function index(): string
    {
        $userId = (int) session()->get('user_id');
        $db = Database::connect();
        $statuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
        $counts = array_fill_keys($statuses, 0);

        foreach ($db->table('reservations')->select('status, COUNT(*) AS total')->where('user_id', $userId)->groupBy('status')->get()->getResultArray() as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $upcomingCount = $db->table('reservations')->where('user_id', $userId)->whereIn('status', ['pending', 'approved'])->where('start_datetime >', $now)->countAllResults();
        $upcoming = $db->table('reservations r')->select('r.id, r.status, r.start_datetime, r.end_datetime, GROUP_CONCAT(DISTINCT resources.name ORDER BY resources.name SEPARATOR ", ") AS resources, GROUP_CONCAT(DISTINCT resources.location ORDER BY resources.location SEPARATOR ", ") AS locations', false)
            ->join('reservation_items ri', 'ri.reservation_id = r.id', 'left')
            ->join('resources', 'resources.id = ri.resource_id', 'left')
            ->where('r.user_id', $userId)->whereIn('r.status', ['pending', 'approved'])->where('r.start_datetime >', $now)
            ->groupBy('r.id')->orderBy('r.start_datetime', 'ASC')->limit(4)->get()->getResultArray();

        $recent = $db->table('reservations')->select('id, status, created_at, start_datetime, total_amount, currency')
            ->where('user_id', $userId)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();

        $candidates = $db->query('SELECT r.id, r.status, r.start_datetime, r.total_amount, r.currency, '
            . '(SELECT d.status FROM '.$db->prefixTable('reservation_documents').' d WHERE d.reservation_id=r.id ORDER BY d.id DESC LIMIT 1) AS document_status, '
            . '(SELECT COUNT(*) FROM '.$db->prefixTable('payments').' p WHERE p.reservation_id=r.id AND p.payment_status="paid") AS paid_count, '
            . '(SELECT COUNT(*) FROM '.$db->prefixTable('payments').' p WHERE p.reservation_id=r.id AND p.payment_status="pending") AS pending_count, '
            . '(SELECT p.payment_status FROM '.$db->prefixTable('payments').' p WHERE p.reservation_id=r.id ORDER BY p.id DESC LIMIT 1) AS latest_payment '
            . 'FROM '.$db->prefixTable('reservations').' r WHERE r.user_id=? AND r.status IN ("pending","approved") ORDER BY r.created_at DESC, r.id DESC', [$userId])->getResultArray();
        $actions = []; $paymentActionCount = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['status'] === 'pending' && ($candidate['document_status'] === null || $candidate['document_status'] === 'rejected')) {
                $actions[] = ['id'=>$candidate['id'], 'message'=>$candidate['document_status'] === 'rejected' ? 'Replace your rejected request form.' : 'Upload your signed request form.'];
            }
            if ($candidate['status'] !== 'approved' || (int)$candidate['paid_count'] > 0) continue;
            if (\App\Services\PaymentRules::paymentPeriodEnded($candidate['start_datetime'])) continue;
            if ((int)$candidate['pending_count'] > 0) {
                $paymentActionCount++; $actions[] = ['id'=>$candidate['id'], 'message'=>'Review your pending payment.']; continue;
            }
            if (\App\Services\PaymentRules::canInitiate($candidate['status'], $candidate['total_amount'], $candidate['currency'], $candidate['document_status'], $candidate['start_datetime'])) {
                $paymentActionCount++; $actions[] = ['id'=>$candidate['id'], 'message'=>$candidate['latest_payment'] === 'failed' ? 'Review your failed payment and retry if needed.' : 'Payment is available for this reservation.'];
            }
        }
        $activity = array_slice((new NotificationService())->listForUser($userId), 0, 5);

        return view('borrower/dashboard', [
            'title' => 'Student dashboard',
            'admin' => false,
            'student' => (new UserModel())->select('full_name, student_id')->find($userId),
            'reservationCounts' => $counts,
            'upcomingReservations' => $upcoming,
            'upcomingCount' => $upcomingCount,
            'recentReservations' => $recent,
            'paymentActionCount' => $paymentActionCount,
            'actions' => array_slice($actions, 0, 5),
            'activity' => $activity,
        ]);
    }
}
