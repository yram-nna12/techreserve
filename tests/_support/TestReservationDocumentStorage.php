<?php

use App\Services\ReservationDocumentStorage;
use App\Services\ReservationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use App\Services\NotificationService;

final class TestReservationDocumentStorage extends ReservationDocumentStorage
{
    public function store(UploadedFile $upload): array
    {
        return [
            'stored_name' => bin2hex(random_bytes(32)) . '.pdf',
            'original_name' => $upload->getClientName(),
            'mime_type' => 'application/pdf',
            'file_size' => 64,
            'sha256' => hash('sha256', 'synthetic signed form'),
            'extension' => 'pdf',
        ];
    }

    public function discard(string $storedName): void
    {
    }
}

final class TestReservationService extends ReservationService
{
    public function __construct(?BaseConnection $db = null, ?NotificationService $notifications = null)
    {
        parent::__construct($db, $notifications, new TestReservationDocumentStorage());
    }

    public function submit(int $borrowerId, array $input, ?UploadedFile $formFile = null): int
    {
        $formFile ??= new UploadedFile(__FILE__, 'teacher-signed-form.pdf', 'application/pdf', 64, UPLOAD_ERR_OK);

        return parent::submit($borrowerId, $input, $formFile);
    }
}
