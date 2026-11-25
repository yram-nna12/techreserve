<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> | TechReserve</title>
    <link rel="stylesheet" href="<?= base_url('css/resources.css?v=20261125-4') ?>">
    <link rel="stylesheet" href="<?= base_url('css/fonts.css?v=20261123-3') ?>">
    <?= $this->renderSection('styles') ?>
    <?php if (!empty($admin)): ?><link rel="stylesheet" href="<?= base_url('css/admin-status-colors.css?v=1') ?>"><link rel="stylesheet" href="<?= base_url('css/admin-interactions.css?v=2') ?>"><?php endif ?>
</head>
<body class="<?= !empty($admin) ? 'admin-portal' : 'borrower-portal' ?>">
<?php $portalUser = (new \App\Models\UserModel())->select('full_name, role')->find((int) session()->get('user_id')); ?>
<?php if (!empty($admin)): ?>
    <aside class="portal-sidebar">
        <a class="sidebar-brand" href="<?= site_url('admin/dashboard') ?>"><img src="<?= base_url('assets/images/branding/techreserve-logo.png') ?>" alt="TechReserve"></a>
        <nav aria-label="Administrator navigation">
            <a class="<?= url_is('admin/dashboard') ? 'active' : '' ?>" href="<?= site_url('admin/dashboard') ?>"><span class="material-symbols-outlined">dashboard</span>Dashboard</a>
            <a class="<?= url_is('admin/reservations*') ? 'active' : '' ?>" href="<?= site_url('admin/reservations') ?>"><span class="material-symbols-outlined">event_available</span>Reservations</a>
            <a class="<?= url_is('admin/resources*') ? 'active' : '' ?>" href="<?= site_url('admin/resources') ?>"><span class="material-symbols-outlined">inventory_2</span>Resource Management</a>
            <a class="<?= url_is('admin/payments*') ? 'active' : '' ?>" href="<?= site_url('admin/payments') ?>"><span class="material-symbols-outlined">payments</span>Payments</a>
            <a class="<?= url_is('notifications*') ? 'active' : '' ?>" href="<?= site_url('notifications') ?>"><span class="material-symbols-outlined">notifications</span>Notifications</a>
        </nav>
        <div class="sidebar-bottom"><form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button type="submit" class="sidebar-logout"><span class="material-symbols-outlined">logout</span>Log out</button></form><div class="sidebar-user"><strong><?= esc($portalUser['full_name'] ?? 'Administrator') ?></strong><small>Facilities Office Admin</small></div></div>
    </aside>
    <main class="portal-main">
<?php else: ?>
    <aside class="portal-sidebar">
        <a class="sidebar-brand" href="<?= site_url('borrower/dashboard') ?>"><img src="<?= base_url('assets/images/branding/techreserve-logo.png') ?>" alt="TechReserve"></a>
        <nav aria-label="Student navigation">
            <a class="<?= url_is('borrower/dashboard') ? 'active' : '' ?>" href="<?= site_url('borrower/dashboard') ?>"><span class="material-symbols-outlined">dashboard</span>Dashboard</a>
            <a class="<?= url_is('borrower/resources*') ? 'active' : '' ?>" href="<?= site_url('borrower/resources') ?>"><span class="material-symbols-outlined">inventory_2</span>Browse Resources</a>
            <a class="<?= url_is('borrower/reservations*') ? 'active' : '' ?>" href="<?= site_url('borrower/reservations') ?>"><span class="material-symbols-outlined">event_available</span>My Reservations</a>
            <a class="<?= url_is('borrower/payments*') ? 'active' : '' ?>" href="<?= site_url('borrower/payments') ?>"><span class="material-symbols-outlined">payments</span>Payment History</a>
            <a class="<?= url_is('notifications*') ? 'active' : '' ?>" href="<?= site_url('notifications') ?>"><span class="material-symbols-outlined">notifications</span>Notifications</a>
        </nav>
        <div class="sidebar-bottom"><form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button type="submit" class="sidebar-logout"><span class="material-symbols-outlined">logout</span>Log out</button></form><div class="sidebar-user"><strong><?= esc($portalUser['full_name'] ?? 'Student') ?></strong><small>Student borrower</small></div></div>
    </aside>
    <main class="portal-main">
<?php endif ?>
<?php if (!str_contains(strtolower((string) $title), 'dashboard') && !( !empty($admin) && in_array($title, ['Resource Management', 'Notifications'], true))): ?><h1 class="portal-title"><?= esc($title) ?></h1><?php endif ?>
<?php if ($error = session()->getFlashdata('error')): ?><p class="alert error" role="alert"><?= esc($error) ?></p><?php endif ?>
<?php if ($success = session()->getFlashdata('success')): ?><p class="alert success" role="status"><?= esc($success) ?></p><?php endif ?>
<?= $this->renderSection('content') ?>
</main>
</body>
</html>
