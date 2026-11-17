<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class CreateReservationDocuments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'reservation_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'stored_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
            ],
            'original_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'file_size' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'sha256' => [
                'type'       => 'CHAR',
                'constraint' => 64,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'accepted', 'rejected'],
                'default'    => 'pending',
            ],
            'rejection_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'reviewed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'submitted_at' => [
                'type' => 'DATETIME',
            ],
            'reviewed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('stored_name', 'reservation_document_stored_name');
        $this->forge->addKey(['reservation_id', 'id'], false, false, 'reservation_document_history');
        $this->forge->addKey(['user_id', 'id'], false, false, 'reservation_document_owner');
        $this->forge->addForeignKey('reservation_id', 'reservations', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('reviewed_by', 'users', 'id', 'SET NULL', 'RESTRICT');
        if (!$this->forge->createTable('reservation_documents')) {
            throw new RuntimeException('Unable to create the reservation documents table.');
        }
    }

    public function down()
    {
        $this->forge->dropTable('reservation_documents');
    }
}
