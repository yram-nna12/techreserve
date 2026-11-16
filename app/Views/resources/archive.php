<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<p>Archive <strong><?= esc($resource['name']) ?></strong>?</p>
<p>It will disappear from the catalog and management lists. Its database record and any reservation references will remain stored.</p>
<form method="post" action="<?= site_url('admin/resources/' . $resource['id'] . '/archive') ?>">
    <?= csrf_field() ?>
    <label class="checkbox"><input type="checkbox" name="confirm_archive" value="yes" required> I confirm that this resource should be archived.</label>
    <p><button type="submit" class="danger">Archive resource</button> <a href="<?= site_url('admin/resources/' . $resource['id']) ?>">Cancel</a></p>
</form>
<?= $this->endSection() ?>
