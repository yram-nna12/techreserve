<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;

class ReservationService
{
    private BookingTransactions $transactions;
    private BaseConnection $db;
    private NotificationService $notifications;
    private ReservationDocumentStorage $documentStorage;

    public function __construct(
        ?BaseConnection $db = null,
        ?NotificationService $notifications = null,
        ?ReservationDocumentStorage $documentStorage = null
    )
    {
        $this->transactions = new BookingTransactions($db);
        $this->db = $this->transactions->db;
        $this->notifications = $notifications ?? new NotificationService(new DatabaseNotificationStore($this->db));
        $this->documentStorage = $documentStorage ?? new ReservationDocumentStorage();
    }

    private function input(array $input): array
    {
        foreach (['user_id', 'status', 'admin_id', 'total_amount', 'currency', 'rate_per_hour', 'subtotal'] as $field) {
            if (array_key_exists($field, $input)) {
                throw new DomainException('Ownership, status, and prices cannot be supplied by the browser.');
            }
        }
        $purpose = is_string($input['purpose'] ?? null) ? trim($input['purpose']) : '';
        if ($purpose === '' || mb_strlen($purpose) > 2000) {
            throw new DomainException('Enter a reservation purpose of at most 2000 characters.');
        }
        $items = $input['items'] ?? null;
        if (!is_array($items) || count($items) < 1 || count($items) > config('Booking')->maximumResources) {
            throw new DomainException('Select between 1 and ' . config('Booking')->maximumResources . ' resources.');
        }
        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['resource_id', 'quantity']) !== []) {
                throw new DomainException('Invalid resource selection; prices are calculated on the server.');
            }
            foreach (['resource_id', 'quantity'] as $field) {
                $value = $item[$field] ?? null;
                if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]{1,10}\z/', (string) $value)
                    || (int) $value < 1 || (int) $value > 4294967295) {
                    throw new DomainException('Resource identifiers and quantities must be positive integers within the supported range.');
                }
            }
            $id = (int) $item['resource_id'];
            if (isset($quantities[$id])) {
                throw new DomainException('Each resource may be selected only once.');
            }
            $quantities[$id] = (int) $item['quantity'];
        }
        ksort($quantities, SORT_NUMERIC);
        return ['purpose' => $purpose, 'quantities' => $quantities];
    }

    private function user(int $id, string $role): array
    {
        $user = $this->db->query('SELECT id, role, status, email, student_id FROM ' . $this->db->prefixTable('users') . ' WHERE id = ? FOR UPDATE', [$id])->getRowArray();
        if (!$user || $user['role'] !== $role || $user['status'] !== 'active') {
            throw new DomainException('An active ' . $role . ' account is required.');
        }
        return $user;
    }

    private function quote(array $data, array $schedule, array $resources): array
    {
        $items = [];
        $reservationItems = [];
        $total = 0;
        $availability = new AvailabilityService($this->db);
        $selectedFacilities = [];
        $selectedCatalogGroups = [];
        foreach ($data['quantities'] as $id => $quantity) {
            $resource = $resources[$id];
            if ($resource['deleted_at'] !== null || $resource['status'] !== 'available') {
                throw new DomainException('A selected resource is archived, unavailable, or under maintenance.');
            }
            if ($resource['resource_type'] === 'facility') {
                ResourceConflictPolicy::assertNoConflicts(array_merge($selectedFacilities, [$resource]));
                $selectedFacilities[] = $resource;
                $availability->check($resource, $quantity, $schedule['start_datetime'], $schedule['end_datetime']);
                $cents = BookingRules::fee($resource['rate_per_hour'], $schedule['minutes'], $quantity);
                $this->addAmount($cents, $total);
                $reservationItems[] = [
                    'resource_id' => $id,
                    'quantity' => $quantity,
                    'rate_per_hour' => $resource['rate_per_hour'],
                    'subtotal' => BookingRules::money($cents),
                ];
                $items[] = [
                    'resource_id' => $id,
                    'name' => $resource['name'],
                    'quantity' => $quantity,
                    'rate_per_hour' => $resource['rate_per_hour'],
                    'subtotal' => BookingRules::money($cents),
                ];
                continue;
            }

            if ($resource['resource_type'] === 'equipment' && is_string($resource['catalog_group'] ?? null)
                && $resource['catalog_group'] !== '') {
                $groupKey = $this->catalogGroupKey($resource);
                if (isset($selectedCatalogGroups[$groupKey])) {
                    throw new DomainException('Select a matching equipment group only once.');
                }
                $selectedCatalogGroups[$groupKey] = true;
                $members = array_filter($resources, static fn(array $candidate): bool =>
                    $candidate['resource_type'] === 'equipment'
                    && $candidate['catalog_group'] === $resource['catalog_group']
                    && $candidate['category'] === $resource['category']
                    && $candidate['rate_per_hour'] === $resource['rate_per_hour']
                );
                usort($members, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
                $remaining = $quantity;
                $groupCents = 0;
                foreach ($members as $member) {
                    $available = $availability->remaining($member, $schedule['start_datetime'], $schedule['end_datetime']);
                    $allocated = min($remaining, $available);
                    if ($allocated < 1) {
                        continue;
                    }
                    $cents = BookingRules::fee($member['rate_per_hour'], $schedule['minutes'], $allocated);
                    $this->addAmount($cents, $total);
                    $groupCents += $cents;
                    $reservationItems[] = [
                        'resource_id' => (int) $member['id'],
                        'quantity' => $allocated,
                        'rate_per_hour' => $member['rate_per_hour'],
                        'subtotal' => BookingRules::money($cents),
                    ];
                    $remaining -= $allocated;
                }
                if ($remaining !== 0) {
                    throw new DomainException('A selected equipment group has insufficient availability for this schedule.');
                }
                $items[] = [
                    'resource_id' => $id,
                    'name' => $resource['catalog_group'],
                    'quantity' => $quantity,
                    'rate_per_hour' => $resource['rate_per_hour'],
                    'subtotal' => BookingRules::money($groupCents),
                ];
                continue;
            }

            $availability->check($resource, $quantity, $schedule['start_datetime'], $schedule['end_datetime']);
            $cents = BookingRules::fee($resource['rate_per_hour'], $schedule['minutes'], $quantity);
            $this->addAmount($cents, $total);
            $reservationItems[] = [
                'resource_id' => $id,
                'quantity' => $quantity,
                'rate_per_hour' => $resource['rate_per_hour'],
                'subtotal' => BookingRules::money($cents),
            ];
            $items[] = [
                'resource_id' => $id,
                'name' => $resource['name'],
                'quantity' => $quantity,
                'rate_per_hour' => $resource['rate_per_hour'],
                'subtotal' => BookingRules::money($cents),
            ];
        }
        return $schedule + [
            'items' => $items,
            'reservation_items' => $reservationItems,
            'total_amount' => BookingRules::money($total),
            'currency' => config('Booking')->currency,
        ];
    }

    private function addAmount(int $cents, int &$total): void
    {
        if ($cents > BookingRules::MAX_CENTS - $total) {
            throw new DomainException('The reservation total exceeds the supported amount.');
        }
        $total += $cents;
    }

    private function selectionKey(array $resource): string
    {
        if ($resource['resource_type'] === 'equipment' && is_string($resource['catalog_group'] ?? null)
            && $resource['catalog_group'] !== '') {
            return $this->catalogGroupKey($resource);
        }

        return 'resource:' . (int) $resource['id'];
    }

    private function catalogGroupKey(array $resource): string
    {
        return 'equipment:' . implode("\0", [
            $resource['catalog_group'],
            $resource['category'],
            $resource['rate_per_hour'],
        ]);
    }

    public function preview(int $borrowerId, array $input): array
    {
        $data = $this->input($input);
        return $this->transactions->run(function () use ($borrowerId, $input, $data) {
            $resources = $this->transactions->lockResources(array_keys($data['quantities']));
            $this->user($borrowerId, 'borrower');
            return $this->quote($data, (new BookingRules())->schedule($input), $resources);
        });
    }

    public function submit(int $borrowerId, array $input, ?UploadedFile $formFile = null): int
    {
        $data = $this->input($input);
        $key = $input['submission_key'] ?? null;
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', $key)) {
            throw new DomainException('Invalid submission key. Open a new reservation form.');
        }
        if (!$formFile) {
            throw new DomainException('Upload the teacher-signed request form before submitting a reservation.');
        }
        $storedDocument = $this->documentStorage->store($formFile);
        try {
            $result = $this->transactions->run(function () use ($borrowerId, $input, $key, $data, $storedDocument) {
                $resources = $this->transactions->lockResources(array_keys($data['quantities']));
                $borrower = $this->user($borrowerId, 'borrower');
                $existing = $this->db->table('reservations')->where('submission_key', $key)->get()->getRowArray();
                // Parse the payload for comparison even on replay, without enforcing "future" again.
                if ($existing) {
                    $local = BookingRules::display($existing['start_datetime']);
                    $end = BookingRules::display($existing['end_datetime']);
                    $storedItems = $this->db->table('reservation_items')->where('reservation_id', $existing['id'])->orderBy('resource_id')->get()->getResultArray();
                    $storedQuantities = [];
                    foreach ($storedItems as $item) {
                        $storedResource = $resources[(int) $item['resource_id']] ?? null;
                        if (!$storedResource) {
                            throw new DomainException('The resources for this submission key have changed.');
                        }
                        $selectionKey = $this->selectionKey($storedResource);
                        $storedQuantities[$selectionKey] = ($storedQuantities[$selectionKey] ?? 0) + (int) $item['quantity'];
                    }
                    $requestedQuantities = [];
                    foreach ($data['quantities'] as $resourceId => $quantity) {
                        $selectionKey = $this->selectionKey($resources[$resourceId]);
                        if (isset($requestedQuantities[$selectionKey])) {
                            throw new DomainException('Select a matching equipment group only once.');
                        }
                        $requestedQuantities[$selectionKey] = $quantity;
                    }
                    ksort($storedQuantities);
                    ksort($requestedQuantities);
                    if ((int) $existing['user_id'] !== $borrowerId || $existing['purpose'] !== $data['purpose']
                        || $storedQuantities !== $requestedQuantities || ($input['date'] ?? '') !== substr($local, 0, 10)
                        || ($input['start_time'] ?? '') !== substr($local, 11, 5)
                        || ($input['end_date'] ?? ($input['date'] ?? '')) !== substr($end, 0, 10)
                        || ($input['end_time'] ?? '') !== substr($end, 11, 5)) {
                        throw new DomainException('This submission key has already been used for a different request.');
                    }
                    $document = $this->db->table('reservation_documents')->where('reservation_id', $existing['id'])
                        ->orderBy('id', 'DESC')->get(1)->getRowArray();
                    if ($document) {
                        if (!hash_equals($document['sha256'], $storedDocument['sha256'])) {
                            throw new DomainException('This submission key has already been used with a different request form.');
                        }
                        return ['id' => (int) $existing['id'], 'created' => false, 'document_stored' => false, 'context' => []];
                    }
                    if ($existing['status'] !== 'pending') {
                        throw new DomainException('A request form cannot be added to a reservation that is no longer pending.');
                    }
                    (new ReservationDocumentService($this->db, $this->documentStorage))
                        ->insertDocument($this->db, (int) $existing['id'], $borrowerId, $storedDocument);
                    return ['id' => (int) $existing['id'], 'created' => false, 'document_stored' => true, 'context' => []];
                }
                $quote = $this->quote($data, (new BookingRules())->schedule($input), $resources);
                $now = gmdate('Y-m-d H:i:s');
                $this->db->table('reservations')->insert([
                    'user_id' => $borrowerId, 'purpose' => $data['purpose'], 'start_datetime' => $quote['start_datetime'],
                    'end_datetime' => $quote['end_datetime'], 'status' => 'pending', 'total_amount' => $quote['total_amount'],
                    'currency' => $quote['currency'], 'submission_key' => $key, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $id = (int) $this->db->insertID();
                foreach ($quote['reservation_items'] as $item) {
                    $this->db->table('reservation_items')->insert($item + ['reservation_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
                }
                (new ReservationDocumentService($this->db, $this->documentStorage))
                    ->insertDocument($this->db, $id, $borrowerId, $storedDocument);
                return [
                    'id' => $id,
                    'created' => true,
                    'document_stored' => true,
                    'context' => [
                        'schedule' => BookingRules::display($quote['start_datetime']) . ' to ' . BookingRules::display($quote['end_datetime']),
                        'amount' => $quote['total_amount'],
                        'currency' => $quote['currency'],
                        'academic_fixture' => AcademicFixturePolicy::matches($borrower, $data['purpose'], $key),
                    ],
                ];
            });
        } catch (\Throwable $exception) {
            $this->documentStorage->discard($storedDocument['stored_name']);
            throw $exception;
        }

        if (!$result['document_stored']) {
            $this->documentStorage->discard($storedDocument['stored_name']);
        }

        if ($result['created']) {
            $this->notifications->reservationSubmitted($result['id'], $result['context']);
        }

        return $result['id'];
    }

    public function decide(int $actorId, int $reservationId, string $action, string $remarks = ''): void
    {
        if (!in_array($action, ['approve', 'reject', 'cancel', 'complete', 'borrower_cancel'], true) || mb_strlen($remarks) > 2000) {
            throw new DomainException('Invalid decision or remarks.');
        }
        $remarks = trim($remarks);
        if (in_array($action, ['reject', 'cancel'], true) && $remarks === '') {
            throw new DomainException('A reason is required for rejection or administrator cancellation.');
        }
        // Items are immutable, so their IDs can be discovered before acquiring resource mutexes.
        $items = $this->db->table('reservation_items')->where('reservation_id', $reservationId)->orderBy('resource_id')->get()->getResultArray();
        if (!$items) {
            throw new DomainException('Reservation not found.');
        }
        $event = $this->transactions->run(function () use ($actorId, $reservationId, $action, $remarks, $items) {
            $resources = $this->transactions->lockResources(array_column($items, 'resource_id'));
            $reservation = $this->db->query('SELECT * FROM ' . $this->db->prefixTable('reservations') . ' WHERE id = ? FOR UPDATE', [$reservationId])->getRowArray();
            if (!$reservation) {
                throw new DomainException('Reservation not found.');
            }
            $borrowerAction = $action === 'borrower_cancel';
            $this->user($actorId, $borrowerAction ? 'borrower' : 'admin');
            if ($borrowerAction && (int) $reservation['user_id'] !== $actorId) {
                throw new DomainException('Reservation not found.');
            }
            $expected = in_array($action, ['approve', 'reject', 'borrower_cancel'], true) ? 'pending' : 'approved';
            if ($reservation['status'] !== $expected) {
                throw new DomainException('This decision is not allowed for the current reservation status.');
            }
            if (in_array($action, ['cancel', 'borrower_cancel'], true)) {
                $payments = $this->db->table('payments')->where('reservation_id', $reservationId)
                    ->whereIn('payment_status', ['pending', 'paid'])->get()->getResultArray();
                PaymentRules::assertCanCancel($payments, $borrowerAction);
            }
            $now = gmdate('Y-m-d H:i:s');
            if (in_array($action, ['approve', 'cancel'], true) && $reservation['start_datetime'] <= $now) {
                throw new DomainException('This action requires a reservation that has not started.');
            }
            if ($action === 'complete' && $reservation['end_datetime'] > $now) {
                throw new DomainException('A reservation can be completed only after its scheduled end.');
            }
            if ($action === 'approve') {
                $this->user((int) $reservation['user_id'], 'borrower');
                $form = $this->db->table('reservation_documents')->select('status')
                    ->where('reservation_id', $reservationId)->orderBy('id', 'DESC')->get(1)->getRowArray();
                if (($form['status'] ?? null) !== 'accepted') {
                    throw new DomainException('Accept the latest teacher-signed request form before approving this reservation.');
                }
                $availability = new AvailabilityService($this->db);
                $selectedFacilities = [];
                foreach ($items as $item) {
                    $resource = $resources[(int) $item['resource_id']];
                    if ($resource['resource_type'] === 'facility') {
                        ResourceConflictPolicy::assertNoConflicts(array_merge($selectedFacilities, [$resource]));
                        $selectedFacilities[] = $resource;
                    }
                    $availability->check($resource, (int) $item['quantity'],
                        $reservation['start_datetime'], $reservation['end_datetime'], $reservationId);
                }
            }
            $status = match ($action) {
                'approve' => 'approved', 'reject' => 'rejected', 'complete' => 'completed', default => 'cancelled',
            };
            $update = ['status' => $status, 'updated_at' => $now];
            if (!$borrowerAction) {
                $update += ['admin_id' => $actorId, 'admin_remarks' => $remarks === '' ? null : $remarks, 'reviewed_at' => $now];
            }
            $this->db->table('reservations')->where('id', $reservationId)->update($update);

            return [
                'user_id' => (int) $reservation['user_id'],
                'status' => $status,
                'context' => [
                    'schedule' => BookingRules::display($reservation['start_datetime']) . ' to ' . BookingRules::display($reservation['end_datetime']),
                    'amount' => $reservation['total_amount'],
                    'currency' => $reservation['currency'],
                ],
            ];
        });

        $this->notifications->reservationStatusChanged(
            $event['user_id'],
            $reservationId,
            $event['status'],
            $event['context']
        );
    }
}
