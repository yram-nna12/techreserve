<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddResourceDetails extends Migration
{
    public function up()
    {
        $this->forge->addColumn('resources', [
            'location' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'capacity' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('resources', ['location', 'capacity', 'deleted_at']);
    }
}
