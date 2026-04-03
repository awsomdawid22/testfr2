<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();
$db = getDB();

// Load profile
$userId = $_GET['user'] ?? null;
if (ctype_digit((string)$userId)) {
    $stmt = $db->prepare("SELECT u.*, r.display_name as role_display, r.color as role_color, r.badge_color, r.name as role_name, r.priority as role_priority, r.can_moderate, r.can_admin, r.animation as role_animation FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([(int)$userId]);
} else {
    $stmt = $db->prepare("SELECT u.*, r.display_name as role_display, r.color as role_color, r.badge_color, r.name as role_name, r.priority as role_priority, r.can_moderate, r.can_admin, r.animation as role_animation FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
    $stmt->execute([$userId]);
}
$profile = $stmt->fetch();
if (!$profile) { header('Location: ' . SITE_URL . '/?error=user_not_found'); exit; }

$pageTitle = $profile['username'] . ' · Profile';

// Extra roles (multi-role support)
$extraRoles = [];
if (!empty($profile['extra_roles'])) {
    $extraIds = json_decode($profile['extra_roles'], true);
    if (is_array($extraIds) && count($extraIds)) {
        $ph = implode(',', array_fill(0, count($extraIds), '?'));
        $rStmt = $db->prepare("SELECT * FROM roles WHERE id IN ($ph) ORDER BY priority DESC");
        $rStmt->execute($extraIds);
        $extraRoles = $rStmt->fetchAll();
    }
}

// Threads
$threads = $db->prepare("
    SELECT t.*, c.name as cat_name, c.slug as cat_slug, c.color as cat_color
    FROM threads t JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ? AND t.is_hidden = 0
    ORDER BY t.created_at DESC LIMIT 20
");
$threads->execute([$profile['id']]);
$threads = $threads->fetchAll();

// Posts
$posts = $db->prepare("
    SELECT p.*, t.title as thread_title, t.id as thread_id, c.name as cat_name, c.color as cat_color
    FROM posts p
    JOIN threads t ON p.thread_id = t.id
    JOIN categories c ON t.category_id = c.id
    WHERE p.user_id = ? AND p.is_hidden = 0
    ORDER BY p.created_at DESC LIMIT 20
");
$posts->execute([$profile['id']]);
$posts = $posts->fetchAll();

$isOnline = $profile['last_seen'] && strtotime($profile['last_seen']) > time() - 900;
$isOwnProfile = $currentUser && $currentUser['id'] === $profile['id'];

// Banner URL
$bannerUrl = null;
if (!empty($profile['banner'])) {
    $bannerUrl = SITE_URL . '/' . ltrim($profile['banner'], '/');
}

include __DIR__ . '/includes/header.php';
?>

<div class="profile-page">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'banner_updated'): ?>
    <div class="container" style="padding-bottom:0">
        <div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> Profile banner updated successfully!</div>
    </div>
    <?php endif; ?>

    <!-- Banner -->
    <div class="profile-banner" style="<?= $bannerUrl ? "background-image:url('" . e($bannerUrl) . "')" : 'background:linear-gradient(135deg,var(--bg1),var(--bg3))' ?>">
        <?php if ($isOwnProfile): ?>
        <form method="POST" action="<?= SITE_URL ?>/settings.php" enctype="multipart/form-data" id="bannerForm" style="position:absolute;bottom:12px;right:16px;z-index:2">
            <input type="hidden" name="action" value="upload_banner">
            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
            <label class="banner-upload-btn" title="Change banner">
                <i class="fas fa-image"></i> Change Banner
                <input type="file" name="banner" accept="image/jpeg,image/png,image/webp" style="display:none"
                       onchange="document.getElementById('bannerForm').submit()">
            </label>
        </form>
        <?php endif; ?>
    </div>

    <div class="container profile-container">

        <!-- Profile header card -->
        <div class="profile-header-card">
            <!-- Avatar -->
            <div class="profile-avatar-wrap">
                <img src="<?= e(getAvatarUrl($profile['avatar'], $profile['username'])) ?>"
                     alt="<?= e($profile['username']) ?>"
                     class="profile-avatar-img">
                <?php if ($isOnline): ?>
                    <span class="profile-online-dot" title="Online now"></span>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="profile-info">
                <div class="profile-name-row">
                    <h1 class="profile-username"><?= e($profile['username']) ?></h1>
                    <?php if ($profile['is_banned']): ?>
                        <span class="thread-tag tag-locked"><i class="fas fa-ban"></i> Banned</span>
                    <?php endif; ?>
                </div>

                <!-- Primary role badge -->
                <div class="profile-roles">
                    <?= getRoleBadge($profile) ?>
                    <?php foreach ($extraRoles as $er): ?>
                        <?= getRoleBadge($er) ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($profile['bio']): ?>
                    <p class="profile-bio"><?= e($profile['bio']) ?></p>
                <?php endif; ?>

                <div class="profile-meta-row">
                    <span><i class="fas fa-comments" style="color:var(--red)"></i> <?= number_format($profile['post_count']) ?> posts</span>
                    <span><i class="fas fa-layer-group" style="color:var(--t2)"></i> <?= count($threads) ?> threads</span>
                    <span><i class="fas fa-calendar" style="color:var(--t2)"></i> Joined <?= date('M Y', strtotime($profile['created_at'])) ?></span>
                    <?php if ($profile['last_seen']): ?>
                        <span>
                            <i class="fas fa-clock" style="color:var(--t2)"></i>
                            <?= $isOnline ? '<span style="color:var(--green)">Online now</span>' : 'Last seen ' . timeAgo($profile['last_seen']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($profile['discord_id']): ?>
                        <span style="color:#5865f2"><i class="fab fa-discord"></i> Discord linked</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="profile-actions">
                <?php if ($isOwnProfile): ?>
                    <a href="<?= SITE_URL ?>/settings.php" class="btn btn-ghost"><i class="fas fa-gear"></i> Edit Profile</a>
                <?php else: ?>
                    <?php if ($currentUser): ?>
                        <a href="<?= SITE_URL ?>/messages.php?to=<?= urlencode($profile['username']) ?>" class="btn btn-ghost"><i class="fas fa-envelope"></i> Message</a>
                    <?php endif; ?>
                    <?php if ($currentUser && $currentUser['can_moderate']): ?>
                        <a href="<?= SITE_URL ?>/admin/moderation.php?search=<?= urlencode($profile['username']) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-shield-halved"></i> Mod History</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs -->
        <div class="profile-tabs">
            <button class="ptab active" data-panel="panel-threads">
                <i class="fas fa-comments"></i> Threads <span class="ptab-count"><?= count($threads) ?></span>
            </button>
            <button class="ptab" data-panel="panel-posts">
                <i class="fas fa-message"></i> Posts <span class="ptab-count"><?= count($posts) ?></span>
            </button>
        </div>

        <!-- Threads panel -->
        <div id="panel-threads" class="ptab-panel active">
            <?php if (empty($threads)): ?>
                <div class="card"><div class="empty-state"><i class="fas fa-comment-slash"></i><p>No threads yet.</p></div></div>
            <?php else: ?>
            <div class="card">
                <?php foreach ($threads as $t): ?>
                <a href="<?= SITE_URL ?>/thread.php?id=<?= $t['id'] ?>" class="profile-thread-row">
                    <div class="ptr-cat-dot" style="background:<?= e($t['cat_color']) ?>"></div>
                    <div class="ptr-body">
                        <div class="ptr-title"><?= e($t['title']) ?></div>
                        <div class="ptr-meta">
                            in <span style="color:<?= e($t['cat_color']) ?>;font-weight:600"><?= e($t['cat_name']) ?></span>
                            · <?= timeAgo($t['created_at']) ?>
                        </div>
                    </div>
                    <div class="ptr-stats">
                        <div><?= $t['reply_count'] ?> <span>replies</span></div>
                        <div><?= number_format($t['views']) ?> <span>views</span></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Posts panel -->
        <div id="panel-posts" class="ptab-panel" style="display:none">
            <?php if (empty($posts)): ?>
                <div class="card"><div class="empty-state"><i class="fas fa-message-slash"></i><p>No posts yet.</p></div></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($posts as $p): ?>
                <div class="card profile-post-card">
                    <div class="ppc-header">
                        <div class="ppc-cat-dot" style="background:<?= e($p['cat_color']) ?>"></div>
                        <a href="<?= SITE_URL ?>/thread.php?id=<?= $p['thread_id'] ?>#post-<?= $p['id'] ?>" class="ppc-thread-title">
                            <?= e($p['thread_title']) ?>
                        </a>
                        <span class="ppc-meta">in <?= e($p['cat_name']) ?> · <?= timeAgo($p['created_at']) ?></span>
                    </div>
                    <div class="ppc-content">
                        <?= sanitizePost(mb_substr($p['content'], 0, 400)) ?><?= mb_strlen($p['content']) > 400 ? '…' : '' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /container -->
</div><!-- /profile-page -->

<script>
// Profile tab switching
document.querySelectorAll('.ptab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.ptab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ptab-panel').forEach(p => p.style.display = 'none');
        tab.classList.add('active');
        const panel = document.getElementById(tab.dataset.panel);
        if (panel) panel.style.display = 'block';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
