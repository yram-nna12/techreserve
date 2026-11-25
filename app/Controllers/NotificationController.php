<?php

namespace App\Controllers;

use App\Services\NotificationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use DomainException;

final class NotificationController extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function index()
    {
        $userId = (int) session()->get('user_id');
        $notifications = new NotificationService();
        $role = $notifications->roleForUser($userId);
        if ($role === null) {
            throw PageNotFoundException::forPageNotFound('Notifications not found.');
        }

        if ($role === 'admin') {
            $filter = $this->request->getGet('filter');
            $filter = is_string($filter) && in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';
            $query = \Config\Database::connect()->table('notifications n')->select('n.*, r.id AS existing_reservation_id')
                ->join('reservations r', 'r.id = n.reservation_id', 'left')->where('n.user_id', $userId);
            if ($filter !== 'all') $query->where('n.is_read', $filter === 'read' ? 1 : 0);
            $total = (clone $query)->countAllResults();
            $requestedPage = $this->request->getGet('page');
            $page = is_string($requestedPage) && ctype_digit($requestedPage) ? max(1, (int) $requestedPage) : 1;
            $page = min($page, max(1, (int) ceil($total / 10)));
            $pager = service('pager');
            return view('notifications/admin_index', [
                'title' => 'Notifications', 'admin' => true, 'filter' => $filter,
                'notifications' => $query->orderBy('n.created_at', 'DESC')->orderBy('n.id', 'DESC')->limit(10, ($page - 1) * 10)->get()->getResultArray(),
                'unreadCount' => $notifications->unreadCount($userId), 'total' => $total,
                'pager' => $pager->only(['filter'])->makeLinks($page, 10, $total, 'admin_records'),
                'notificationWritesEnabled' => config('Notifications')->enabled,
            ]);
        }

        return view('notifications/index', [
            'title' => 'Notifications',
            'admin' => $role === 'admin',
            'notifications' => $notifications->listForUser($userId),
            'unreadCount' => $notifications->unreadCount($userId),
            'notificationWritesEnabled' => config('Notifications')->enabled,
        ]);
    }

    public function markRead(int $notificationId)
    {
        try {
            $updated = (new NotificationService())->markRead((int) session()->get('user_id'), $notificationId);
        } catch (DomainException $exception) {
            return redirect()->to(site_url('notifications'))->with('error', $exception->getMessage());
        }

        if (!$updated) {
            throw PageNotFoundException::forPageNotFound('Notification not found.');
        }

        return redirect()->to(site_url('notifications'))->with('success', 'Notification marked as read.');
    }

    public function markAllRead()
    {
        try {
            $count = (new NotificationService())->markAllRead((int) session()->get('user_id'));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('notifications'))->with('error', $exception->getMessage());
        }

        return redirect()->to(site_url('notifications'))->with('success', $count . ' notification(s) marked as read.');
    }
}
