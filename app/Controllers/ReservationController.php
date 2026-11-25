<?php

namespace App\Controllers;

use App\Models\ReservationModel;
use App\Models\ReservationItemModel;
use App\Models\ResourceModel;
use App\Services\BookingRules;
use App\Services\PaymentService;
use App\Services\ResourceCatalog;
use App\Services\ReservationService;
use App\Services\ReservationDocumentService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Exceptions\PageNotFoundException;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

class ReservationController extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function create()
    {
        return $this->form();
    }

    public function preview()
    {
        $input = $this->input();
        try {
            $quote = (new ReservationService())->preview((int) session()->get('user_id'), $input);
            return $this->form($input, $quote);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode($exception instanceof DomainException ? 422 : 503)
                ->setBody($this->form($input, null, $this->message($exception)));
        }
    }

    public function store()
    {
        $input = $this->input();
        try {
            $upload = $this->request->getFile('request_form');
            $id = (new ReservationService())->submit(
                (int) session()->get('user_id'),
                $input,
                $upload instanceof UploadedFile ? $upload : null
            );
            return redirect()->to(site_url('borrower/reservations/' . $id))->with('success', 'Reservation submitted for review. Availability is secured only after approval.');
        } catch (Throwable $exception) {
            return $this->response->setStatusCode($exception instanceof DomainException ? 422 : 503)
                ->setBody($this->form($input, null, $this->message($exception)));
        }
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof DomainException) {
            return $exception->getMessage();
        }
        log_message('error', 'Reservation operation failed and was rolled back.');
        return 'The reservation service is temporarily unavailable. No partial changes were saved. Please retry using the same submission key.';
    }

    private function input(): array
    {
        $input = $this->request->getPost();
        // Unchecked catalog rows submit a quantity but no resource ID.
        if (is_array($input['items'] ?? null)) {
            $input['items'] = array_values(array_filter($input['items'], static fn($item) => !is_array($item) || array_key_exists('resource_id', $item)));
        }
        return $input;
    }

    private function form(array $input = [], ?array $quote = null, ?string $error = null): string
    {
        if (!is_string($input['submission_key'] ?? null)) {
            $input['submission_key'] = BookingRules::key();
        }
        return view('reservations/form', [
            'title' => 'Create reservation', 'admin' => false, 'input' => $input, 'quote' => $quote, 'error' => $error,
            'resources' => ResourceCatalog::borrowerGroups(
                (new ResourceModel())->where('status', 'available')->orderBy('name')->orderBy('id')->findAll()
            ),
            'booking' => config('Booking'),
        ]);
    }

    public function index()
    {
        return $this->listing(false);
    }

    public function adminIndex()
    {
        return $this->adminListing();
    }

    private function adminListing()
    {
        $db = \Config\Database::connect();
        $model = new ReservationModel();
        $model->select('reservations.*, users.full_name, users.student_id')->join('users', 'users.id = reservations.user_id');
        $query = $this->request->getGet('q');
        $q = is_string($query) ? mb_substr(trim($query), 0, 100) : '';
        $rawStatus = $this->request->getGet('status');
        $status = is_string($rawStatus) && in_array($rawStatus, ['pending','approved','rejected','cancelled','completed'], true) ? $rawStatus : '';
        if ($status !== '') $model->where('reservations.status', $status);
        if ($q !== '') {
            $resourceMatches = $db->table('reservation_items ri')->select('ri.reservation_id')->join('resources resource_search', 'resource_search.id = ri.resource_id')->like('resource_search.name', $q);
            $model->groupStart()->like('users.full_name', $q)->orLike('users.student_id', $q)->orWhereIn('reservations.id', $resourceMatches);
            $reference = preg_replace('/^(?:RES-?|#)/i', '', $q);
            if (ctype_digit($reference) && strlen($reference) <= 10) $model->orWhere('reservations.id', (int) $reference);
            $model->groupEnd();
        }
        $filters = ['q'=>$q, 'status'=>$status, 'from'=>'', 'to'=>''];
        foreach (['from','to'] as $field) {
            $value = $this->request->getGet($field);
            if ($value === null || $value === '') continue;
            $date = is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(config('Booking')->timezone)) : false;
            if (!$date || $date->format('Y-m-d') !== $value) return $this->response->setStatusCode(422)->setBody('Use valid Manila schedule dates.');
            $filters[$field] = $value;
            $bound = ($field === 'to' ? $date->modify('+1 day') : $date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $model->where($field === 'from' ? 'reservations.end_datetime >' : 'reservations.start_datetime <', $bound);
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) return $this->response->setStatusCode(422)->setBody('The schedule-through date must not precede the schedule-from date.');
        $reservations = $model->orderBy('reservations.created_at','DESC')->orderBy('reservations.id','DESC')->paginate(10);
        $ids = array_column($reservations, 'id');
        $resources = []; $payments = [];
        if ($ids !== []) {
            foreach ($db->table('reservation_items ri')->select('ri.reservation_id, resources.name')->join('resources','resources.id = ri.resource_id')->whereIn('ri.reservation_id',$ids)->orderBy('resources.name')->get()->getResultArray() as $item) $resources[$item['reservation_id']][] = $item['name'];
            foreach ($db->table('payments')->select('reservation_id, payment_status')->whereIn('reservation_id',$ids)->orderBy('created_at','DESC')->orderBy('id','DESC')->get()->getResultArray() as $payment) {
                $id = $payment['reservation_id'];
                if (!isset($payments[$id]) || $payment['payment_status'] === 'paid') $payments[$id] = $payment['payment_status'];
            }
        }
        return view('reservations/admin_index', ['title'=>'Reservation records','admin'=>true,'reservations'=>$reservations,'pager'=>$model->pager,'filters'=>$filters,'resources'=>$resources,'payments'=>$payments]);
    }

    private function listing(bool $admin)
    {
        $model = new ReservationModel();
        if (!$admin) {
            $model->where('user_id', session()->get('user_id'));
        }
        $status = $this->request->getGet('status');
        $status = is_string($status) && in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true) ? $status : '';
        if ($status !== '') {
            $model->where('status', $status);
        }
        $filters = ['status' => $status, 'from' => '', 'to' => ''];
        foreach (['from', 'to'] as $field) {
            $value = $this->request->getGet($field);
            if ($value === null || $value === '') {
                continue;
            }
            $date = is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(config('Booking')->timezone)) : false;
            if (!$date || $date->format('Y-m-d') !== $value) {
                return $this->response->setStatusCode(422)->setBody('Use valid Manila schedule dates.');
            }
            $filters[$field] = $value;
            $bound = ($field === 'to' ? $date->modify('+1 day') : $date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $model->where($field === 'from' ? 'end_datetime >' : 'start_datetime <', $bound);
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            return $this->response->setStatusCode(422)->setBody('The schedule-through date must not precede the schedule-from date.');
        }
        return view('reservations/index', [
            'title' => $admin ? 'Reservation queue' : 'My reservations', 'admin' => $admin,
            'reservations' => $model->orderBy('start_datetime', 'DESC')->orderBy('id', 'DESC')->paginate(10),
            'pager' => $model->pager, 'filters' => $filters,
        ]);
    }

    public function show(int $id)
    {
        return $this->details($id, false);
    }

    public function adminShow(int $id)
    {
        return $this->details($id, true);
    }

    private function record(int $id, bool $admin): array
    {
        $model = new ReservationModel();
        if (!$admin) {
            $model->where('user_id', session()->get('user_id'));
        }
        $record = $model->find($id);
        if (!$record) {
            throw PageNotFoundException::forPageNotFound('Reservation not found.');
        }
        return $record;
    }

    private function details(int $id, bool $admin): string
    {
        $record = $this->record($id, $admin);
        $db = \Config\Database::connect();
        $items = (new ReservationItemModel())->select(
            'reservation_items.*, resources.name, resources.resource_type, resources.category, resources.catalog_group, resources.inventory_identifier'
        )
            ->join('resources', 'resources.id = reservation_items.resource_id')->where('reservation_id', $id)->findAll();
        $borrower = $admin ? $db->table('users')->select('student_id, full_name, email, program, program_code, specialization, department')
            ->where('id', $record['user_id'])->get()->getRowArray() : null;
        $reviewer = $record['admin_id'] ? $db->table('users')->select('full_name')->where('id', $record['admin_id'])->get()->getRowArray() : null;
        $documentService = new ReservationDocumentService($db);
        $documents = $admin ? $documentService->allForReservation($id) : [];
        $latestDocument = $admin ? ($documents[0] ?? null) : $documentService->latestForReservation($id);
        return view($admin ? 'reservations/admin_show' : 'reservations/show', [
            'title' => 'Reservation #' . $id, 'admin' => $admin, 'reservation' => $record,
            'items' => $items, 'borrower' => $borrower, 'reviewer' => $reviewer,
            'payments' => (new PaymentService($db))->forReservation($id),
            'documents' => $documents, 'latestDocument' => $latestDocument,
        ]);
    }

    public function cancel(int $id)
    {
        $this->record($id, false);
        return $this->decision($id, false, 'borrower_cancel');
    }

    public function decide(int $id)
    {
        $this->record($id, true);
        $action = $this->request->getPost('action');
        $action = is_string($action) && in_array($action, ['approve', 'reject', 'cancel', 'complete'], true) ? $action : '';
        return $this->decision($id, true, $action);
    }

    private function decision(int $id, bool $admin, string $action)
    {
        $target = ($admin ? 'admin' : 'borrower') . '/reservations/' . $id;
        $remarks = $this->request->getPost('remarks');
        if ($remarks !== null && !is_string($remarks)) {
            return redirect()->to(site_url($target))->with('error', 'Enter valid administrator remarks.');
        }
        foreach (['user_id', 'status', 'total_amount', 'rate_per_hour', 'quantity', 'items', 'admin_id'] as $field) {
            if ($this->request->getPost($field) !== null) {
                return redirect()->to(site_url($target))->with('error', 'Reservation fields cannot be changed during a decision.');
            }
        }
        try {
            (new ReservationService())->decide((int) session()->get('user_id'), $id, $action, $remarks ?? '');
            return redirect()->to(site_url($target))->with('success', 'Reservation status updated.');
        } catch (Throwable $exception) {
            return redirect()->to(site_url($target))->with('error', $this->message($exception));
        }
    }
}
