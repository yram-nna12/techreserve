<?= $this->extend('auth/layout') ?>
<?= $this->section('content') ?>
<p class="muted">Log in with your student ID and TechReserve password.</p>
<form method="post" action="<?= site_url('login') ?>">
    <?= csrf_field() ?>
    <label for="student_id">Student ID</label>
    <input id="student_id" name="student_id" required maxlength="30" autocomplete="username" value="<?= esc(session()->getFlashdata('student_id') ?? '', 'attr') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required maxlength="72" autocomplete="current-password">
    <button type="submit">Log in</button>
</form>
<p class="switch">New to TechReserve? <a href="<?= site_url('register') ?>">Create a student account</a></p>
<?= $this->endSection() ?>
