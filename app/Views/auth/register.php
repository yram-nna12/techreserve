<?= $this->extend('auth/layout') ?>
<?= $this->section('content') ?>
<p class="muted">For currently enrolled students. Your identity details are securely verified with Student Records.</p>
<form method="post" action="<?= site_url('register') ?>">
    <?= csrf_field() ?>
    <label for="student_id">Student ID</label>
    <input id="student_id" name="student_id" required maxlength="30" pattern="[A-Za-z0-9-]+" autocomplete="username" value="<?= esc(session()->getFlashdata('student_id') ?? '', 'attr') ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help">
    <small id="password-help">Use at least 12 characters (maximum 72 bytes). Choose a unique password.</small>
    <label for="password_confirm">Confirm password</label>
    <input id="password_confirm" name="password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
    <button type="submit">Create student account</button>
</form>
<p class="switch">Already registered? <a href="<?= site_url('login') ?>">Log in</a></p>
<p class="muted fine">Facilities Office accounts are provisioned separately. Administrator registration is not available here.</p>
<?= $this->endSection() ?>
