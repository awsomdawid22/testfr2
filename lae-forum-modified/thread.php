<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();

// Require login to view threads
if (!$currentUser) {
    header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = getDB();

$threadId = (int)($_GET['id'] ?? 0);
if (!$threadId) { header('Location: ' . SITE_URL . '/forum.php'); exit; }

$thread = $db->prepare("
    SELECT t.*, u.username as author, c.name as cat_name, c.slug as cat_slug, c.color as cat_color, c.icon as cat_icon
    FROM threads t
    JOIN users u ON t.user_id = u.id
    JOIN categories c ON t.category_id = c.id
    WHERE t.id = ? AND t.is_hidden = 0
");
$thread->execute([$threadId]);
$thread = $thread->fetch();

if (!$thread) { header('Location: ' . SITE_URL . '/forum.php'); exit; }

// Deduplicated view tracking — only count unique visitors (by IP+session) per thread
$ip         = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'anon';
$sessionKey = session_id() ?: $ip;
$viewerKey  = hash('sha256', $ip . '|' . $sessionKey . '|' . $threadId);
try {
    $viewStmt = $db->prepare("INSERT IGNORE INTO thread_views (thread_id, viewer_key) VALUES (?,?)");
    $viewStmt->execute([$threadId, $viewerKey]);
    if ($viewStmt->rowCount() > 0) {
        // Only increment when a genuinely new viewer is recorded
        $db->prepare("UPDATE threads SET views = views + 1 WHERE id = ?")->execute([$threadId]);
    }
} catch (\Throwable $e) {
    // Fallback: always increment (table may not exist yet)
    $db->prepare("UPDATE threads SET views = views + 1 WHERE id = ?")->execute([$threadId]);
}

$pageTitle = $thread['title'] . ' · LAE Forums';
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPostsStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND is_hidden = 0");
$totalPostsStmt->execute([$threadId]);
$totalPosts = (int)$totalPostsStmt->fetchColumn();
$pag = paginate($totalPosts, POSTS_PER_PAGE, $page);

$posts = $db->prepare("
    SELECT p.*, u.username, u.avatar, u.post_count, u.bio, u.discord_id, u.created_at as joined,
           r.display_name as role_display, r.color as role_color, r.badge_color, r.name as role_name,
           r.can_moderate, r.can_admin,
           (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
           " . ($currentUser ? "(SELECT COUNT(*) FROM post_likes WHERE post_id = p.id AND user_id = {$currentUser['id']})" : "0") . " as user_liked
    FROM posts p
    JOIN users u ON p.user_id = u.id
    JOIN roles r ON u.role_id = r.id
    WHERE p.thread_id = ? AND p.is_hidden = 0
    ORDER BY p.created_at ASC
    LIMIT ? OFFSET ?
");
$posts->execute([$threadId, $pag['per_page'], $pag['offset']]);
$posts = $posts->fetchAll();

// Handle new reply
$postError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_content'])) {
    if (!$currentUser) { $postError = 'You must be logged in to reply.'; }
    elseif (!verifyCSRF($_POST['csrf'] ?? '')) { $postError = 'Security token invalid.'; }
    elseif ($thread['is_locked'] && !$currentUser['can_moderate']) { $postError = 'This thread is locked.'; }
    elseif (!$currentUser['can_post']) { $postError = 'You do not have permission to post.'; }
    elseif (!canUserInCategory((int)$thread['category_id'], $currentUser, 'can_post')) { $postError = 'You do not have permission to post in this category.'; }
    elseif (strlen(trim($_POST['post_content'])) < 5) { $postError = 'Reply must be at least 5 characters.'; }
    else {
        $content = trim($_POST['post_content']);
        $db->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?,?,?)")->execute([$threadId, $currentUser['id'], $content]);
        $newPostId = $db->lastInsertId();
        $db->prepare("UPDATE threads SET reply_count = reply_count + 1, last_reply_user_id = ?, last_reply_at = NOW() WHERE id = ?")->execute([$currentUser['id'], $threadId]);
        $db->prepare("UPDATE users SET post_count = post_count + 1 WHERE id = ?")->execute([$currentUser['id']]);
        $db->prepare("UPDATE categories SET post_count = post_count + 1 WHERE id = ?")->execute([$thread['category_id']]);
        logAudit($currentUser['id'], 'create_post', 'thread', $threadId, 'Reply posted');

        // ── Notify thread participants ──────────────────────────────────────────
        // Collect unique user IDs to notify: thread author + all previous repliers
        // Exclude the person who just posted.
        $participantStmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.email
            FROM users u
            WHERE u.id IN (
                -- Thread author
                SELECT user_id FROM threads WHERE id = ?
                UNION
                -- Everyone who previously replied
                SELECT user_id FROM posts WHERE thread_id = ? AND is_hidden = 0
            )
            AND u.id != ?
            AND u.is_banned = 0
        ");
        $participantStmt->execute([$threadId, $threadId, $currentUser['id']]);
        $participants = $participantStmt->fetchAll();

        $threadUrl   = SITE_URL . '/thread.php?id=' . $threadId . '#post-' . $newPostId;
        $snippet     = mb_substr(strip_tags($content), 0, 180);
        if (mb_strlen(strip_tags($content)) > 180) $snippet .= '…';
        $replierName = $currentUser['username'];
        $threadTitle = $thread['title'];

        foreach ($participants as $p) {
            // In-app notification (one per user, no duplicates within 5 mins)
            $recentCheck = $db->prepare("
                SELECT id FROM notifications
                WHERE user_id = ? AND type = 'thread_reply' AND link = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1
            ");
            $recentCheck->execute([$p['id'], $threadUrl]);
            if (!$recentCheck->fetch()) {
                $db->prepare("
                    INSERT INTO notifications (user_id, type, title, content, link)
                    VALUES (?, 'thread_reply', ?, ?, ?)
                ")->execute([
                    $p['id'],
                    $replierName . ' replied to: ' . mb_substr($threadTitle, 0, 60),
                    $snippet,
                    $threadUrl,
                ]);
            }

            // Email notification
            if (defined('MAIL_ENABLED') && MAIL_ENABLED && $p['email']) {
                require_once __DIR__ . '/includes/mailer.php';
                sendThreadReplyEmail(
                    $p['email'],
                    $p['username'],
                    $replierName,
                    $threadTitle,
                    $snippet,
                    $threadUrl
                );
            }
        }
        // ── End notifications ───────────────────────────────────────────────────

        header('Location: ' . SITE_URL . '/thread.php?id=' . $threadId . '#bottom');
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>
<meta name="csrf-token" content="<?= generateCSRF() ?>">
<meta name="thread-id" content="<?= $threadId ?>">

<div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="<?= SITE_URL ?>/">Home</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php">Forums</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php?cat=<?= e($thread['cat_slug']) ?>"><?= e($thread['cat_name']) ?></a>
        <i class="fas fa-chevron-right"></i>
        <span><?= e(substr($thread['title'], 0, 50)) ?></span>
    </div>

    <!-- Thread header -->
    <div class="thread-header-bar" style="border-radius:var(--r-md) var(--r-md) 0 0;margin-bottom:0;border-bottom:1px solid var(--b0)">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
            <div>
                <div style="display:flex;gap:8px;margin-bottom:8px">
                    <span style="background:<?= e($thread['cat_color']) ?>22;color:<?= e($thread['cat_color']) ?>;padding:2px 10px;border-radius:3px;font-size:0.72rem;font-weight:700;letter-spacing:2px;text-transform:uppercase">
                        <i class="<?= e($thread['cat_icon']) ?>"></i> <?= e($thread['cat_name']) ?>
                    </span>
                    <?php if ($thread['is_pinned']): ?><span class="thread-tag tag-pinned">Pinned</span><?php endif; ?>
                    <?php if ($thread['is_locked']): ?><span class="thread-tag tag-locked">Locked</span><?php endif; ?>
                </div>
                <h1 style="font-family:var(--font-head);font-size:clamp(1.4rem,4vw,2.2rem);letter-spacing:1px"><?= e($thread['title']) ?></h1>
                <div style="font-size:0.82rem;color:var(--t2);margin-top:6px">
                    By <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($thread['author']) ?>" style="color:var(--red);text-decoration:none"><?= e($thread['author']) ?></a>
                    · <?= formatDate($thread['created_at']) ?>
                    · <?= number_format($thread['views']) ?> views · <span id="live-reply-count"><?= number_format($thread['reply_count']) ?></span> replies
                </div>
            </div>
            <?php if ($currentUser && $currentUser['can_moderate']): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?= SITE_URL ?>/admin/moderate.php?action=pin&id=<?= $threadId ?>&csrf=<?= generateCSRF() ?>" class="btn btn-ghost btn-sm"><i class="fas fa-thumbtack"></i> <?= $thread['is_pinned'] ? 'Unpin' : 'Pin' ?></a>
                <a href="<?= SITE_URL ?>/admin/moderate.php?action=lock&id=<?= $threadId ?>&csrf=<?= generateCSRF() ?>" class="btn btn-ghost btn-sm"><i class="fas fa-lock"></i> <?= $thread['is_locked'] ? 'Unlock' : 'Lock' ?></a>
                <a href="<?= SITE_URL ?>/admin/moderate.php?action=delete_thread&id=<?= $threadId ?>&csrf=<?= generateCSRF() ?>" class="btn btn-danger btn-sm" data-confirm="Delete this entire thread?"><i class="fas fa-trash"></i> Delete</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($postError): ?>
        <div class="alert alert-error" style="margin-top:12px"><i class="fas fa-exclamation-circle"></i> <?= e($postError) ?></div>
    <?php endif; ?>

    <!-- Posts -->
    <div style="margin-top:2px">
    <?php foreach ($posts as $i => $post): ?>
    <div class="post-wrapper" id="post-<?= $post['id'] ?>">
        <div class="post-card">
            <!-- Sidebar -->
            <div class="post-sidebar">
                <img src="<?= e(getAvatarUrl($post['avatar'], $post['username'])) ?>" alt="avatar" class="post-avatar">
                <div class="post-username">
                    <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($post['username']) ?>"><?= e($post['username']) ?></a>
                </div>
                <?= getRoleBadge($post) ?>
                <div class="post-stats">
                    <span><i class="fas fa-comments" style="color:var(--red);margin-right:4px"></i><?= number_format($post['post_count']) ?> posts</span>
                    <span><i class="fas fa-calendar" style="color:var(--t2);margin-right:4px"></i>Joined <?= date('M Y', strtotime($post['joined'])) ?></span>
                    <?php if ($post['discord_id']): ?>
                    <span style="color:#5865f2"><i class="fab fa-discord" style="margin-right:4px"></i>Discord linked</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Body -->
            <div class="post-body">
                <div class="post-header">
                    <span>#<?= $pag['offset'] + $i + 1 ?> · <?= formatDate($post['created_at']) ?></span>
                    <div style="display:flex;gap:8px">
                        <?php if ($post['edited_at']): ?>
                            <span style="font-size:0.75rem;color:var(--t2);font-style:italic">Edited <?= timeAgo($post['edited_at']) ?></span>
                        <?php endif; ?>
                        <a href="#post-<?= $post['id'] ?>" style="color:var(--t2);text-decoration:none">#<?= $post['id'] ?></a>
                    </div>
                </div>
                <div class="post-content" id="post-body-<?= $post['id'] ?>"><?= sanitizePost($post['content']) ?></div>

                <!-- Inline edit box (hidden by default) -->
                <?php if ($currentUser && ($currentUser['id'] == $post['user_id'] || $currentUser['can_moderate'])): ?>
                <div id="edit-box-<?= $post['id'] ?>" style="display:none;margin-top:12px">
                    <div class="editor-toolbar" data-target="edit-ta-<?= $post['id'] ?>">
                        <button type="button" class="tb-btn" data-tag="b" data-sample="bold text" title="Bold"><b>B</b></button>
                        <button type="button" class="tb-btn" data-tag="i" data-sample="italic text" title="Italic"><i>I</i></button>
                        <button type="button" class="tb-btn" data-tag="u" data-sample="underlined" title="Underline"><u>U</u></button>
                        <button type="button" class="tb-btn" data-tag="s" data-sample="strikethrough" title="Strikethrough"><s>S</s></button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="color" data-end="color" data-sample="red text" data-attr="=#ff6b6b" title="Colour">🎨</button>
                        <button type="button" class="tb-btn" data-tag="quote" data-sample="quoted text" title="Quote">❝</button>
                        <button type="button" class="tb-btn" data-tag="code" data-sample="code here" title="Code">&lt;/&gt;</button>
                        <button type="button" class="tb-btn" data-tag="spoiler" data-sample="hidden content" title="Spoiler">👁</button>
                        <button type="button" class="tb-btn" data-tag="url" data-end="url" data-sample="https://example.com" data-attr="=https://example.com" title="Link">🔗</button>
                        <button type="button" class="tb-btn" data-insert="[hr]" title="Divider">—</button>
                    </div>
                    <textarea id="edit-ta-<?= $post['id'] ?>" class="form-textarea" style="min-height:120px;border-radius:0 0 var(--r) var(--r);margin-bottom:8px"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
                        <span style="font-size:0.75rem;color:var(--t2)">BBCode supported</span>
                        <button class="btn btn-ghost btn-sm edit-cancel-btn" data-post-id="<?= $post['id'] ?>">Cancel</button>
                        <button class="btn btn-accent btn-sm edit-save-btn" data-post-id="<?= $post['id'] ?>"><i class="fas fa-save"></i> Save</button>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Staff watermark — shown on posts by moderators/admins
                $isStaffPoster = !empty($post['can_moderate']) || !empty($post['can_admin']);
                if ($isStaffPoster):
                    $watermarkRole = $post['role_display'] ?? $post['role_name'] ?? 'Staff';
                    $watermarkColor = $post['role_color'] ?? '#888';
                ?>
                <div class="staff-watermark" style="border-top:1px solid <?= e($watermarkColor) ?>22;margin-top:14px;padding-top:10px">
                    <div class="staff-watermark-inner">
                        <i class="fas fa-shield-halved" style="color:<?= e($watermarkColor) ?>"></i>
                        <span style="color:<?= e($watermarkColor) ?>;font-weight:700"><?= e($watermarkRole) ?></span>
                        <span style="color:var(--t2)">at</span>
                        <span style="color:var(--t1);font-weight:600">Los Angeles Experience</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="post-footer">
                    <button class="like-btn <?= $post['user_liked'] ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                        <i class="fas fa-heart"></i>
                        <span class="like-count"><?= $post['like_count'] ?></span>
                    </button>
                    <div class="post-actions">
                        <button class="post-action-btn copy-link-btn" data-post-url="<?= SITE_URL ?>/thread.php?id=<?= $threadId ?>#post-<?= $post['id'] ?>" title="Copy link to post"><i class="fas fa-link"></i> Link</button>
                        <?php if ($currentUser && ($currentUser['id'] == $post['user_id'] || $currentUser['can_moderate'])): ?>
                            <button class="post-action-btn edit-post-btn" data-post-id="<?= $post['id'] ?>"><i class="fas fa-pen"></i> Edit</button>
                        <?php endif; ?>
                        <?php if ($currentUser && $currentUser['can_moderate']): ?>
                            <a href="<?= SITE_URL ?>/admin/moderate.php?action=delete_post&id=<?= $post['id'] ?>&thread=<?= $threadId ?>&csrf=<?= generateCSRF() ?>" class="post-action-btn" style="color:var(--red)" data-confirm="Delete this post?"><i class="fas fa-trash"></i> Delete</a>
                        <?php endif; ?>
                        <?php if ($currentUser): ?>
                            <button class="post-action-btn" onclick="document.querySelector('#reply-content').value += '[quote]@<?= e($post['username']) ?>: ...[/quote]\n\n'"><i class="fas fa-reply"></i> Quote</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?= renderPagination($pag, SITE_URL . '/thread.php?id=' . $threadId) ?>

    <!-- Reply Box -->
    <?php
    $canReplyHere = $currentUser && !empty($currentUser['can_post']) && canUserInCategory((int)$thread['category_id'], $currentUser, 'can_post');
    ?>
    <?php if ($canReplyHere && !$thread['is_locked']): ?>
    <div class="reply-box" id="bottom">
        <h3 style="font-family:var(--font-head);letter-spacing:2px;font-size:1.2rem;margin-bottom:16px"><i class="fas fa-reply" style="color:var(--red)"></i> POST REPLY</h3>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
            <div class="form-group">
                <div class="editor-toolbar">
                        <button type="button" class="tb-btn" data-tag="b" data-sample="bold text" title="Bold"><b>B</b></button>
                        <button type="button" class="tb-btn" data-tag="i" data-sample="italic text" title="Italic"><i>I</i></button>
                        <button type="button" class="tb-btn" data-tag="u" data-sample="underlined" title="Underline"><u>U</u></button>
                        <button type="button" class="tb-btn" data-tag="s" data-sample="strikethrough" title="Strikethrough"><s>S</s></button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="color" data-end="color" data-sample="red text" data-attr="=#ff6b6b" title="Colour">🎨</button>
                        <button type="button" class="tb-btn" data-tag="size" data-end="size" data-sample="big text" data-attr="=3" title="Size">A↕</button>
                        <button type="button" class="tb-btn" data-tag="highlight" data-sample="highlighted text" title="Highlight">H</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="center" data-sample="centred text" title="Centre">≡</button>
                        <button type="button" class="tb-btn" data-tag="right" data-sample="right aligned" title="Right align">⇥</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="quote" data-sample="quoted text" title="Quote">❝</button>
                        <button type="button" class="tb-btn" data-tag="code" data-sample="your code here" title="Code block">&lt;/&gt;</button>
                        <button type="button" class="tb-btn" data-tag="icode" data-sample="inline code" title="Inline code">`</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="url" data-end="url" data-sample="https://example.com" data-attr="=https://example.com" title="Link">🔗</button>
                        <button type="button" class="tb-btn" data-tag="img" data-sample="https://example.com/img.jpg" title="Image">🖼</button>
                        <button type="button" class="tb-btn" data-tag="youtube" data-sample="https://youtu.be/dQw4w9WgXcQ" title="YouTube">▶</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="list" data-sample="[*]Item 1[*]Item 2" title="List">☰</button>
                        <button type="button" class="tb-btn" data-tag="spoiler" data-sample="secret content" title="Spoiler">👁</button>
                        <button type="button" class="tb-btn" data-tag="notice" data-sample="important notice here" title="Notice">ℹ</button>
                        <button type="button" class="tb-btn" data-insert="[hr]" title="Divider">—</button>
                    </div>
                <textarea name="post_content" id="reply-content" class="form-textarea" placeholder="Write your reply here... BBCode supported: [b]bold[/b] [i]italic[/i] [quote]...[/quote]" required style="border-radius:0 0 var(--r) var(--r);min-height:180px"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="reset" class="btn btn-ghost">Clear</button>
                <button type="submit" class="btn btn-accent"><i class="fas fa-paper-plane"></i> Post Reply</button>
            </div>
        </form>
    </div>
    <?php elseif ($thread['is_locked']): ?>
        <div class="alert alert-warning" style="margin-top:16px"><i class="fas fa-lock"></i> This thread is locked. No new replies can be posted.</div>
    <?php elseif (!$currentUser): ?>
        <div class="alert alert-info" style="margin-top:16px"><i class="fas fa-info-circle"></i> You must <a href="<?= SITE_URL ?>/login.php" style="color:var(--red)">login</a> to reply.</div>
    <?php elseif (!$canReplyHere): ?>
        <div class="alert alert-warning" style="margin-top:16px"><i class="fas fa-shield-halved"></i> Your role does not have permission to post replies in this category.</div>
    <?php endif; ?>

</div>

<script>
// ── Live reply polling ─────────────────────────────────────────────────────
(function() {
    const threadId    = <?= (int)$threadId ?>;
    const SITE_URL    = document.querySelector('meta[name="site-url"]')?.content || '';
    const currentPage = <?= (int)$page ?>;
    let   lastCount   = <?= (int)$thread['reply_count'] ?>;

    const replyCountEl = document.getElementById('live-reply-count');

    async function pollReplies() {
        try {
            const r = await fetch(`${SITE_URL}/api.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'poll_thread', thread_id: threadId})
            });
            const d = await r.json();
            if (!d.success) return;

            if (replyCountEl) replyCountEl.textContent = d.reply_count;

            // Show "new replies" banner if count changed and we're on last page
            if (d.reply_count > lastCount) {
                const diff = d.reply_count - lastCount;
                showNewReplyBanner(diff, d.last_reply_user);
                lastCount = d.reply_count;
            }
        } catch(e) { /* ignore */ }
    }

    function showNewReplyBanner(count, username) {
        const existing = document.getElementById('new-reply-banner');
        if (existing) existing.remove();

        const banner = document.createElement('div');
        banner.id = 'new-reply-banner';
        banner.innerHTML = `
            <div style="position:fixed;bottom:24px;right:24px;z-index:9999;
                background:var(--bg3);border:1px solid var(--red);border-radius:var(--radius);
                padding:12px 18px;box-shadow:0 8px 30px rgba(0,0,0,0.6);
                display:flex;align-items:center;gap:12px;
                animation:slideUp 0.3s ease">
                <i class="fas fa-comment-dots" style="color:var(--red);font-size:1.1rem"></i>
                <div>
                    <div style="font-weight:700;font-size:0.88rem;color:var(--t0)">
                        ${count} new repl${count===1?'y':'ies'}
                    </div>
                    ${username ? `<div style="font-size:0.75rem;color:var(--t2)">from ${username}</div>` : ''}
                </div>
                <button onclick="window.location.reload()"
                        style="background:var(--red);color:#fff;border:none;padding:5px 12px;
                               border-radius:var(--radius);cursor:pointer;font-size:0.78rem;
                               font-weight:700;font-family:var(--font-body);white-space:nowrap">
                    Refresh
                </button>
                <button onclick="this.closest('#new-reply-banner').remove()"
                        style="background:none;border:none;color:var(--t2);cursor:pointer;font-size:1rem;padding:0 4px">
                    ✕
                </button>
            </div>`;
        document.body.appendChild(banner);
    }

    // Poll every 15 seconds
    setInterval(pollReplies, 15000);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
