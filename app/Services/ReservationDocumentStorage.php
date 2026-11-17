<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use finfo;
use RuntimeException;

class ReservationDocumentStorage
{
    private string $directory;
    private int $maxFileSizeBytes;

    public function __construct(?string $directory = null, ?int $maxFileSizeBytes = null)
    {
        $config = config('ReservationDocuments');
        $this->directory = $directory ?? $config->storageDirectory;
        $this->maxFileSizeBytes = $maxFileSizeBytes ?? $config->maxFileSizeBytes;
    }

    public function inspectFile(string $path, string $clientName, ?int $reportedSize = null): array
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new DomainException('The uploaded form is not a readable file.');
        }
        $size = filesize($realPath);
        if ($size === false || $size < 1 || $size > $this->maxFileSizeBytes
            || ($reportedSize !== null && $reportedSize !== $size)) {
            throw new DomainException('The uploaded form is empty, exceeds the configured size limit, or is incomplete.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $extensions = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ];
        $clientExtension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if (!is_string($mime) || !isset($extensions[$mime]) || !in_array($clientExtension, $extensions[$mime], true)) {
            throw new DomainException('Upload a valid PDF, JPG, or PNG form.');
        }
        $hash = hash_file('sha256', $realPath);
        if (!is_string($hash)) {
            throw new RuntimeException('The uploaded form could not be verified.');
        }

        $clientName = basename(str_replace('\\', '/', $clientName));
        $clientName = preg_replace('/[\x00-\x1F\x7F]/', '', $clientName) ?? '';
        $clientName = mb_substr($clientName, 0, 255);
        if ($clientName === '') {
            $clientName = 'reservation-form.' . $extensions[$mime][0];
        }

        return [
            'original_name' => $clientName,
            'mime_type' => $mime,
            'file_size' => $size,
            'sha256' => $hash,
            'extension' => $extensions[$mime][0],
            'source_path' => $realPath,
        ];
    }

    public function store(UploadedFile $upload): array
    {
        if ($upload->hasMoved() || $upload->getError() !== UPLOAD_ERR_OK || !$upload->isValid()) {
            throw new DomainException('Choose a complete signed request form to upload.');
        }

        $metadata = $this->inspectFile($upload->getTempName(), $upload->getClientName(), $upload->getSize());
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Private reservation-form storage is unavailable.');
        }
        @chmod($this->directory, 0700);

        $storedName = bin2hex(random_bytes(32)) . '.' . $metadata['extension'];
        if (!$upload->move($this->directory, $storedName, false)) {
            throw new RuntimeException('The uploaded form could not be stored.');
        }

        $destination = $this->pathFor($storedName);
        $storedMetadata = $this->inspectFile($destination, $metadata['original_name'], $metadata['file_size']);
        if (!hash_equals($metadata['sha256'], $storedMetadata['sha256'])) {
            $this->discard($storedName);
            throw new RuntimeException('The stored form did not match the verified upload.');
        }

        unset($metadata['source_path']);
        return $metadata + ['stored_name' => $storedName];
    }

    public function pathFor(string $storedName): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\.(?:pdf|jpg|png)\z/', $storedName)) {
            throw new DomainException('The requested document is unavailable.');
        }
        $directory = realpath($this->directory);
        $path = realpath($this->directory . DIRECTORY_SEPARATOR . $storedName);
        if ($directory === false || $path === false || !is_file($path)
            || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            throw new DomainException('The requested document is unavailable.');
        }

        return $path;
    }

    public function discard(string $storedName): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\.(?:pdf|jpg|png)\z/', $storedName)) {
            return;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($path) && !unlink($path)) {
            log_message('error', 'A staged reservation form could not be removed.');
        }
    }
}
