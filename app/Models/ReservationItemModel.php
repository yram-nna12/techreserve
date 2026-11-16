<?php

namespace App\Models;

use CodeIgniter\Model;

class ReservationItemModel extends Model
{
    protected $table = 'reservation_items';
    protected $returnType = 'array';
    protected $allowedFields = [];
}
