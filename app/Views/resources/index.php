<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<p>Hourly rates are listed per resource unit. Matching equipment is grouped in the borrower catalog; admins retain each inventory record and identifier.</p>
<?php if ($admin): ?><p><a class="button" href="<?= site_url('admin/resources/new') ?>">Create resource</a></p><?php endif ?>
<?php if (!$admin): ?><p><a class="button" href="<?= site_url('borrower/reservations/new') ?>">Create reservation</a></p><?php endif ?>
<form method="get" action="<?= site_url($admin ? 'admin/resources' : 'borrower/resources') ?>" class="filters">
    <label>Search<input type="search" name="q" maxlength="150" value="<?= esc($filters['q'], 'attr') ?>" placeholder="Name, description, or location"></label>
    <label>Type<select name="resource_type"><option value="">All types</option><?php foreach (['facility', 'equipment'] as $type): ?><option value="<?= $type ?>" <?= $filters['resource_type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option><?php endforeach ?></select></label>
    <label>Category<input type="search" name="category" maxlength="100" value="<?= esc($filters['category'], 'attr') ?>"></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['available', 'unavailable', 'maintenance'] as $status): ?><option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach ?></select></label>
    <button type="submit">Filter</button><a href="<?= site_url($admin ? 'admin/resources' : 'borrower/resources') ?>">Clear</a>
</form>
<?php if ($resources === []): ?><p class="empty">No resources match these filters.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><?php if ($admin): ?><th>Record</th><?php endif ?><th>Name</th><th>Category</th><th>Type</th><th>Location</th><th>Quantity / capacity</th><th>Hourly rate</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($resources as $resource): ?>
<tr data-resource-id="<?= (int) $resource['id'] ?>">
<?php if ($admin): ?><td>#<?= (int) $resource['id'] ?><?php if (!empty($resource['inventory_identifier'])): ?><br><?= esc($resource['inventory_identifier']) ?><?php endif ?></td><?php endif ?>
<td><?= esc($resource['name']) ?></td><td><?= esc($resource['category'] ?? 'General') ?></td><td><?= esc(ucfirst($resource['resource_type'])) ?></td>
    <td><?= esc($resource['location'] ?: 'Not specified') ?></td>
<td><?= (int) $resource['quantity'] ?> <?= $resource['resource_type'] === 'facility' ? 'space' : 'units' ?><?php if ($resource['resource_type'] === 'equipment' && !$admin && ($resource['inventory_count'] ?? 1) > 1): ?> across <?= (int) $resource['inventory_count'] ?> records<?php endif ?><?php if ($resource['capacity'] !== null): ?> / <?= (int) $resource['capacity'] ?> people<?php endif ?></td>
    <td><?= esc($resource['rate_per_hour']) ?> / hour</td><td><span class="status-badge <?= esc($resource['status'], 'attr') ?>"><?= esc(ucfirst($resource['status'])) ?></span></td>
    <td><a href="<?= site_url(($admin ? 'admin' : 'borrower') . '/resources/' . $resource['id']) ?>">View</a><?php if ($admin): ?> <a href="<?= site_url('admin/resources/' . $resource['id'] . '/edit') ?>">Edit</a><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?= $pager->links() ?>
<?php endif ?>
<?= $this->endSection() ?>
