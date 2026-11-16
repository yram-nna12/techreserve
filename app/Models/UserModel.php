<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'student_id', 'full_name', 'email', 'password_hash', 'role', 'status',
        'program', 'program_code', 'specialization', 'department',
    ];
}
