<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Notifications · LAE Forums';

// Mark all as read
if (isset($_GET['mark_all'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$currentUser['id']]);
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$totalStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$totalStmt->execute([$currentUser['id']]);
$total = (int)$totalStmt->fetchColumn();
$pag = paginate($total, 20, $page);

$notifs = $db->prepare("
    SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?
");
$notifs->execute([$currentUser['id'], $pag['per_page'], $pag['offset']]);
$notifs = $notifs->fetchAll();

// Mark fetched as read
$db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$currentUser['id']]);

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:800px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <h1 style="font-family:var(--font-head);font-size:2rem;letter-spacing:3px">
            <i class="fas fa-bell" style="color:var(--red)"></i> NOTIFICATIONS
        </h1>
        <a href="?mark_all=1" class="btn btn-ghost btn-sm"><i class="fas fa-check-double"></i> Mark all read</a>
    </div>

    <div class="card">
        <?php if (empty($notifs)): ?>
            <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
        <?php else: ?>
            <?php foreach ($notifs as $notif): ?>
            <div style="padding:14px 20px;border-bottom:1px solid var(--b0);display:flex;gap:14px;align-items:flex-start;<?= !$notif['is_read'] ? 'background:rgba(233,30,140,0.04)' : '' ?>">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--bg1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fas fa-<?= $notif['type'] === 'reply' ? 'reply' : ($notif['type'] === 'like' ? 'heart' : 'bell') ?>" style="color:var(--red)"></i>
                </div>
                <div style="flex:1">
                    <?php if ($notif['title']): ?>
                        <div style="font-weight:700;font-size:0.9rem;margin-bottom:3px"><?= e($notif['title']) ?></div>
                    <?php endif; ?>
                    <div style="color:var(--t0);font-size:0.85rem"><?= e($notif['content'] ?? '') ?></div>
                    <div style="font-size:0.75rem;color:var(--t1);margin-top:4px"><?= timeAgo($notif['created_at']) ?></div>
                </div>
                <?php if ($notif['link']): ?>
                    <a href="<?= e($notif['link']) ?>" class="btn btn-ghost btn-sm">View</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?= renderPagination($pag, SITE_URL . '/notifications.php') ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
