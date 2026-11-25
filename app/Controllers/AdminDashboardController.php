<?php
namespace App\Controllers;

use App\Models\UserModel;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

final class AdminDashboardController extends BaseController
{
    public function index(): string
    {
        $db = Database::connect();
        $zone = new DateTimeZone(config('Booking')->timezone);
        $today = new DateTimeImmutable('today', $zone);
        $range = $this->request->getGet('range');
        $range = is_string($range) && in_array($range, ['7','30','90','all'], true) ? $range : '30';
        $start = $range === 'all' ? null : $today->modify('-' . ((int)$range - 1) . ' days');
        $end = $today->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        $bounds = [$start?->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
        $where = static function ($query, string $column) use ($bounds) { if ($bounds[0] !== null) $query->where($column.' >=', $bounds[0]); return $query->where($column.' <', $bounds[1]); };
        // A single successful transaction per reservation is counted; select the earliest paid timestamp and ID.
        $paid = '(SELECT p.* FROM payments p INNER JOIN (SELECT reservation_id, MIN(id) AS paid_id FROM payments WHERE payment_status = "paid" GROUP BY reservation_id) chosen ON chosen.paid_id = p.id)';
        $paidTotal = $db->query('SELECT COALESCE(SUM(amount),0) AS total FROM '.$paid.' AS paid')->getRowArray()['total'];
        $metrics = [
            'reservations' => $db->table('reservations')->countAllResults(),
            'students' => $db->table('users')->where('role','borrower')->countAllResults(),
            'facilities' => $db->table('resources')->where('resource_type','facility')->where('deleted_at',null)->countAllResults(),
            'equipment' => $db->table('resources')->where('resource_type','equipment')->where('deleted_at',null)->countAllResults(),
            'payments' => $db->table('payments')->countAllResults(), 'collected' => $paidTotal,
        ];
        $status = array_fill_keys(['pending','approved','rejected','completed','cancelled'],0);
        foreach ($where($db->table('reservations')->select('status, COUNT(*) AS total')->groupBy('status'),'created_at')->get()->getResultArray() as $row) if (isset($status[$row['status']])) $status[$row['status']] = (int)$row['total'];
        $dates = $where($db->table('reservations')->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, COUNT(*) AS total', false)->groupBy('day')->orderBy('day'),'created_at')->get()->getResultArray();
        $collections = $where($db->table($paid.' AS paid')->select('DATE(DATE_ADD(paid_at, INTERVAL 8 HOUR)) AS day, SUM(amount) AS total', false)->groupBy('day')->orderBy('day'),'paid_at')->get()->getResultArray();
        $methods = $where($db->table($paid.' AS paid')->select('payment_method, COUNT(*) AS total')->groupBy('payment_method'),'paid_at')->get()->getResultArray();
        $popular = $where($db->table('reservation_items ri')->select('resources.name, COUNT(DISTINCT ri.reservation_id) AS total', false)->join('reservations r','r.id = ri.reservation_id')->join('resources','resources.id = ri.resource_id')->groupBy('ri.resource_id, resources.name')->orderBy('total','DESC')->limit(5),'r.created_at')->get()->getResultArray();
        $recent = $db->table('reservations r')->select('r.id, r.status, r.created_at, r.start_datetime, u.full_name, GROUP_CONCAT(DISTINCT resources.name ORDER BY resources.name SEPARATOR ", ") AS resources',false)->join('users u','u.id = r.user_id')->join('reservation_items ri','ri.reservation_id = r.id','left')->join('resources','resources.id = ri.resource_id','left')->groupBy('r.id, r.status, r.created_at, r.start_datetime, u.full_name')->orderBy('r.created_at','DESC')->orderBy('r.id','DESC')->limit(5)->get()->getResultArray();
        return view('admin/dashboard', ['title'=>'Admin dashboard','admin'=>true,'adminUser'=>(new UserModel())->select('full_name')->find((int)session()->get('user_id')),'metrics'=>$metrics,'range'=>$range,'rangeStart'=>$start?->format('Y-m-d'),'rangeEnd'=>$today->format('Y-m-d'),'chartData'=>['status'=>$status,'reservations'=>$dates,'collections'=>$collections,'methods'=>$methods,'resources'=>$popular],'recentReservations'=>$recent]);
    }
}
