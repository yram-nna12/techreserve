<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<dl>
    <dt>Type</dt><dd><?= esc(ucfirst($resource['resource_type'])) ?></dd>
    <dt>Description</dt><dd class="description"><?= esc($resource['description'] ?: 'No description provided.') ?></dd>
    <dt>Location</dt><dd><?= esc($resource['location'] ?: 'Not specified') ?></dd>
    <dt>Quantity</dt><dd><?= (int) $resource['quantity'] ?></dd>
    <dt>Facility capacity</dt><dd><?= $resource['capacity'] === null ? 'Not specified / not applicable' : (int) $resource['capacity'] . ' people' ?></dd>
    <dt>Hourly rate per unit</dt><dd><?= esc($resource['rate_per_hour']) ?> / hour</dd>
    <dt>Operational status</dt><dd><?= esc(ucfirst($resource['status'])) ?></dd>
</dl>
<p>Operational status does not guarantee availability for a particular time slot.</p>
<?php if ($admin): ?><p><a class="button" href="<?= site_url('admin/resources/' . $resource['id'] . '/edit') ?>">Edit resource</a> <a href="<?= site_url('admin/resources/' . $resource['id'] . '/archive') ?>">Archive resource</a></p><?php endif ?>
<p><a href="<?= site_url(($admin ? 'admin' : 'borrower') . '/resources') ?>">Back to resources</a></p>
<?= $this->endSection() ?>
