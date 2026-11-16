<?= $this->extend('auth/layout') ?>
<?= $this->section('content') ?>
<p class="muted">Welcome, <?= esc($user['full_name']) ?>.</p>
<span class="badge"><?= esc(ucfirst($user['role'])) ?> · Active account</span>
<dl>
    <?php foreach (['Student ID' => 'student_id', 'Institutional email' => 'email', 'Program' => 'program', 'Program code' => 'program_code', 'Specialization' => 'specialization', 'Department' => 'department'] as $label => $field): ?>
        <dt><?= esc($label) ?></dt><dd><?= esc($user[$field] ?: '—') ?></dd>
    <?php endforeach ?>
</dl>
<p class="muted fine">These identity details were verified at registration. Contact the Facilities Office if they need correction.</p>
<form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="secondary" type="submit">Log out</button></form>
<?= $this->endSection() ?>
