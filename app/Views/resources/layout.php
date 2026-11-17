<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> | TechReserve</title>
    <link rel="stylesheet" href="<?= base_url('css/resources.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<header><strong>TechReserve</strong><nav aria-label="Main navigation">
    <a href="<?= site_url(isset($admin) && !$admin ? 'borrower/dashboard' : 'admin/dashboard') ?>">Dashboard</a>
    <a href="<?= site_url(isset($admin) && !$admin ? 'borrower/resources' : 'admin/resources') ?>">Resources</a>
    <a href="<?= site_url(isset($admin) && !$admin ? 'borrower/reservations' : 'admin/reservations') ?>">Reservations</a>
    <a href="<?= site_url(isset($admin) && !$admin ? 'borrower/payments' : 'admin/payments') ?>">Payments</a>
    <?= view('notifications/_navigation') ?>
    <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button type="submit" class="secondary">Log out</button></form>
</nav></header>
<main>
    <h1><?= esc($title) ?></h1>
    <?php if ($error = session()->getFlashdata('error')): ?><p class="alert error" role="alert"><?= esc($error) ?></p><?php endif ?>
    <?php if ($success = session()->getFlashdata('success')): ?><p class="alert success" role="status"><?= esc($success) ?></p><?php endif ?>
    <?= $this->renderSection('content') ?>
</main>
</body>
</html>
