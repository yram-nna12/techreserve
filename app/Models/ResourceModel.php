<?php

namespace App\Models;

use CodeIgniter\Model;

class ResourceModel extends Model
{
    protected $table = 'resources';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $allowedFields = [
        'name', 'description', 'resource_type', 'quantity', 'rate_per_hour',
        'status', 'location', 'capacity',
    ];
}
