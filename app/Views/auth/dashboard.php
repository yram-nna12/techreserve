<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<p class="muted">Welcome, <?= esc($user['full_name']) ?>.</p>
<span class="badge"><?= esc(ucfirst($user['role'])) ?> · Active account</span>
<dl>
    <?php $identityFields = $user['role'] === 'admin'
        ? ['Office login identifier' => 'student_id', 'Office email' => 'email']
        : ['Student ID' => 'student_id', 'Institutional email' => 'email', 'Program' => 'program', 'Program code' => 'program_code', 'Specialization' => 'specialization', 'Department' => 'department']; ?>
    <?php foreach ($identityFields as $label => $field): ?>
        <dt><?= esc($label) ?></dt><dd><?= esc($user[$field] ?: '—') ?></dd>
    <?php endforeach ?>
</dl>
<?php if ($user['role'] === 'borrower'): ?><p class="muted fine">These identity details were verified at registration. Contact the Facilities Office if they need correction.</p><?php else: ?><p class="muted fine">This Facilities Office account was provisioned separately. It is not a verified student account.</p><?php endif ?>
<p><a href="<?= site_url($user['role'] === 'admin' ? 'admin/resources' : 'borrower/resources') ?>"><?= $user['role'] === 'admin' ? 'Manage resources' : 'Browse resources' ?></a></p>
<form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="secondary" type="submit">Log out</button></form>
<p><a href="<?= site_url($user['role'] . '/reservations') ?>"><?= $user['role'] === 'admin' ? 'Review reservations' : 'My reservations' ?></a></p>
<?= $this->endSection() ?>
