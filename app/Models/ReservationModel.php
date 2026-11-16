<?php

namespace App\Models;

use CodeIgniter\Model;

class ReservationModel extends Model
{
    protected $table = 'reservations';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    // All mutations go through ReservationService transactions, not posted model fields.
    protected $allowedFields = [];
}
