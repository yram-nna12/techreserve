<?php

namespace App\Controllers;

use App\Models\UserModel;
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
        $upcoming = $db->table('reservations r')->select('r.id, r.status, r.start_datetime, r.end_datetime, GROUP_CONCAT(DISTINCT resources.name ORDER BY resources.name SEPARATOR ", ") AS resources', false)
            ->join('reservation_items ri', 'ri.reservation_id = r.id', 'left')
            ->join('resources', 'resources.id = ri.resource_id', 'left')
            ->where('r.user_id', $userId)->whereIn('r.status', ['pending', 'approved'])->where('r.start_datetime >', $now)
            ->groupBy('r.id')->orderBy('r.start_datetime', 'ASC')->limit(4)->get()->getResultArray();

        $recent = $db->table('reservations')->select('id, status, created_at, start_datetime, total_amount, currency')
            ->where('user_id', $userId)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();

        $paidReservations = $db->table('payments')->select('reservation_id')->where('payment_status', 'paid');
        $paymentActionCount = (int) $db->table('reservations r')->where('r.user_id', $userId)->where('r.status', 'approved')
            ->whereNotIn('r.id', $paidReservations)->countAllResults();

        return view('borrower/dashboard', [
            'title' => 'Student dashboard',
            'admin' => false,
            'student' => (new UserModel())->select('full_name, student_id')->find($userId),
            'reservationCounts' => $counts,
            'upcomingReservations' => $upcoming,
            'recentReservations' => $recent,
            'paymentActionCount' => $paymentActionCount,
        ]);
    }
}
