<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<?php if ($errors): ?><div class="alert error" role="alert"><strong>Please correct these fields:</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<form method="post" action="<?= site_url(isset($resource['id']) ? 'admin/resources/' . $resource['id'] . '/edit' : 'admin/resources') ?>" class="resource-form">
    <?= csrf_field() ?>
    <label for="name">Name</label><input id="name" name="name" required maxlength="150" value="<?= esc($resource['name'] ?? '', 'attr') ?>">
    <label for="description">Description</label><textarea id="description" name="description" rows="4" maxlength="5000"><?= esc($resource['description'] ?? '') ?></textarea>
    <label for="resource_type">Resource type</label><select id="resource_type" name="resource_type" required><?php foreach (['facility', 'equipment'] as $type): ?><option value="<?= $type ?>" <?= ($resource['resource_type'] ?? '') === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option><?php endforeach ?></select>
    <label for="location">Location</label><input id="location" name="location" maxlength="150" value="<?= esc($resource['location'] ?? '', 'attr') ?>">
    <label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" required min="1" max="4294967295" step="1" value="<?= esc((string) ($resource['quantity'] ?? '1'), 'attr') ?>"><small>Exactly 1 for a facility; interchangeable stock count for equipment.</small>
    <div id="capacity-field" <?= ($resource['resource_type'] ?? '') === 'equipment' ? 'hidden' : '' ?>>
        <label for="capacity">Facility capacity (optional)</label><input id="capacity" name="capacity" type="number" min="1" max="4294967295" step="1" <?= ($resource['resource_type'] ?? '') === 'equipment' ? 'disabled' : '' ?> value="<?= esc((string) ($resource['capacity'] ?? ''), 'attr') ?>"><small>Number of people. If entered, use a positive integer.</small>
    </div>
    <label for="rate_per_hour">Rate per hour, per unit</label><input id="rate_per_hour" name="rate_per_hour" type="number" required min="0" max="99999999.99" step="0.01" value="<?= esc((string) ($resource['rate_per_hour'] ?? '0.00'), 'attr') ?>"><small>Use up to two decimal places. A rate of 0.00 is allowed.</small>
    <label for="status">Operational status</label><select id="status" name="status" required><?php foreach (['available', 'unavailable', 'maintenance'] as $status): ?><option value="<?= $status ?>" <?= ($resource['status'] ?? '') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach ?></select>
    <p><button type="submit">Save resource</button> <a href="<?= site_url('admin/resources') ?>">Cancel</a></p>
</form>
<script src="<?= base_url('js/resource-form.js') ?>" defer></script>
<?= $this->endSection() ?>
