<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Admin Panel · LAE';

// Stats
$stats = [
    ['label' => 'Total Members', 'value' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(), 'icon' => 'fa-users', 'color' => 'var(--blue)'],
    ['label' => 'Threads', 'value' => $db->query("SELECT COUNT(*) FROM threads")->fetchColumn(), 'icon' => 'fa-comments', 'color' => 'var(--accent)'],
    ['label' => 'Posts', 'value' => $db->query("SELECT COUNT(*) FROM posts")->fetchColumn(), 'icon' => 'fa-message', 'color' => 'var(--green)'],
    ['label' => 'Banned Users', 'value' => $db->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn(), 'icon' => 'fa-ban', 'color' => 'var(--red)'],
    ['label' => 'Pending Apps', 'value' => $db->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn(), 'icon' => 'fa-file-alt', 'color' => 'var(--gold)'],
    ['label' => 'Pending Appeals', 'value' => $db->query("SELECT COUNT(*) FROM ban_appeals WHERE status = 'pending'")->fetchColumn(), 'icon' => 'fa-gavel', 'color' => 'var(--purple)'],
    ['label' => 'New Today', 'value' => $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn(), 'icon' => 'fa-user-plus', 'color' => 'var(--green)'],
    ['label' => 'Online Now', 'value' => $db->query("SELECT COUNT(*) FROM users WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn(), 'icon' => 'fa-circle', 'color' => 'var(--green)'],
];

// Recent audit log
$audit = $db->query("
    SELECT al.*, u.username 
    FROM audit_log al LEFT JOIN users u ON al.user_id = u.id 
    ORDER BY al.created_at DESC LIMIT 15
")->fetchAll();

// Recent registrations
$newUsers = $db->query("
    SELECT u.*, r.display_name as role_display, r.color as role_color 
    FROM users u JOIN roles r ON u.role_id = r.id 
    ORDER BY u.created_at DESC LIMIT 8
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-header">
            <div>
                <h1><i class="fas fa-gauge" style="color:var(--red)"></i> Dashboard</h1>
                <p style="color:var(--t2);font-size:0.85rem;margin-top:4px">Los Angeles Experience Admin Panel</p>
            </div>
            <div style="font-size:0.82rem;color:var(--t2)">
                <i class="fas fa-clock"></i> <?= date('D, M j Y · g:i A') ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <?php foreach ($stats as $stat): ?>
            <div class="stat-card">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div class="stat-label"><?= e($stat['label']) ?></div>
                    <i class="fas <?= $stat['icon'] ?>" style="color:<?= $stat['color'] ?>;font-size:1.2rem;opacity:0.7"></i>
                </div>
                <div class="stat-number" style="color:<?= $stat['color'] ?>" data-value="<?= $stat['value'] ?>"><?= number_format($stat['value']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

            <!-- Recent Users -->
            <div>
                <h3 style="font-family:var(--font-head);letter-spacing:2px;font-size:1rem;margin-bottom:12px;color:var(--t1)">RECENT REGISTRATIONS</h3>
                <table class="admin-table">
                    <thead>
                        <tr><th>User</th><th>Role</th><th>Joined</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($newUsers as $u): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <img src="<?= e(getAvatarUrl($u['avatar'], $u['username'])) ?>" style="width:28px;height:28px;border-radius:50%">
                                    <a href="<?= SITE_URL ?>/profile.php?user=<?= $u['id'] ?>" style="color:var(--t0);text-decoration:none;font-weight:600"><?= e($u['username']) ?></a>
                                </div>
                            </td>
                            <td><?= getRoleBadge($u) ?></td>
                            <td style="color:var(--t2);font-size:0.8rem"><?= timeAgo($u['created_at']) ?></td>
                            <td>
                                <a href="<?= SITE_URL ?>/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-ghost btn-sm" style="margin-top:10px">View All Users →</a>
            </div>

            <!-- Audit Log -->
            <div>
                <h3 style="font-family:var(--font-head);letter-spacing:2px;font-size:1rem;margin-bottom:12px;color:var(--t1)">RECENT ACTIVITY LOG</h3>
                <div class="card">
                    <?php foreach ($audit as $log): ?>
                    <div style="padding:10px 14px;border-bottom:1px solid var(--b0);font-size:0.82rem">
                        <div style="display:flex;justify-content:space-between">
                            <span>
                                <span style="color:var(--red);font-weight:700"><?= e($log['username'] ?? 'System') ?></span>
                                · <span style="color:var(--t1)"><?= e($log['action']) ?></span>
                            </span>
                            <span style="color:var(--t2)"><?= timeAgo($log['created_at']) ?></span>
                        </div>
                        <?php if ($log['details']): ?>
                        <div style="color:var(--t2);margin-top:2px"><?= e(substr($log['details'], 0, 80)) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="<?= SITE_URL ?>/admin/audit.php" class="btn btn-ghost btn-sm" style="margin-top:10px">Full Audit Log →</a>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
