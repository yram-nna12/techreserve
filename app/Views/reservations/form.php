<?= $this->extend('resources/layout') ?>
<?= $this->section('styles') ?><link rel="stylesheet" href="<?= base_url('css/borrower-form.css?v=1') ?>"><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
use App\Services\BookingRules;
$value = static fn($field) => is_string($input[$field] ?? null) ? $input[$field] : '';
$selected = [];
foreach (is_array($input['items'] ?? null) ? $input['items'] : [] as $item) {
    if (is_array($item) && is_scalar($item['resource_id'] ?? null)) {
        $selected[(string) $item['resource_id']] = is_scalar($item['quantity'] ?? null) ? (string) $item['quantity'] : '1';
    }
}
?>
<p>All schedule inputs are in <strong><?= esc($booking->timezone) ?></strong>. Select one shared interval for all resources.</p>
<p><?= $booking->minimumMinutes ?>-minute minimum; <?= $booking->incrementMinutes ?>-minute clock increments; <?= $booking->maximumMinutes / 60 ?>-hour maximum; up to <?= $booking->advanceDays ?> days ahead. Pending requests do not hold availability.</p>
<?php if ($error): ?><p class="alert error" role="alert"><?= esc($error) ?></p><?php endif ?>
<?php if ($quote): ?>
<section class="alert success" aria-label="Reservation preview">
    <h2>Availability and fee preview</h2>
    <p><?= esc(BookingRules::display($quote['start_datetime'])) ?> to <?= esc(BookingRules::display($quote['end_datetime'])) ?> (Manila), <?= (int) $quote['minutes'] ?> minutes.</p>
    <ul><?php foreach ($quote['items'] as $item): ?><li><?= esc($item['name']) ?>: <?= (int) $item['quantity'] ?> × PHP <?= esc($item['rate_per_hour']) ?>/hour = PHP <?= esc($item['subtotal']) ?></li><?php endforeach ?></ul>
    <p><strong>Estimated total: PHP <?= esc($quote['total_amount']) ?></strong></p>
    <p>Available when checked. Availability and current rates will be checked again on submission; approval is still required.</p>
</section>
<?php endif ?>
<form class="reservation-request-form" method="post" enctype="multipart/form-data" action="<?= site_url('borrower/reservations/preview') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="submission_key" value="<?= esc($value('submission_key'), 'attr') ?>">
    <section class="student-form-section"><h2>Schedule and Duration</h2><div class="filters">
        <label>Start date<input name="date" type="date" required value="<?= esc($value('date'), 'attr') ?>"></label>
        <label>Start time<select name="start_time" required><option value="">Choose time</option><?php for ($minute = 0; $minute < 1440; $minute += $booking->incrementMinutes): $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60); ?><option value="<?= $time ?>" <?= $value('start_time') === $time ? 'selected' : '' ?>><?= $time ?></option><?php endfor ?></select></label>
        <label>End date<input name="end_date" type="date" required value="<?= esc($value('end_date') ?: $value('date'), 'attr') ?>"></label>
        <label>End time<select name="end_time" required><option value="">Choose time</option><?php for ($minute = 0; $minute < 1440; $minute += $booking->incrementMinutes): $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60); ?><option value="<?= $time ?>" <?= $value('end_time') === $time ? 'selected' : '' ?>><?= $time ?></option><?php endfor ?></select></label>
    </div></section>
    <section class="student-form-section"><h2>Facilities and Equipment</h2>
    <?php if (!$resources): ?><p>No operational resources are currently available.</p><?php endif ?>
    <div class="table-wrap"><table><thead><tr><th>Select</th><th>Resource</th><th>Hourly rate per unit</th><th>Requested quantity</th></tr></thead><tbody>
    <?php foreach ($resources as $index => $resource): ?>
        <tr><td><input aria-label="Select <?= esc($resource['name'], 'attr') ?>" type="checkbox" name="items[<?= $index ?>][resource_id]" value="<?= (int) $resource['id'] ?>" <?= isset($selected[$resource['id']]) ? 'checked' : '' ?>></td>
        <td><?= esc($resource['name']) ?> (<?= esc($resource['resource_type']) ?>)<br><?= esc($resource['location'] ?? '') ?></td>
        <td>PHP <?= esc($resource['rate_per_hour']) ?></td>
        <td><input aria-label="Quantity for <?= esc($resource['name'], 'attr') ?>" type="number" name="items[<?= $index ?>][quantity]" min="1" step="1" max="<?= (int) $resource['quantity'] ?>" <?= $resource['resource_type'] === 'facility' ? 'readonly' : '' ?> value="<?= esc($resource['resource_type'] === 'facility' ? '1' : ($selected[$resource['id']] ?? '1'), 'attr') ?>"></td></tr>
    <?php endforeach ?>
    </tbody></table></div></section>
    <section class="student-form-section"><h2>Purpose and Required Document</h2><div class="student-form-columns"><p><label for="purpose">Purpose of reservation</label><textarea id="purpose" name="purpose" required maxlength="2000" rows="3"><?= esc($value('purpose')) ?></textarea></p>
    <p><label for="request_form">Teacher-signed request form (PDF, JPG, or PNG)</label>
        <input id="request_form" name="request_form" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
        <small>Required to submit the reservation; maximum size <?= number_format(config('ReservationDocuments')->maxFileSizeBytes / 1048576, 1) ?> MiB. The file is stored privately.</small>
    </p></div></section>
    <p class="student-form-actions"><button type="submit" formnovalidate>Preview availability and fees</button> <button type="submit" formaction="<?= site_url('borrower/reservations') ?>">Submit for review</button></p>
    <p><a href="<?= site_url('borrower/reservations') ?>">My reservations</a></p>
</form>
<?= $this->endSection() ?>
