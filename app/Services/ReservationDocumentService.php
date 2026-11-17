<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use DomainException;
use RuntimeException;

final class ReservationDocumentService
{
    private BaseConnection $db;
    private BookingTransactions $transactions;
    private ReservationDocumentStorage $storage;

    public function __construct(?BaseConnection $db = null, ?ReservationDocumentStorage $storage = null)
    {
        $this->db = $db ?? Database::connect();
        $this->transactions = new BookingTransactions($this->db);
        $this->storage = $storage ?? new ReservationDocumentStorage();
    }

    public function latestForReservation(int $reservationId): ?array
    {
        return $this->db->table('reservation_documents')
            ->select('id, reservation_id, user_id, original_name, mime_type, file_size, status, rejection_reason, reviewed_by, submitted_at, reviewed_at')
            ->where('reservation_id', $reservationId)->orderBy('id', 'DESC')->get(1)->getRowArray() ?: null;
    }

    public function allForReservation(int $reservationId): array
    {
        return $this->db->table('reservation_documents')
            ->select('id, reservation_id, user_id, original_name, mime_type, file_size, status, rejection_reason, reviewed_by, submitted_at, reviewed_at')
            ->where('reservation_id', $reservationId)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    public function downloadForBorrower(int $borrowerId, int $reservationId, int $documentId): array
    {
        $document = $this->db->table('reservation_documents AS documents')
            ->select('documents.*')
            ->join('reservations', 'reservations.id = documents.reservation_id')
            ->where('documents.id', $documentId)->where('documents.reservation_id', $reservationId)
            ->where('documents.user_id', $borrowerId)->where('reservations.user_id', $borrowerId)
            ->get()->getRowArray();
        if (!$document) {
            throw new DomainException('Reservation form not found.');
        }

        return $this->downloadRecord($document);
    }

    public function downloadForAdmin(int $adminId, int $reservationId, int $documentId): array
    {
        $this->activeAdmin($adminId);
        $document = $this->db->table('reservation_documents')
            ->where('id', $documentId)->where('reservation_id', $reservationId)->get()->getRowArray();
        if (!$document) {
            throw new DomainException('Reservation form not found.');
        }

        return $this->downloadRecord($document);
    }

    private function downloadRecord(array $document): array
    {
        $path = $this->storage->pathFor($document['stored_name']);
        $actual = $this->storage->inspectFile($path, $document['original_name'], (int) $document['file_size']);
        if ($actual['mime_type'] !== $document['mime_type']
            || !hash_equals($document['sha256'], $actual['sha256'])) {
            throw new RuntimeException('The stored reservation form failed integrity verification.');
        }

        return ['path' => $path, 'mime_type' => $actual['mime_type'], 'extension' => $actual['extension']];
    }

    public function resubmit(int $borrowerId, int $reservationId, ?UploadedFile $upload): void
    {
        if (!$upload) {
            throw new DomainException('Upload a replacement signed request form.');
        }
        $stored = $this->storage->store($upload);
        try {
            $this->transactions->run(function ($db) use ($borrowerId, $reservationId, $stored): void {
                $reservation = $db->query(
                    'SELECT id, user_id, status FROM ' . $db->prefixTable('reservations') . ' WHERE id = ? FOR UPDATE',
                    [$reservationId]
                )->getRowArray();
                if (!$reservation || (int) $reservation['user_id'] !== $borrowerId) {
                    throw new DomainException('Reservation not found.');
                }
                $this->activeBorrower($borrowerId);
                $latest = $db->table('reservation_documents')->where('reservation_id', $reservationId)
                    ->orderBy('id', 'DESC')->get(1)->getRowArray();
                $hasPayments = $db->table('payments')->where('reservation_id', $reservationId)->countAllResults() > 0;
                ReservationDocumentPolicy::assertCanResubmit($reservation['status'], $latest ?: null, $hasPayments);

                $this->insertDocument($db, $reservationId, $borrowerId, $stored);
            });
        } catch (\Throwable $exception) {
            $this->storage->discard($stored['stored_name']);
            throw $exception;
        }
    }

    public function review(int $adminId, int $reservationId, int $documentId, string $decision, string $reason = ''): void
    {
        if (!in_array($decision, ['accept', 'reject'], true) || mb_strlen($reason) > 2000) {
            throw new DomainException('Choose a valid form review decision and reason.');
        }
        $reason = trim($reason);
        if ($decision === 'reject' && $reason === '') {
            throw new DomainException('A reason is required when rejecting a submitted form.');
        }

        $this->transactions->run(function ($db) use ($adminId, $reservationId, $documentId, $decision, $reason): void {
            $reservation = $db->query(
                'SELECT id, status FROM ' . $db->prefixTable('reservations') . ' WHERE id = ? FOR UPDATE',
                [$reservationId]
            )->getRowArray();
            if (!$reservation) {
                throw new DomainException('Reservation not found.');
            }
            $this->activeAdmin($adminId);
            $document = $db->query(
                'SELECT * FROM ' . $db->prefixTable('reservation_documents')
                    . ' WHERE id = ? AND reservation_id = ? FOR UPDATE',
                [$documentId, $reservationId]
            )->getRowArray();
            if (!$document) {
                throw new DomainException('Reservation form not found.');
            }
            $latest = $db->table('reservation_documents')->select('id')->where('reservation_id', $reservationId)
                ->orderBy('id', 'DESC')->get(1)->getRowArray();
            ReservationDocumentPolicy::assertCanReview($reservation['status'], $document, (int) ($latest['id'] ?? 0));

            $now = gmdate('Y-m-d H:i:s');
            $db->table('reservation_documents')->where('id', $documentId)->update([
                'status' => $decision === 'accept' ? 'accepted' : 'rejected',
                'rejection_reason' => $decision === 'reject' ? $reason : null,
                'reviewed_by' => $adminId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function insertDocument(BaseConnection $db, int $reservationId, int $borrowerId, array $stored): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $db->table('reservation_documents')->insert([
            'reservation_id' => $reservationId,
            'user_id' => $borrowerId,
            'stored_name' => $stored['stored_name'],
            'original_name' => $stored['original_name'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['file_size'],
            'sha256' => $stored['sha256'],
            'status' => 'pending',
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$inserted) {
            throw new RuntimeException('The reservation form could not be recorded.');
        }
    }

    private function activeAdmin(int $adminId): void
    {
        $user = $this->db->table('users')->select('id')->where('id', $adminId)
            ->where('role', 'admin')->where('status', 'active')->get()->getRowArray();
        if (!$user) {
            throw new DomainException('An active administrator account is required.');
        }
    }

    private function activeBorrower(int $borrowerId): void
    {
        $user = $this->db->table('users')->select('id')->where('id', $borrowerId)
            ->where('role', 'borrower')->where('status', 'active')->get()->getRowArray();
        if (!$user) {
            throw new DomainException('An active borrower account is required.');
        }
    }
}
