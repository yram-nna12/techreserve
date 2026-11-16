<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<?php
use App\Services\BookingRules;
$now = gmdate('Y-m-d H:i:s');
$duration = intdiv((new DateTimeImmutable($reservation['end_datetime'], new DateTimeZone('UTC')))->getTimestamp()
    - (new DateTimeImmutable($reservation['start_datetime'], new DateTimeZone('UTC')))->getTimestamp(), 60);
?>
<p><strong>Status: <?= esc(ucfirst($reservation['status'])) ?></strong></p>
<p><?= esc(BookingRules::display($reservation['start_datetime'])) ?> to <?= esc(BookingRules::display($reservation['end_datetime'])) ?>, Asia/Manila (<?= $duration ?> minutes).</p>
<p class="description">Purpose: <?= esc($reservation['purpose']) ?></p>
<?php if ($admin && $borrower): ?><h2>Borrower</h2><dl><?php foreach ($borrower as $field => $value): ?><dt><?= esc(ucwords(str_replace('_', ' ', $field))) ?></dt><dd><?= esc($value ?? 'Not specified') ?></dd><?php endforeach ?></dl><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Resource</th><th>Quantity</th><th>Stored hourly rate</th><th>Stored subtotal</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= esc($item['name']) ?></td><td><?= (int) $item['quantity'] ?></td><td>PHP <?= esc($item['rate_per_hour']) ?></td><td>PHP <?= esc($item['subtotal']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<p><strong>Total fee: <?= esc($reservation['currency']) ?> <?= esc($reservation['total_amount']) ?></strong></p>
<p>Approval does not mean payment has been received. Payment processing is not available in this phase.</p>
<?php if ($reservation['reviewed_at']): ?><h2>Latest administrator decision</h2><p><?= esc($reviewer['full_name'] ?? 'Facilities Office') ?>, <?= esc(BookingRules::display($reservation['reviewed_at'])) ?> (Manila).</p><p class="description"><?= esc($reservation['admin_remarks'] ?? 'No remarks.') ?></p><?php endif ?>
<?php if (!$admin && $reservation['status'] === 'pending'): ?><form method="post" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/cancel') ?>"><?= csrf_field() ?><p><button type="submit">Cancel pending reservation</button></p></form><?php endif ?>
<?php if ($admin): ?>
    <?php $actions = [];
    if ($reservation['status'] === 'pending') { $actions = $reservation['start_datetime'] > $now ? ['approve', 'reject'] : ['reject']; }
    if ($reservation['status'] === 'approved' && $reservation['start_datetime'] > $now) { $actions = ['cancel']; }
    if ($reservation['status'] === 'approved' && $reservation['end_datetime'] <= $now) { $actions = ['complete']; }
    ?>
    <?php if ($actions): ?><form method="post" action="<?= site_url('admin/reservations/' . $reservation['id'] . '/decision') ?>">
        <?= csrf_field() ?><label for="remarks">Administrator remarks (required for rejection or cancellation)</label><textarea id="remarks" name="remarks" maxlength="2000" rows="3"></textarea>
        <p><?php foreach ($actions as $action): ?><button type="submit" name="action" value="<?= $action ?>"><?= ucfirst($action) ?></button> <?php endforeach ?></p>
    </form><?php endif ?>
<?php endif ?>
<p><a href="<?= site_url(($admin ? 'admin' : 'borrower') . '/reservations') ?>">Back to reservations</a></p>
<?= $this->endSection() ?>
