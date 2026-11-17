<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddPaymentGatewayReplayFields extends Migration
{
    public function up()
    {
        $duplicatePending = $this->db->query(
            'SELECT reservation_id FROM ' . $this->db->prefixTable('payments')
                . ' WHERE payment_status = ? GROUP BY reservation_id HAVING COUNT(*) > 1 LIMIT 1',
            ['pending']
        )->getRowArray();
        if ($duplicatePending) {
            throw new RuntimeException('Multiple unresolved payment attempts exist for one reservation; review them before applying this migration.');
        }

        $this->forge->addColumn('payments', [
            'request_payload' => ['type' => 'TEXT', 'null' => true],
            'checkout_url' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'active_reservation_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'last_verified_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addUniqueKey('active_reservation_id', 'payment_active_reservation');
        $this->forge->processIndexes('payments');

        $this->db->query(
            'UPDATE ' . $this->db->prefixTable('payments')
                . ' SET active_reservation_id = reservation_id WHERE payment_status = ?',
            ['pending']
        );
    }

    public function down()
    {
        $this->forge->dropKey('payments', 'payment_active_reservation');
        $this->forge->dropColumn('payments', [
            'request_payload',
            'checkout_url',
            'active_reservation_id',
            'last_verified_at',
        ]);
    }
}
