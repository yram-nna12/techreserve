<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAcademicFieldsToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'program' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'program_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'specialization' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'department' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['program', 'program_code', 'specialization', 'department']);
    }
}
