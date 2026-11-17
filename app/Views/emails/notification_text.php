<?= $brand ?>
<?= $title ?>

<?= $message ?>
<?php foreach ($details as $detail): ?>
<?= $detail['label'] ?>: <?= $detail['value'] ?>
<?php endforeach ?>

View reservation: <?= $reservationUrl ?>

Sign in to TechReserve to view your reservation. This email does not contain payment credentials or account numbers.
