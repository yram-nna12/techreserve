<?= $this->extend('resources/layout') ?>
<?= $this->section('styles') ?><link rel="stylesheet" href="<?= base_url('css/borrower-resource-detail.css?v=1') ?>"><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $facility = $resource['resource_type'] === 'facility'; ?>
<div class="student-resource-detail">
    <a class="student-resource-back" href="<?= site_url('borrower/resources') ?>"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Back to Resources</a>
    <header class="student-resource-heading">
        <span class="student-resource-icon material-symbols-outlined" aria-hidden="true"><?= $facility ? 'meeting_room' : 'inventory_2' ?></span>
        <div><h1><?= esc($resource['name']) ?></h1><div class="student-resource-meta"><span><?= esc(ucfirst($resource['resource_type'])) ?></span><span><?= esc($resource['location'] ?: 'Location not specified') ?></span><span class="status-badge <?= esc($resource['status'], 'attr') ?>"><?= esc(ucfirst($resource['status'])) ?></span></div></div>
    </header>
    <div class="student-resource-summary">
        <section class="student-resource-card"><h2>Hourly rate per unit</h2><p class="student-resource-value">&#8369;<?= esc(number_format((float) $resource['rate_per_hour'], 2)) ?> <small>/ hour</small></p></section>
        <section class="student-resource-card"><h2><?= $facility ? 'Facility capacity' : 'Equipment quantity' ?></h2><p class="student-resource-value"><?= $facility ? ($resource['capacity'] === null ? 'Not specified' : (int) $resource['capacity'] . ' people') : (int) $resource['quantity'] . ' units' ?></p></section>
    </div>
    <section class="student-resource-card student-resource-information">
        <h2>Resource information</h2>
        <dl><div><dt>Type</dt><dd><?= esc(ucfirst($resource['resource_type'])) ?></dd></div><div><dt>Category</dt><dd><?= esc($resource['category'] ?: 'General') ?></dd></div><div><dt>Location</dt><dd><?= esc($resource['location'] ?: 'Not specified') ?></dd></div><div><dt>Quantity</dt><dd><?= (int) $resource['quantity'] ?> <?= $facility ? 'space' : 'units' ?></dd></div><?php if (!$facility && !empty($resource['catalog_group'])): ?><div><dt>Catalog group</dt><dd><?= esc($resource['catalog_group']) ?></dd></div><?php endif ?><div class="student-resource-description"><dt>Description</dt><dd><?= esc($resource['description'] ?: 'No description provided.') ?></dd></div></dl>
    </section>
    <p class="student-resource-note"><span class="material-symbols-outlined" aria-hidden="true">info</span> Operational status does not guarantee availability for a particular time slot.</p>
    <a class="button" href="<?= site_url('borrower/reservations/new') ?>">Create reservation</a>
</div>
<?= $this->endSection() ?>
