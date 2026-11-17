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
