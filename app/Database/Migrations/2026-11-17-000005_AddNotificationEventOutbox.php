<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNotificationEventOutbox extends Migration
{
    public function up()
    {
        $this->forge->addColumn('notifications', [
            'event_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],
        ]);
        $this->forge->addUniqueKey(['user_id', 'event_key'], 'notification_user_event');
        $this->forge->processIndexes('notifications');

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'event_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'recipient_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
            ],
            'html_body' => [
                'type' => 'TEXT',
            ],
            'text_body' => [
                'type' => 'TEXT',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'processing', 'sent', 'failed'],
                'default'    => 'pending',
            ],
            'attempt_count' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'available_at' => [
                'type' => 'DATETIME',
            ],
            'locked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_error_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'sent_at' => [
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
        $this->forge->addUniqueKey(['user_id', 'event_key'], 'notification_email_user_event');
        $this->forge->addKey(['status', 'available_at'], false, false, 'notification_email_due');
        $this->forge->addKey('locked_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('notification_email_outbox');
    }

    public function down()
    {
        $this->forge->dropTable('notification_email_outbox');
        $this->forge->dropKey('notifications', 'notification_user_event');
        $this->forge->dropColumn('notifications', 'event_key');
    }
}
