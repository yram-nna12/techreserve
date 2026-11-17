<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

class ReservationDocuments extends BaseConfig
{
    public int $maxFileSizeBytes = 10485760;
    public string $storageDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->storageDirectory = WRITEPATH . 'private' . DIRECTORY_SEPARATOR . 'reservation-forms';

        $maxFileSize = filter_var(
            env('reservationDocuments.maxFileSizeBytes', $this->maxFileSizeBytes),
            FILTER_VALIDATE_INT
        );
        if ($maxFileSize === false || $maxFileSize < 1024 || $maxFileSize > 104857600) {
            throw new InvalidArgumentException(
                'reservationDocuments.maxFileSizeBytes must be between 1024 and 104857600.'
            );
        }
        $this->maxFileSizeBytes = $maxFileSize;
    }
}
