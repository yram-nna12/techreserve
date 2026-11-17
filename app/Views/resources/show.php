<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<dl>
    <?php if ($admin): ?><dt>Record</dt><dd>#<?= (int) $resource['id'] ?><?php if (!empty($resource['inventory_identifier'])): ?> — <?= esc($resource['inventory_identifier']) ?><?php endif ?></dd><?php endif ?>
    <dt>Type</dt><dd><?= esc(ucfirst($resource['resource_type'])) ?></dd>
    <dt>Category</dt><dd><?= esc($resource['category'] ?? 'General') ?></dd>
    <?php if ($resource['resource_type'] === 'equipment' && !empty($resource['catalog_group'])): ?><dt>Catalog group</dt><dd><?= esc($resource['catalog_group']) ?></dd><?php endif ?>
    <?php if ($admin && !empty($resource['conflict_group'])): ?><dt>Scheduling conflict groups</dt><dd><?= esc(implode(', ', array_filter([$resource['conflict_group'], $resource['conflict_group_alt'] ?? null]))) ?></dd><?php endif ?>
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
