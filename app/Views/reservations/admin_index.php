<?= $this->extend('resources/layout') ?>
<?= $this->section('styles') ?><link rel="stylesheet" href="<?= base_url('css/reservation-table.css') ?>"><link rel="stylesheet" href="<?= base_url('css/admin-reservations.css?v=1') ?>"><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php use App\Services\BookingRules; ?>
<script src="<?= base_url('js/reservation-table.js') ?>" defer></script>
<p class="reservation-hint">Schedules are displayed in Asia/Manila. Only approved reservations consume availability.</p>
<form method="get" class="filters admin-reservation-filters" action="<?= site_url('admin/reservations') ?>">
    <label>Search<input type="search" name="q" maxlength="100" placeholder="Reference, student, resource" value="<?= esc($filters['q'],'attr') ?>"></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['pending','approved','rejected','cancelled','completed'] as $status): ?><option value="<?= $status ?>" <?= $filters['status']===$status?'selected':'' ?>><?= ucfirst($status) ?></option><?php endforeach ?></select></label>
    <label>Schedule from<input type="date" name="from" value="<?= esc($filters['from'],'attr') ?>"></label>
    <label>Schedule through<input type="date" name="to" value="<?= esc($filters['to'],'attr') ?>"></label>
    <button type="submit"><span class="material-symbols-outlined" aria-hidden="true">filter_list</span>Apply filters</button><a class="button secondary" href="<?= site_url('admin/reservations') ?>">Clear</a>
</form>
<?php $total=$pager->getTotal(); $page=$pager->getCurrentPage(); $perPage=$pager->getPerPage(); $first=$total ? (($page-1)*$perPage+1) : 0; $last=$total ? min($first+count($reservations)-1,$total) : 0; ?>
<p class="record-count">Showing <?= (int)$first ?>–<?= (int)$last ?> of <?= (int)$total ?> matching reservations</p>
<?php if (!$reservations): ?><p class="empty">No reservations match.</p><?php else: ?>
<div class="table-wrap"><table class="admin-reservation-table"><thead><tr><th>Reservation</th><th>Borrower</th><th>Reserved resources</th><th>Schedule</th><th>Total fee</th><th>Payment</th><th>Reservation status</th></tr></thead><tbody>
<?php foreach ($reservations as $reservation): $id=(int)$reservation['id']; $detailUrl=site_url('admin/reservations/'.$id); $names=array_values(array_unique($resources[$id]??[])); $payment=$payments[$id]??'unpaid'; ?>
<tr class="reservation-row" data-row-link="<?= esc($detailUrl,'attr') ?>" tabindex="0" aria-label="Open reservation #<?= $id ?>"><td><a href="<?= esc($detailUrl,'attr') ?>">#<?= $id ?></a><small>Submitted <?= esc(BookingRules::display($reservation['created_at'])) ?></small></td><td><?= esc($reservation['full_name']) ?><small><?= esc($reservation['student_id']) ?></small></td><td><?php if($names): ?><span title="<?= esc(implode(', ',$names),'attr') ?>"><?= esc($names[0]) ?><?= count($names)>1?' +'.(count($names)-1).' more':'' ?></span><?php else: ?>None recorded<?php endif ?></td><td><?= esc(BookingRules::display($reservation['start_datetime'])) ?><small>to <?= esc(BookingRules::display($reservation['end_datetime'])) ?></small></td><td><?= esc($reservation['currency']) ?> <?= esc($reservation['total_amount']) ?></td><td><span class="status-badge <?= esc($payment,'attr') ?>"><?= esc(ucfirst($payment)) ?></span></td><td><span class="status-badge <?= esc($reservation['status'],'attr') ?>"><?= esc(ucfirst($reservation['status'])) ?></span></td></tr>
<?php endforeach ?></tbody></table></div><?= $pager->links('default', 'admin_records') ?><?php endif ?>
<?= $this->endSection() ?>
