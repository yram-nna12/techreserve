<?php

namespace App\Controllers;

use App\Models\UserModel;
use Config\Database;

final class AdminDashboardController extends BaseController
{
    public function index(): string
    {
        $db = Database::connect();
        $reservationStatuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
        $counts = array_fill_keys($reservationStatuses, 0);
        foreach ($db->table('reservations')->select('status, COUNT(*) AS total')->groupBy('status')->get()->getResultArray() as $row) {
            if (array_key_exists($row['status'], $counts)) $counts[$row['status']] = (int) $row['total'];
        }
        $resourceCounts = ['facility' => 0, 'equipment' => 0];
        foreach ($db->table('resources')->select('resource_type, COUNT(*) AS total')->groupBy('resource_type')->get()->getResultArray() as $row) {
            if (array_key_exists($row['resource_type'], $resourceCounts)) $resourceCounts[$row['resource_type']] = (int) $row['total'];
        }
        $paymentCounts = $db->table('payments')->select('payment_status, COUNT(*) AS total')->groupBy('payment_status')->get()->getResultArray();
        $payments = 0;
        foreach ($paymentCounts as $row) $payments += (int) $row['total'];
        return view('admin/dashboard', [
            'title' => 'Admin dashboard', 'admin' => true,
            'adminUser' => (new UserModel())->select('full_name, role')->find((int) session()->get('user_id')),
            'reservationCounts' => $counts, 'resourceCounts' => $resourceCounts, 'paymentTotal' => $payments,
            'recentReservations' => $db->table('reservations r')->select('r.id, r.status, r.created_at, r.start_datetime, u.full_name')
                ->join('users u', 'u.id = r.user_id')->orderBy('r.created_at', 'DESC')->orderBy('r.id', 'DESC')->limit(6)->get()->getResultArray(),
        ]);
    }
}
