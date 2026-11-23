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
<section aria-labelledby="request-form-heading">
    <h2 id="request-form-heading">Teacher-signed request form</h2>
    <?php if ($latestDocument): ?>
        <p>Latest form status: <strong><?= esc(ucfirst($latestDocument['status'])) ?></strong></p>
        <?php if ($admin): ?>
            <?php foreach ($documents as $document): ?>
                <p>
                    Version submitted <?= esc($document['submitted_at']) ?>:
                    <?= esc($document['original_name']) ?> (<?= esc($document['mime_type']) ?>),
                    <?= esc(ucfirst($document['status'])) ?>,
                    <a href="<?= site_url('admin/reservations/' . $reservation['id'] . '/documents/' . $document['id']) ?>">Download form</a>
                </p>
                <?php if (!empty($document['rejection_reason'])): ?><p class="description">Rejection reason: <?= esc($document['rejection_reason']) ?></p><?php endif ?>
            <?php endforeach ?>
            <?php if ($reservation['status'] === 'pending' && $latestDocument['status'] === 'pending'): ?>
                <form method="post" action="<?= site_url('admin/reservations/' . $reservation['id'] . '/documents/' . $latestDocument['id'] . '/review') ?>">
                    <?= csrf_field() ?>
                    <label for="rejection_reason">Reason if rejecting the form</label>
                    <textarea id="rejection_reason" name="rejection_reason" maxlength="2000" rows="3"></textarea>
                    <p><button type="submit" name="decision" value="accept">Accept form</button>
                        <button type="submit" name="decision" value="reject">Reject form</button></p>
                </form>
            <?php endif ?>
        <?php else: ?>
            <p><?= esc($latestDocument['original_name']) ?> — <?= number_format((int) $latestDocument['file_size'] / 1048576, 2) ?> MiB
                <a href="<?= site_url('borrower/reservations/' . $reservation['id'] . '/documents/' . $latestDocument['id']) ?>">Download your form</a>
            </p>
            <?php if (!empty($latestDocument['rejection_reason'])): ?><p class="description">Administrator feedback: <?= esc($latestDocument['rejection_reason']) ?></p><?php endif ?>
            <?php if ($reservation['status'] === 'pending' && $latestDocument['status'] === 'rejected'): ?>
                <form method="post" enctype="multipart/form-data" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/form') ?>">
                    <?= csrf_field() ?>
                    <label for="replacement_form">Upload replacement signed request form (PDF, JPG, or PNG)</label>
                    <input id="replacement_form" name="request_form" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                    <small>Maximum size <?= number_format(config('ReservationDocuments')->maxFileSizeBytes / 1048576, 1) ?> MiB. The replacement returns to pending review.</small>
                    <p><button type="submit">Resubmit form for review</button></p>
                </form>
            <?php endif ?>
        <?php endif ?>
    <?php elseif (!$admin && $reservation['status'] === 'pending'): ?>
        <p>No signed form has been recorded. Upload it now for administrator review.</p>
        <form method="post" enctype="multipart/form-data" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/form') ?>">
            <?= csrf_field() ?>
            <label for="replacement_form">Upload teacher-signed request form (PDF, JPG, or PNG)</label>
            <input id="replacement_form" name="request_form" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
            <small>Maximum size <?= number_format(config('ReservationDocuments')->maxFileSizeBytes / 1048576, 1) ?> MiB. The form will be reviewed by an administrator.</small>
            <p><button type="submit">Submit form for review</button></p>
        </form>
    <?php else: ?>
        <p>No signed form has been recorded. Payment and reservation approval are blocked until a valid form is submitted and accepted.</p>
    <?php endif ?>
</section>
<?php if ($admin && $borrower): ?><h2>Borrower</h2><dl><?php foreach ($borrower as $field => $value): ?><dt><?= esc(ucwords(str_replace('_', ' ', $field))) ?></dt><dd><?= esc($value ?? 'Not specified') ?></dd><?php endforeach ?></dl><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Resource</th><th>Quantity</th><th>Stored hourly rate</th><th>Stored subtotal</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= esc(!$admin && !empty($item['catalog_group']) ? $item['catalog_group'] : $item['name']) ?><?php if ($admin && !empty($item['inventory_identifier'])): ?><br>Inventory: <?= esc($item['inventory_identifier']) ?><?php endif ?></td><td><?= (int) $item['quantity'] ?></td><td>PHP <?= esc($item['rate_per_hour']) ?></td><td>PHP <?= esc($item['subtotal']) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<p><strong>Total fee: <?= esc($reservation['currency']) ?> <?= esc($reservation['total_amount']) ?></strong></p>
<section class="payment-panel" aria-labelledby="payment-heading">
    <h2 id="payment-heading">Payment</h2>
    <p>Reservation approval and payment are tracked separately.</p>
    <?php if ($payments): ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Payment reference</th><th>Method</th><th>Status</th><th>Amount</th><th>Gateway transaction</th></tr></thead>
            <tbody><?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= esc($payment['transaction_reference'] ?? 'Pending reference') ?></td>
                    <td><?= esc(ucwords(str_replace('_', ' ', $payment['payment_method'] ?? ''))) ?></td>
                    <td><?= esc(ucfirst($payment['payment_status'])) ?></td>
                    <td><?= esc($payment['currency']) ?> <?= esc($payment['amount']) ?></td>
                    <td><?= esc($payment['gateway_transaction_id'] ?? 'Not received yet') ?></td>
                </tr>
            <?php endforeach ?></tbody>
        </table></div>
    <?php else: ?>
        <p>Payment status: Unpaid</p>
    <?php endif ?>
    <?php
    $pendingPayment = null;
    $hasPaidPayment = false;
    foreach ($payments as $payment) {
        if ($payment['payment_status'] === 'paid') {
            $hasPaidPayment = true;
        }
        if ($payment['payment_status'] === 'pending' && $pendingPayment === null) {
            $pendingPayment = $payment;
        }
    }
    $validPaymentTotal = preg_match('/\A(?:0|[1-9][0-9]{0,7})\.[0-9]{2}\z/', (string) $reservation['total_amount']) === 1
        && $reservation['total_amount'] !== '0.00' && $reservation['currency'] === 'PHP';
    $formAccepted = $latestDocument && $latestDocument['status'] === 'accepted';
    ?>
    <?php if (!$admin && $reservation['status'] === 'approved' && $formAccepted && $validPaymentTotal && !$hasPaidPayment && !$pendingPayment): ?>
        <form method="post" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/payments') ?>" class="payment-form">
            <?= csrf_field() ?>
            <fieldset class="payment-methods"><legend>Choose a demo payment method</legend>
                <label><input type="radio" name="payment_method" value="gcash" checked><span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span><span><strong>Mock GCash</strong><small>Mobile-wallet style demo checkout</small></span></label>
                <label><input type="radio" name="payment_method" value="card"><span class="material-symbols-outlined" aria-hidden="true">credit_card</span><span><strong>Demo Credit / Debit Card</strong><small>Fictional test card details only</small></span></label>
                <label><input type="radio" name="payment_method" value="bank_transfer"><span class="material-symbols-outlined" aria-hidden="true">account_balance</span><span><strong>Demo Bank Transfer</strong><small>Fictional bank and account details only</small></span></label>
            </fieldset>
            <p><button type="submit">Pay Now</button></p>
        </form>
    <?php elseif (!$admin && $pendingPayment): ?>
        <p role="status">Payment status: Pending confirmation. An uncertain attempt remains open; do not start a separate payment.</p>
        <form method="post" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/payments/' . $pendingPayment['id'] . '/verify') ?>">
            <?= csrf_field() ?><button type="submit" class="secondary">Check Payment Status</button>
        </form>
        <?php if ($reservation['status'] === 'approved' && $formAccepted && is_string($pendingPayment['request_payload'] ?? null) && $pendingPayment['request_payload'] !== ''): ?>
            <form method="post" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/payments') ?>" class="payment-form">
                <?= csrf_field() ?>
                <input type="hidden" name="payment_method" value="<?= esc($pendingPayment['payment_method']) ?>">
                <p><button type="submit">Continue Existing Payment</button></p>
            </form>
        <?php endif ?>
    <?php elseif (!$admin && !$hasPaidPayment && in_array($reservation['status'], ['rejected', 'cancelled', 'completed'], true)): ?>
        <p>New payment attempts are unavailable for this reservation status.</p>
    <?php elseif (!$admin && !$formAccepted): ?>
        <p>Payment is blocked until an administrator accepts the latest teacher-signed request form.</p>
    <?php elseif (!$admin && $hasPaidPayment): ?>
        <p role="status">Payment status: Paid. Borrower cancellation is unavailable because refunds are not supported.</p>
    <?php elseif (!$admin && $reservation['status'] !== 'approved'): ?>
        <p>Payment is available only after administrator approval.</p>
    <?php elseif (!$admin && $reservation['status'] === 'approved' && !$validPaymentTotal && !$hasPaidPayment && !$pendingPayment): ?>
        <p>This reservation total cannot be processed by the mock gateway.</p>
    <?php endif ?>
</section>
<?php if ($reservation['reviewed_at']): ?><h2>Latest administrator decision</h2><p><?= esc($reviewer['full_name'] ?? 'Facilities Office') ?>, <?= esc(BookingRules::display($reservation['reviewed_at'])) ?> (Manila).</p><p class="description"><?= esc($reservation['admin_remarks'] ?? 'No remarks.') ?></p><?php endif ?>
<?php if (!$admin && $reservation['status'] === 'pending'): ?><form method="post" action="<?= site_url('borrower/reservations/' . $reservation['id'] . '/cancel') ?>"><?= csrf_field() ?><p><button type="submit">Cancel pending reservation</button></p></form><?php endif ?>
<?php if ($admin): ?>
    <?php $actions = [];
    if ($reservation['status'] === 'pending') {
        $actions = $reservation['start_datetime'] > $now
            ? (($latestDocument && $latestDocument['status'] === 'accepted') ? ['approve', 'reject'] : ['reject'])
            : ['reject'];
    }
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
