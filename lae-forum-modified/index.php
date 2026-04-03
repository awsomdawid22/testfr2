<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Load site settings
$siteSettings = [];
$settingsFile = __DIR__ . '/includes/settings.php';
if (file_exists($settingsFile)) {
    $siteSettings = include $settingsFile;
}
$siteName = $siteSettings['site_name'] ?? SITE_NAME;
$siteTagline = $siteSettings['site_tagline'] ?? 'FiveM Roleplay Community';
$fivemConnect = $siteSettings['fivem_connect'] ?? 'fivem://connect/play.laexperience.com';

$pageTitle = $siteName . ' | FiveM Community';
$currentUser = getCurrentUser();
$db = getDB();

$stats = getForumStats();

// Recent threads
$recentThreads = $db->query("
    SELECT t.*, u.username, u.avatar,
           c.name as cat_name, c.slug as cat_slug, c.color as cat_color
    FROM threads t
    JOIN users u ON t.user_id = u.id
    JOIN categories c ON t.category_id = c.id
    WHERE t.is_hidden = 0
    ORDER BY t.last_reply_at DESC, t.created_at DESC
    LIMIT 8
")->fetchAll();

// Online users (last 15 mins)
$onlineUsers = $db->query("
    SELECT u.username, u.avatar, r.color as role_color
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE u.last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ORDER BY u.last_seen DESC LIMIT 20
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="hero" style="background-image: linear-gradient(to bottom, rgba(5,5,5,0.80) 0%, rgba(5,5,5,0.62) 40%, rgba(5,5,5,0.88) 100%), url('<?= SITE_URL ?>/public/images/bg2.webp'); background-size: cover; background-position: center 30%;">
    <img src="<?= SITE_URL ?>/public/images/LARPWhite.png" alt="<?= e($siteName) ?>" class="hero-logo animate-fade-in">
    <p class="hero-sub animate-slide-up"><?= e($siteTagline) ?></p>
    <div class="server-status-badge animate-scale-in" id="serverStatus">
        <span class="status-indicator"></span>
        <span class="status-text">Checking server...</span>
        <span class="player-count"></span>
    </div>
    <div class="hero-actions">
        <?php if (!$currentUser): ?>
            <a href="<?= SITE_URL ?>/register.php" class="btn btn-accent btn-lg"><i class="fas fa-user-plus"></i> Join Community</a>
            <a href="<?= SITE_URL ?>/forum.php" class="btn btn-ghost btn-lg"><i class="fas fa-comments"></i> Browse Forums</a>
        <?php else: ?>
            <a href="<?= SITE_URL ?>/forum.php" class="btn btn-accent btn-lg animate-scale-in"><i class="fas fa-comments"></i> Browse Forums</a>
            <a href="<?= e($fivemConnect) ?>" class="btn btn-ghost btn-lg animate-scale-in" style="animation-delay:0.1s"><i class="fas fa-gamepad"></i> Connect to Server</a>
        <?php endif; ?>
    </div>
    <div class="hero-stats">
        <div class="hero-stat">
            <strong data-value="<?= $stats['members'] ?>"><?= number_format($stats['members']) ?></strong>
            <span>Members</span>
        </div>
        <div class="hero-stat">
            <strong data-value="<?= $stats['threads'] ?>"><?= number_format($stats['threads']) ?></strong>
            <span>Threads</span>
        </div>
        <div class="hero-stat">
            <strong data-value="<?= $stats['posts'] ?>"><?= number_format($stats['posts']) ?></strong>
            <span>Posts</span>
        </div>
        <div class="hero-stat">
            <strong><?= count($onlineUsers) ?></strong>
            <span>Online Now</span>
        </div>
    </div>
</section>

<div class="container">
    <div class="layout-with-sidebar">
        <div>
            <!-- Recent Activity -->
            <div class="card" style="margin-bottom:24px">
                <div class="card-header">
                    <h3><i class="fas fa-fire-flame-curved"></i> Recent Activity</h3>
                    <a href="<?= SITE_URL ?>/forum.php" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <?php foreach ($recentThreads as $thread): ?>
                <a href="<?= SITE_URL ?>/thread.php?id=<?= $thread['id'] ?>" class="activity-row">
                    <div class="activity-dot" style="background:<?= e($thread['cat_color']) ?>"></div>
                    <div class="activity-info">
                        <div class="activity-title"><?= e($thread['title']) ?></div>
                        <div class="activity-meta">
                            in <strong><?= e($thread['cat_name']) ?></strong> · by <?= e($thread['username']) ?> · <?= timeAgo($thread['last_reply_at'] ?? $thread['created_at']) ?>
                        </div>
                    </div>
                    <div class="activity-replies"><?= $thread['reply_count'] ?> repl<?= $thread['reply_count'] == 1 ? 'y' : 'ies' ?></div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($recentThreads)): ?>
                <div class="empty-state"><i class="fas fa-comments"></i><p>No threads yet. Be the first to post!</p></div>
                <?php endif; ?>
            </div>

            <!-- Quick Links -->
            <div class="quick-links-grid">
                <a href="<?= SITE_URL ?>/forum.php?cat=announcements" class="quick-link-card">
                    <div class="qlc-icon" style="background:rgba(230,57,70,0.12);color:var(--red)"><i class="fas fa-bullhorn"></i></div>
                    <div><div class="qlc-name">Announcements</div><div class="qlc-sub">Official updates</div></div>
                    <i class="fas fa-chevron-right qlc-arrow"></i>
                </a>
                <a href="<?= SITE_URL ?>/apply.php" class="quick-link-card">
                    <div class="qlc-icon" style="background:rgba(55,182,121,0.12);color:var(--green)"><i class="fas fa-file-alt"></i></div>
                    <div><div class="qlc-name">Apply for Staff</div><div class="qlc-sub">Join the team</div></div>
                    <i class="fas fa-chevron-right qlc-arrow"></i>
                </a>
                <a href="<?= SITE_URL ?>/rules.php" class="quick-link-card">
                    <div class="qlc-icon" style="background:rgba(201,162,39,0.12);color:var(--gold)"><i class="fas fa-scroll"></i></div>
                    <div><div class="qlc-name">Server Rules</div><div class="qlc-sub">Read before playing</div></div>
                    <i class="fas fa-chevron-right qlc-arrow"></i>
                </a>
                <a href="<?= SITE_URL ?>/appeal.php" class="quick-link-card">
                    <div class="qlc-icon" style="background:rgba(61,126,191,0.12);color:var(--blue)"><i class="fas fa-gavel"></i></div>
                    <div><div class="qlc-name">Ban Appeals</div><div class="qlc-sub">Appeal your ban</div></div>
                    <i class="fas fa-chevron-right qlc-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Sidebar -->
        <aside>
            <!-- Online users -->
            <div class="sidebar-widget">
                <div class="sidebar-title"><i class="fas fa-circle online-dot" style="width:8px;height:8px;margin-right:6px"></i> Online (<?= count($onlineUsers) ?>)</div>
                <?php foreach (array_slice($onlineUsers, 0, 10) as $ou): ?>
                <div class="sidebar-item">
                    <div style="display:flex;align-items:center;gap:8px">
                        <img src="<?= e(getAvatarUrl($ou['avatar'], $ou['username'])) ?>" style="width:26px;height:26px;border-radius:50%">
                        <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($ou['username']) ?>" style="color:<?= e($ou['role_color']) ?>;text-decoration:none;font-size:0.85rem;font-weight:600"><?= e($ou['username']) ?></a>
                    </div>
                    <span class="online-dot"></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($onlineUsers)): ?>
                <div style="padding:16px;font-size:0.82rem;color:var(--t2);text-align:center">No users online right now</div>
                <?php endif; ?>
            </div>

            <!-- Server status -->
            <div class="sidebar-widget" id="sidebarServerStatus">
                <div class="sidebar-title"><i class="fas fa-server"></i> &nbsp; Server Status</div>
                <div style="padding:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <span style="font-size:0.85rem;color:var(--t2)">Main Server</span>
                        <span class="sidebar-server-status" style="font-size:0.82rem;font-weight:700">
                            <i class="fas fa-spinner fa-spin" style="font-size:0.5rem"></i> Checking...
                        </span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <span style="font-size:0.85rem;color:var(--t2)">Players</span>
                        <span class="sidebar-player-count" style="font-size:0.82rem;font-weight:700;color:var(--t1)">--/--</span>
                    </div>
                    <div class="player-list-preview" style="margin-bottom:12px;max-height:120px;overflow-y:auto;display:none">
                        <!-- Player list will be populated by JS -->
                    </div>
                    <a href="<?= e($fivemConnect) ?>" class="btn btn-accent" style="width:100%;justify-content:center;font-size:0.82rem"><i class="fas fa-play"></i> Connect Now</a>
                </div>
            </div>

            <!-- Newest members -->
            <div class="sidebar-widget">
                <div class="sidebar-title">New Members</div>
                <?php 
                $newest = $db->query("SELECT u.id, u.username, u.avatar, r.color as role_color FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.created_at DESC LIMIT 5")->fetchAll();
                foreach ($newest as $m): ?>
                <div class="sidebar-item">
                    <div style="display:flex;align-items:center;gap:8px">
                        <img src="<?= e(getAvatarUrl($m['avatar'], $m['username'])) ?>" style="width:24px;height:24px;border-radius:50%">
                        <a href="<?= SITE_URL ?>/profile.php?user=<?= $m['id'] ?>" style="color:var(--t1);text-decoration:none;font-size:0.83rem"><?= e($m['username']) ?></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
