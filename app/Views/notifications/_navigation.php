<?php
$notificationUserId = (int) session()->get('user_id');
if ($notificationUserId > 0):
    $notificationUnreadCount = (new \App\Services\NotificationService())->unreadCount($notificationUserId);
?>
    <a class="notification-nav" href="<?= site_url('notifications') ?>" aria-label="Notifications, <?= $notificationUnreadCount ?> unread">
        <span aria-hidden="true">&#128276;</span> Notifications
        <?php if ($notificationUnreadCount > 0): ?><span class="notification-count"><?= $notificationUnreadCount ?></span><?php endif ?>
    </a>
<?php endif ?>
