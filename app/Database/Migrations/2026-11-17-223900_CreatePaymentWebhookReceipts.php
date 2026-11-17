<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePaymentWebhookReceipts extends Migration
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
            'event_id' => [
                'type'       => 'CHAR',
                'constraint' => 36,
            ],
            'payment_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'payload_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'payment_status' => [
                'type'       => 'ENUM',
                'constraint' => ['paid', 'failed', 'expired'],
            ],
            'processing_result' => [
                'type'       => 'ENUM',
                'constraint' => ['applied', 'already_applied'],
            ],
            'received_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('event_id', 'payment_webhook_event_id');
        $this->forge->addKey(['payment_id', 'received_at'], false, false, 'payment_webhook_history');
        $this->forge->addForeignKey('payment_id', 'payments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_webhook_receipts');
    }

    public function down()
    {
        $this->forge->dropTable('payment_webhook_receipts');
    }
}
