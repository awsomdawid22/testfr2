<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();
$db = getDB();
$catSlug = $_GET['cat'] ?? null;

// ── Strip BBCode for preview snippets ──────────────────────────
function threadSnippet(?string $raw, int $len = 140): string {
    if (!$raw) return '';
    $text = preg_replace('/\[[^\]]*\]/', '', $raw);
    $text = html_entity_decode(strip_tags($text));
    $text = preg_replace('/\s+/', ' ', trim($text));
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}

if ($catSlug) {
    /* ── Thread list view ─────────────────────────────────────── */
    $cat = $db->prepare("SELECT * FROM categories WHERE slug = ? AND is_visible = 1");
    $cat->execute([$catSlug]);
    $cat = $cat->fetch();
    if (!$cat) { header('Location: ' . SITE_URL . '/forum.php'); exit; }

    $pageTitle = $cat['name'] . ' · LAE Forums';
    $page = max(1, (int)($_GET['page'] ?? 1));

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM threads WHERE category_id = ? AND is_hidden = 0");
    $cntStmt->execute([$cat['id']]);
    $total = (int)$cntStmt->fetchColumn();
    $pag   = paginate($total, THREADS_PER_PAGE, $page);

    $threads = $db->prepare("
        SELECT t.*,
               u.username  AS author,      u.avatar AS author_avatar,
               r.color     AS author_color,
               lu.username AS last_user,   lu.avatar AS last_avatar,
               lr.color    AS last_color,
               p.content   AS preview_raw
        FROM threads t
        JOIN  users u   ON t.user_id            = u.id
        JOIN  roles r   ON u.role_id             = r.id
        LEFT JOIN users lu  ON t.last_reply_user_id = lu.id
        LEFT JOIN roles lr  ON lu.role_id            = lr.id
        LEFT JOIN posts p   ON p.id = (
            SELECT id FROM posts WHERE thread_id = t.id AND is_hidden = 0
            ORDER BY id ASC LIMIT 1
        )
        WHERE t.category_id = ? AND t.is_hidden = 0
        ORDER BY t.is_pinned DESC, COALESCE(t.last_reply_at, t.created_at) DESC
        LIMIT ? OFFSET ?
    ");
    $threads->execute([$cat['id'], $pag['per_page'], $pag['offset']]);
    $threads = $threads->fetchAll();

    // App / appeal statuses
    $appSt = []; $appealSt = [];
    if (in_array($cat['slug'], ['applications','appeals'])) {
        $ids = array_filter(array_column($threads, 'id'));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($cat['slug'] === 'applications') {
                $s = $db->prepare("SELECT t.id as tid, a.id as aid, a.status FROM threads t JOIN applications a ON t.application_id=a.id WHERE t.id IN ($ph)");
                $s->execute($ids);
                foreach ($s->fetchAll() as $row) $appSt[$row['tid']] = $row;
            } else {
                $s = $db->prepare("SELECT t.id as tid, ba.id as aid, ba.status FROM threads t JOIN ban_appeals ba ON t.appeal_id=ba.id WHERE t.id IN ($ph)");
                $s->execute($ids);
                foreach ($s->fetchAll() as $row) $appealSt[$row['tid']] = $row;
            }
        }
    }

} else {
    /* ── Category index ────────────────────────────────────────── */
    $pageTitle = 'Forums · LAE';
    $categories = $db->query("
        SELECT c.*,
               t.title      AS last_title,
               t.id         AS last_tid,
               t.created_at AS last_date,
               u.username   AS last_user,
               u.avatar     AS last_avatar
        FROM categories c
        LEFT JOIN threads t ON t.id = (
            SELECT id FROM threads WHERE category_id = c.id AND is_hidden = 0
            ORDER BY COALESCE(last_reply_at, created_at) DESC LIMIT 1
        )
        LEFT JOIN users u ON (t.last_reply_user_id IS NOT NULL AND u.id = t.last_reply_user_id)
                          OR (t.last_reply_user_id IS NULL    AND u.id = t.user_id)
        WHERE c.is_visible = 1
        ORDER BY c.sort_order ASC
    ")->fetchAll();

    $totalThreads  = (int)$db->query("SELECT COUNT(*) FROM threads WHERE is_hidden=0")->fetchColumn();
    $totalPosts    = (int)$db->query("SELECT COUNT(*) FROM posts   WHERE is_hidden=0")->fetchColumn();
    $totalMembers  = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $onlineCount   = (int)$db->query("SELECT COUNT(*) FROM users WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
    $newestMember  = $db->query("SELECT username FROM users ORDER BY created_at DESC LIMIT 1")->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($catSlug && isset($cat)): ?>
<!-- ═══════════════ THREAD LIST VIEW ═══════════════ -->
<div class="container">

    <nav class="breadcrumb" style="margin-bottom:18px">
        <a href="<?= SITE_URL ?>/">Home</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php">Forums</a>
        <i class="fas fa-chevron-right"></i>
        <span><?= e($cat['name']) ?></span>
    </nav>

    <div class="cat-page-header">
        <div class="cat-page-icon" style="background:<?= e($cat['color']) ?>18;color:<?= e($cat['color']) ?>">
            <i class="<?= e($cat['icon']) ?>"></i>
        </div>
        <div>
            <div class="cat-page-title"><?= e($cat['name']) ?></div>
            <div class="cat-page-sub"><?= e($cat['description'] ?? '') ?> · <?= number_format($total) ?> threads</div>
        </div>
        <?php if ($currentUser && canUserInCategory((int)$cat['id'], $currentUser, 'can_create_threads')): ?>
            <div style="margin-left:auto">
            <?php if ($cat['slug'] === 'applications'): ?>
                <a href="<?= SITE_URL ?>/apply.php" class="btn btn-accent"><i class="fas fa-file-alt"></i> Apply Now</a>
            <?php elseif ($cat['slug'] === 'appeals'): ?>
                <a href="<?= SITE_URL ?>/appeal.php" class="btn btn-accent"><i class="fas fa-gavel"></i> Submit Appeal</a>
            <?php else: ?>
                <a href="<?= SITE_URL ?>/new-thread.php?cat=<?= $cat['id'] ?>" class="btn btn-accent"><i class="fas fa-plus"></i> New Thread</a>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="thread-card-list">
    <?php if (empty($threads)): ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <p>No threads yet — be the first to post!</p>
                <?php if ($currentUser): ?>
                    <a href="<?= SITE_URL ?>/new-thread.php?cat=<?= $cat['id'] ?>" class="btn btn-accent" style="margin-top:14px">Create Thread</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
    $sc = ['pending'=>'#c9a227','reviewing'=>'#3d7ebf','accepted'=>'#37b679','denied'=>'#d94040'];
    foreach ($threads as $i => $t):
        $app    = $appSt[$t['id']]    ?? null;
        $appeal = $appealSt[$t['id']] ?? null;
        $prev   = threadSnippet($t['preview_raw']);
        $isPinned = (bool)$t['is_pinned'];
        $isLocked = (bool)$t['is_locked'];
        $isNew    = strtotime($t['created_at']) > time() - 86400;
        $lastUser = $t['last_user']   ?? $t['author'];
        $lastAv   = $t['last_avatar'] ?? $t['author_avatar'];
        $lastClr  = $t['last_color']  ?? $t['author_color'];
        $lastTime = $t['last_reply_at'] ?? $t['created_at'];
    ?>
    <div class="thread-card stagger-in" style="--stagger:<?= $i ?>">
        <?php if ($isPinned): ?><div class="tc-pin-bar"></div><?php endif; ?>

        <div class="tc-icon">
            <div class="tc-icon-inner <?= $isPinned ? 'tc-pinned' : ($isLocked ? 'tc-locked' : 'tc-normal') ?>">
                <i class="fas fa-<?= $app ? 'file-alt' : ($appeal ? 'gavel' : ($isPinned ? 'thumbtack' : ($isLocked ? 'lock' : 'comment-dots'))) ?>"></i>
            </div>
        </div>

        <div class="tc-body">
            <div class="tc-title-row">
                <a href="<?= SITE_URL ?>/thread.php?id=<?= $t['id'] ?>" class="tc-title"><?= e($t['title']) ?></a>
                <div class="tc-tags">
                    <?php if ($isPinned): ?><span class="thread-tag tag-pinned">Pinned</span><?php endif; ?>
                    <?php if ($isLocked): ?><span class="thread-tag tag-locked">Locked</span><?php endif; ?>
                    <?php if ($isNew):    ?><span class="thread-tag tag-new">New</span><?php endif; ?>
                    <?php if ($app): ?>
                        <span class="thread-tag" style="background:<?= $sc[$app['status']]??'#888' ?>18;color:<?= $sc[$app['status']]??'#888' ?>;border-color:<?= $sc[$app['status']]??'#888' ?>44"><?= ucfirst($app['status']) ?></span>
                        <?php if ($currentUser && $currentUser['can_moderate']): ?>
                            <a href="<?= SITE_URL ?>/admin/applications.php?view=<?= $app['aid'] ?>" class="thread-tag" style="background:rgba(0,206,201,0.1);color:#00cec9;border-color:rgba(0,206,201,0.3);text-decoration:none">Review</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($appeal): ?>
                        <span class="thread-tag" style="background:<?= $sc[$appeal['status']]??'#888' ?>18;color:<?= $sc[$appeal['status']]??'#888' ?>;border-color:<?= $sc[$appeal['status']]??'#888' ?>44"><?= ucfirst($appeal['status']) ?></span>
                        <?php if ($currentUser && $currentUser['can_moderate']): ?>
                            <a href="<?= SITE_URL ?>/admin/appeals.php?view=<?= $appeal['aid'] ?>" class="thread-tag" style="background:rgba(253,121,168,0.1);color:#fd79a8;border-color:rgba(253,121,168,0.3);text-decoration:none">Review</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($prev): ?><p class="tc-preview"><?= e($prev) ?></p><?php endif; ?>
            <div class="tc-meta">
                <img src="<?= e(getAvatarUrl($t['author_avatar'], $t['author'])) ?>" class="tc-meta-avatar" alt="">
                by <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($t['author']) ?>" style="color:<?= e($t['author_color']) ?>"><?= e($t['author']) ?></a>
                <span class="tc-meta-sep">·</span>
                <?= timeAgo($t['created_at']) ?>
            </div>
        </div>

        <div class="tc-stats">
            <div class="tc-stat-num"><?= number_format($t['reply_count']) ?></div>
            <div class="tc-stat-lbl">replies</div>
            <div class="tc-stat-num" style="margin-top:8px"><?= number_format($t['views'] ?? 0) ?></div>
            <div class="tc-stat-lbl">views</div>
        </div>

        <div class="tc-last">
            <img src="<?= e(getAvatarUrl($lastAv, $lastUser)) ?>" class="tc-last-avatar" alt="">
            <div style="min-width:0">
                <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($lastUser) ?>" class="tc-last-name" style="color:<?= e($lastClr) ?>"><?= e($lastUser) ?></a>
                <div class="tc-last-time"><?= timeAgo($lastTime) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?= renderPagination($pag, SITE_URL . '/forum.php?cat=' . urlencode($catSlug)) ?>
</div>

<?php else: ?>
<!-- ═══════════════ FORUM INDEX ═══════════════ -->
<div class="container">

    <div class="forum-page-header">
        <div class="forum-page-title">
            <h1>Community <em>Forums</em></h1>
            <p>Discuss roleplay, get support, and stay up to date with the community.</p>
        </div>
        <div class="forum-stats-bar">
            <div class="fsb-item">
                <strong><?= number_format($totalThreads) ?></strong>
                <span>Threads</span>
            </div>
            <div class="fsb-item">
                <strong><?= number_format($totalPosts) ?></strong>
                <span>Posts</span>
            </div>
            <div class="fsb-item">
                <strong><?= number_format($totalMembers) ?></strong>
                <span>Members</span>
            </div>
            <div class="fsb-item is-online">
                <strong><?= $onlineCount ?></strong>
                <span>Online</span>
            </div>
        </div>
        <?php if ($currentUser): ?>
            <a href="<?= SITE_URL ?>/new-thread.php" class="btn btn-accent"><i class="fas fa-pen"></i> New Thread</a>
        <?php else: ?>
            <a href="<?= SITE_URL ?>/register.php" class="btn btn-accent"><i class="fas fa-user-plus"></i> Join Now</a>
        <?php endif; ?>
    </div>

    <?php
    $sections = [
        'Community'              => ['announcements','general','introductions','media','offtopic'],
        'Server'                 => ['updates','guides','bugs','suggestions'],
        'Applications & Appeals' => ['applications','appeals'],
    ];
    foreach ($sections as $sLabel => $slugs):
        $sCats = array_values(array_filter($categories, fn($c) => in_array($c['slug'], $slugs)));
        if (empty($sCats)) continue;
    ?>
    <div class="forum-section stagger-in" style="--stagger:<?= array_search($sLabel, array_keys($sections)) ?>">
        <div class="forum-section-label"><span><?= e($sLabel) ?></span></div>
        <div class="forum-cat-list">
            <?php foreach ($sCats as $cat): ?>
            <div class="forum-cat-row" onclick="location.href='<?= SITE_URL ?>/forum.php?cat=<?= e($cat['slug']) ?>'" style="cursor:pointer">
                <div class="fcr-stripe" style="background:<?= e($cat['color']) ?>"></div>

                <div class="fcr-icon-cell">
                    <div class="fcr-icon" style="background:<?= e($cat['color']) ?>18;color:<?= e($cat['color']) ?>">
                        <i class="<?= e($cat['icon']) ?>"></i>
                    </div>
                </div>

                <div class="fcr-info">
                    <div class="fcr-name"><?= e($cat['name']) ?></div>
                    <div class="fcr-desc"><?= e($cat['description'] ?? '') ?></div>
                    <?php if ($cat['last_title']): ?>
                        <div class="fcr-last">
                            <img src="<?= e(getAvatarUrl($cat['last_avatar'] ?? null, $cat['last_user'] ?? '?')) ?>" class="fcr-last-avatar" alt="">
                            <a href="<?= SITE_URL ?>/thread.php?id=<?= $cat['last_tid'] ?>" class="fcr-last-title"><?= e(mb_strimwidth($cat['last_title'], 0, 50, '…')) ?></a>
                            <span style="color:var(--t2);flex-shrink:0">· <?= timeAgo($cat['last_date']) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="fcr-last-empty">No posts yet — be the first!</div>
                    <?php endif; ?>
                </div>

                <div class="fcr-stats">
                    <div class="fcr-stat">
                        <strong><?= number_format($cat['thread_count']) ?></strong>
                        <span>threads</span>
                    </div>
                    <div class="fcr-stat">
                        <strong><?= number_format($cat['post_count']) ?></strong>
                        <span>posts</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="forum-footer-bar">
        <span><i class="fas fa-circle" style="color:var(--green);font-size:0.55rem"></i> &nbsp;<?= $onlineCount ?> member<?= $onlineCount !== 1 ? 's' : '' ?> currently online</span>
        <span>Newest member: <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($newestMember) ?>" style="color:var(--t0);text-decoration:none;font-weight:700"><?= e($newestMember) ?></a></span>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
