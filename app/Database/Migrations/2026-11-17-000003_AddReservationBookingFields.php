<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddReservationBookingFields extends Migration
{
    public function up()
    {
        // Refuse conflicting legacy rows rather than removing or merging history.
        $duplicates = $this->db->table('reservation_items')->select('reservation_id, resource_id')
            ->groupBy(['reservation_id', 'resource_id'])->having('COUNT(*) >', 1)->get(1)->getRowArray();
        if ($duplicates) {
            throw new RuntimeException('Duplicate reservation items require review before this additive migration can run. No records were removed.');
        }
        $this->forge->addColumn('reservations', [
            'currency' => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'PHP'],
            'submission_key' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
        ]);
        $this->forge->addUniqueKey('submission_key', 'booking_submission');
        $this->forge->addKey(['status', 'start_datetime', 'end_datetime'], false, false, 'booking_schedule');
        $this->forge->processIndexes('reservations');
        $this->forge->addUniqueKey(['reservation_id', 'resource_id'], 'booking_item');
        $this->forge->processIndexes('reservation_items');
    }

    public function down()
    {
        $this->forge->dropKey('reservation_items', 'booking_item');
        $this->forge->dropKey('reservations', 'booking_submission');
        $this->forge->dropKey('reservations', 'booking_schedule');
        $this->forge->dropColumn('reservations', ['currency', 'submission_key']);
    }
}
