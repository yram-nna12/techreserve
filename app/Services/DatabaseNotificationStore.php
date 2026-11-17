<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

final class DatabaseNotificationStore implements NotificationStoreInterface, EmailOutboxStoreInterface
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function activeRecipient(int $userId): ?array
    {
        return $this->db->table('users')
            ->select('id, role, status, email')
            ->where('id', $userId)
            ->where('status', 'active')
            ->get()->getRowArray() ?: null;
    }

    public function activeAdministrators(): array
    {
        return $this->db->table('users')
            ->select('id, role, status, email')
            ->where('role', 'admin')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()->getResultArray();
    }

    public function createEvent(array $notification, ?array $email): bool
    {
        $this->db->transException(true);
        try {
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Could not begin notification storage transaction.');
            }

            $this->db->query(
                'INSERT INTO ' . $this->db->prefixTable('notifications')
                    . ' (user_id, reservation_id, title, message, notification_type, event_key, is_read, read_at, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [
                    $notification['user_id'],
                    $notification['reservation_id'],
                    $notification['title'],
                    $notification['message'],
                    $notification['notification_type'],
                    $notification['event_key'],
                    $notification['created_at'],
                    $notification['updated_at'],
                ]
            );
            if ($this->db->affectedRows() !== 1) {
                $this->db->transRollback();
                return false;
            }

            if ($email !== null) {
                $this->db->query(
                    'INSERT INTO ' . $this->db->prefixTable('notification_email_outbox')
                        . ' (user_id, event_key, recipient_email, subject, html_body, text_body, status, attempt_count,'
                        . ' available_at, locked_at, last_error_code, sent_at, created_at, updated_at)'
                        . ' VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, NULL, NULL, ?, ?)'
                        . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                    [
                        $email['user_id'],
                        $email['event_key'],
                        $email['recipient_email'],
                        $email['subject'],
                        $email['html_body'],
                        $email['text_body'],
                        'pending',
                        $notification['created_at'],
                        $notification['created_at'],
                        $notification['updated_at'],
                    ]
                );
                if ($this->db->affectedRows() !== 1) {
                    $this->db->transRollback();
                    return false;
                }
            }

            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Notification storage transaction failed.');
            }

            return true;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        } finally {
            $this->db->transException(false);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->countAllResults();
    }

    public function listForUser(int $userId, int $limit): array
    {
        return $this->db->table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function markRead(int $userId, int $notificationId, string $readAt): bool
    {
        $this->db->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'read_at' => $readAt, 'updated_at' => $readAt]);

        if ($this->db->affectedRows() > 0) {
            return true;
        }

        return $this->db->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }

    public function markAllRead(int $userId, string $readAt): int
    {
        $this->db->table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'read_at' => $readAt, 'updated_at' => $readAt]);

        return max(0, $this->db->affectedRows());
    }

    public function claimNext(int $maxAttempts, int $leaseSeconds, string $now): ?array
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - $leaseSeconds);
        $this->db->transException(true);
        try {
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Could not begin email claim transaction.');
            }

            $this->db->table('notification_email_outbox')
                ->where('status', 'processing')
                ->where('locked_at <=', $cutoff)
                ->where('attempt_count >=', $maxAttempts)
                ->update([
                    'status' => 'failed',
                    'locked_at' => null,
                    'last_error_code' => 'worker_lease_expired',
                    'updated_at' => $now,
                ]);

            $row = $this->db->query(
                'SELECT * FROM ' . $this->db->prefixTable('notification_email_outbox')
                    . ' WHERE attempt_count < ? AND ((status = ? AND available_at <= ?) OR (status = ? AND locked_at <= ?))'
                    . ' ORDER BY available_at, id LIMIT 1 FOR UPDATE',
                [$maxAttempts, 'pending', $now, 'processing', $cutoff]
            )->getRowArray();

            if (!$row) {
                if (!$this->db->transCommit()) {
                    throw new RuntimeException('Email claim transaction failed.');
                }
                return null;
            }

            $attempt = (int) $row['attempt_count'] + 1;
            $this->db->table('notification_email_outbox')->where('id', $row['id'])->update([
                'status' => 'processing',
                'attempt_count' => $attempt,
                'locked_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Email claim transaction failed.');
            }

            $row['attempt_count'] = $attempt;
            return $row;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        } finally {
            $this->db->transException(false);
        }
    }

    public function markSent(int $id, string $sentAt): void
    {
        $this->db->table('notification_email_outbox')->where('id', $id)->where('status', 'processing')->update([
            'status' => 'sent',
            'locked_at' => null,
            'last_error_code' => null,
            'sent_at' => $sentAt,
            'updated_at' => $sentAt,
        ]);
        if ($this->db->affectedRows() !== 1) {
            throw new RuntimeException('The claimed email could not be marked as sent.');
        }
    }

    public function markFailure(
        int $id,
        string $status,
        ?string $availableAt,
        string $errorCode,
        string $updatedAt
    ): void {
        if (!in_array($status, ['pending', 'failed'], true)) {
            throw new RuntimeException('Invalid email outbox failure state.');
        }
        $this->db->table('notification_email_outbox')->where('id', $id)->where('status', 'processing')->update([
            'status' => $status,
            'available_at' => $availableAt ?? $updatedAt,
            'locked_at' => null,
            'last_error_code' => $errorCode,
            'updated_at' => $updatedAt,
        ]);
        if ($this->db->affectedRows() !== 1) {
            throw new RuntimeException('The claimed email failure could not be recorded.');
        }
    }
}
