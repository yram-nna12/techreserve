<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use RuntimeException;
class AddCancelledPaymentStatus extends Migration {
 public function up() {
  $unknown=$this->db->query("SELECT payment_status FROM payments WHERE payment_status NOT IN ('pending','paid','failed','expired','refunded') LIMIT 1")->getRowArray();
  if($unknown) throw new RuntimeException('Unexpected TechReserve payment status; cancellation migration stopped safely.');
  $this->db->query("ALTER TABLE payments MODIFY payment_status ENUM('pending','paid','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending'");
  $this->db->query("ALTER TABLE payment_webhook_receipts MODIFY payment_status ENUM('paid','failed','cancelled','expired') NOT NULL");
 }
 public function down() { throw new RuntimeException('Cancellation status migration is intentionally forward-only to preserve payment history.'); }
}
