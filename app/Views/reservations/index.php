<?= $this->extend('resources/layout') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('css/reservation-table.css') ?>">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php use App\Services\BookingRules; ?>
<script src="<?= base_url('js/reservation-table.js') ?>" defer></script>
<?php if (!$admin): ?><p><a class="button" href="<?= site_url('borrower/reservations/new') ?>">Create reservation</a></p><?php endif ?>
<p>Schedule dates and times are displayed in Asia/Manila. Only approved reservations consume availability.</p>
<form method="get" class="filters" action="<?= site_url(($admin ? 'admin' : 'borrower') . '/reservations') ?>">
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['pending', 'approved', 'rejected', 'cancelled', 'completed'] as $status): ?><option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach ?></select></label>
    <label>Schedule from<input type="date" name="from" value="<?= esc($filters['from'], 'attr') ?>"></label>
    <label>Schedule through<input type="date" name="to" value="<?= esc($filters['to'], 'attr') ?>"></label>
    <button type="submit">Filter</button><a class="button secondary" href="<?= site_url(($admin ? 'admin' : 'borrower') . '/reservations') ?>">Clear</a>
</form>
<?php if (!$reservations): ?><p>No reservations match.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Reservation</th><th>Start (Manila)</th><th>End (Manila)</th><th>Status</th><th>Total</th></tr></thead><tbody>
<?php foreach ($reservations as $reservation): ?>
    <?php $detailUrl = site_url(($admin ? 'admin' : 'borrower') . '/reservations/' . $reservation['id']); ?>
    <tr class="reservation-row" data-row-link="<?= esc($detailUrl, 'attr') ?>" tabindex="0" aria-label="Open reservation #<?= (int) $reservation['id'] ?>">
        <td><a href="<?= esc($detailUrl, 'attr') ?>">#<?= (int) $reservation['id'] ?></a></td>
        <td><?= esc(BookingRules::display($reservation['start_datetime'])) ?></td>
        <td><?= esc(BookingRules::display($reservation['end_datetime'])) ?></td>
        <td><span class="status-badge <?= esc($reservation['status'], 'attr') ?>"><?= esc(ucfirst($reservation['status'])) ?></span></td>
        <td><?= esc($reservation['currency']) ?> <?= esc($reservation['total_amount']) ?></td>
    </tr>
<?php endforeach ?>
</tbody></table></div>
<?= $pager->links('default', 'admin_records') ?>
<?php endif ?>
<?= $this->endSection() ?>
