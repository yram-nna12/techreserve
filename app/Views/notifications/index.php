<?= $this->extend('resources/layout') ?>
<?= $this->section('content') ?>
<p><?= (int) $unreadCount ?> unread notification(s).</p>
<?php if (!$notificationWritesEnabled): ?>
    <p class="alert" role="status">Notification changes are disabled pending database approval. This page is read-only.</p>
<?php elseif ($unreadCount > 0): ?>
    <form method="post" action="<?= site_url('notifications/read-all') ?>" class="notification-actions">
        <?= csrf_field() ?><button type="submit" class="secondary">Mark all as read</button>
    </form>
<?php endif ?>

<?php if (!$notifications): ?>
    <p class="empty">You have no notifications.</p>
<?php else: ?>
    <div class="notification-list">
        <?php foreach ($notifications as $notification): ?>
            <?php $read = (bool) $notification['is_read']; ?>
            <article class="notification-item<?= $read ? ' is-read' : ' is-unread' ?>">
                <div class="notification-heading">
                    <h2><?= esc($notification['title']) ?></h2>
                    <span class="notification-state"><?= $read ? 'Read' : 'Unread' ?></span>
                </div>
                <p><?= esc($notification['message']) ?></p>
                <time datetime="<?= esc($notification['created_at'] ?? '', 'attr') ?>">
                    <?= esc($notification['created_at'] ?? 'Date unavailable') ?>
                </time>
                <?php if ($notification['reservation_id'] !== null): ?>
                    <p><a href="<?= site_url(($admin ? 'admin' : 'borrower') . '/reservations/' . (int) $notification['reservation_id']) ?>">View reservation</a></p>
                <?php endif ?>
                <?php if (!$read && $notificationWritesEnabled): ?>
                    <form method="post" action="<?= site_url('notifications/' . (int) $notification['id'] . '/read') ?>">
                        <?= csrf_field() ?><button type="submit" class="secondary">Mark as read</button>
                    </form>
                <?php endif ?>
            </article>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
