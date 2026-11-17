<?php
namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

final class PaymentHistoryController extends BaseController
{
    public function borrowerIndex(): string { return $this->history(false); }
    public function adminIndex(): string { return $this->history(true); }

    private function history(bool $admin): string
    {
        $db = \Config\Database::connect();
        $query = $db->table('payments p')->select('p.*, r.id reservation_id, r.user_id, u.student_id, u.full_name')
            ->join('reservations r', 'r.id = p.reservation_id')->join('users u', 'u.id = r.user_id');
        if (!$admin) { $query->where('r.user_id', (int) session()->get('user_id')); }
        $filters = ['student' => '', 'reservation' => '', 'method' => '', 'status' => '', 'date' => ''];
        if ($admin) {
            foreach ($filters as $key => $_) { $value = $this->request->getGet($key); $filters[$key] = is_string($value) ? trim($value) : ''; }
            if ($filters['student'] !== '') $query->groupStart()->like('u.student_id', $filters['student'])->orLike('u.full_name', $filters['student'])->groupEnd();
            if (ctype_digit($filters['reservation'])) $query->where('r.id', (int) $filters['reservation']);
            if (in_array($filters['method'], ['card','gcash','bank_transfer'], true)) $query->where('p.payment_method', $filters['method']);
            if (in_array($filters['status'], ['pending','paid','failed','cancelled','expired','refunded'], true)) $query->where('p.payment_status', $filters['status']);
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $filters['date'])) $query->where('DATE(p.created_at)', $filters['date']);
        }
        return view('payments/history', ['title' => $admin ? 'Payment transactions' : 'My payment history', 'admin' => $admin,
            'payments' => $query->orderBy('p.created_at', 'DESC')->orderBy('p.id', 'DESC')->limit(100)->get()->getResultArray(), 'filters' => $filters]);
    }

    public function borrowerReceipt(int $paymentId): string { return $this->receipt($paymentId, false); }
    public function adminReceipt(int $paymentId): string { return $this->receipt($paymentId, true); }

    private function receipt(int $paymentId, bool $admin): string
    {
        $db = \Config\Database::connect();
        $query = $db->table('payments p')->select('p.*, r.id reservation_id, r.user_id, u.student_id, u.full_name')
            ->join('reservations r', 'r.id = p.reservation_id')->join('users u', 'u.id = r.user_id')->where('p.id', $paymentId);
        if (!$admin) $query->where('r.user_id', (int) session()->get('user_id'));
        $payment = $query->get()->getRowArray();
        if (!$payment) throw PageNotFoundException::forPageNotFound('Payment receipt not found.');
        $payload = json_decode((string) ($payment['request_payload'] ?? ''), true);
        $items = is_array($payload['rented_items'] ?? null) ? $payload['rented_items'] : [];
        return view('payments/receipt', ['title' => 'Payment receipt', 'admin' => $admin, 'payment' => $payment, 'items' => $items]);
    }
}
