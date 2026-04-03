<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Threads & Posts · Admin';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    switch ($action) {
        case 'pin_thread':
            $t = $db->prepare("SELECT is_pinned FROM threads WHERE id=?"); $t->execute([$id]); $t = $t->fetch();
            $db->prepare("UPDATE threads SET is_pinned=? WHERE id=?")->execute([$t ? ($t['is_pinned'] ? 0 : 1) : 1, $id]);
            $message = 'Thread pin toggled.'; break;
        case 'lock_thread':
            $t = $db->prepare("SELECT is_locked FROM threads WHERE id=?"); $t->execute([$id]); $t = $t->fetch();
            $db->prepare("UPDATE threads SET is_locked=? WHERE id=?")->execute([$t ? ($t['is_locked'] ? 0 : 1) : 1, $id]);
            $message = 'Thread lock toggled.'; break;
        case 'hide_thread':
            $db->prepare("UPDATE threads SET is_hidden=1 WHERE id=?")->execute([$id]);
            logAudit($currentUser['id'], 'hide_thread', 'thread', $id);
            $message = 'Thread hidden.'; break;
        case 'restore_thread':
            $db->prepare("UPDATE threads SET is_hidden=0 WHERE id=?")->execute([$id]);
            $message = 'Thread restored.'; break;
        case 'delete_thread':
            $db->prepare("DELETE FROM threads WHERE id=?")->execute([$id]);
            logAudit($currentUser['id'], 'delete_thread', 'thread', $id);
            $message = 'Thread permanently deleted.'; break;
        case 'hide_post':
            $db->prepare("UPDATE posts SET is_hidden=1 WHERE id=?")->execute([$id]);
            $message = 'Post hidden.'; break;
        case 'restore_post':
            $db->prepare("UPDATE posts SET is_hidden=0 WHERE id=?")->execute([$id]);
            $message = 'Post restored.'; break;
        case 'delete_post':
            $db->prepare("DELETE FROM posts WHERE id=?")->execute([$id]);
            $message = 'Post deleted.'; break;
    }
}

// Tab: threads or posts
$tab    = $_GET['tab'] ?? 'threads';
$search = trim($_GET['search'] ?? '');
$show   = $_GET['show'] ?? 'all'; // all, hidden
$page   = max(1, (int)($_GET['page'] ?? 1));

if ($tab === 'posts') {
    $where  = 'p.is_hidden IN (0,1)';
    $params = [];
    if ($show === 'hidden') { $where = 'p.is_hidden = 1'; }
    if ($search) { $where .= " AND (p.content LIKE ? OR u.username LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM posts p JOIN users u ON p.user_id = u.id WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, 20, $page);

    $stmt = $db->prepare("
        SELECT p.*, u.username, t.title as thread_title, t.id as thread_id
        FROM posts p
        JOIN users u ON p.user_id = u.id
        JOIN threads t ON p.thread_id = t.id
        WHERE $where
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
    $posts = $stmt->fetchAll();
} else {
    $where  = 't.is_hidden IN (0,1)';
    $params = [];
    if ($show === 'hidden') { $where = 't.is_hidden = 1'; }
    if ($search) { $where .= " AND (t.title LIKE ? OR u.username LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM threads t JOIN users u ON t.user_id = u.id WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, 20, $page);

    $stmt = $db->prepare("
        SELECT t.*, u.username, c.name as cat_name, c.color as cat_color
        FROM threads t
        JOIN users u ON t.user_id = u.id
        JOIN categories c ON t.category_id = c.id
        WHERE $where
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
    $threads = $stmt->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-comments" style="color:var(--red)"></i> Threads &amp; Posts</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>

        <!-- Tabs -->
        <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--b0);padding-bottom:0">
            <a href="?tab=threads" class="btn <?= $tab === 'threads' ? 'btn-accent' : 'btn-ghost' ?> btn-sm" style="border-radius:var(--r) var(--r) 0 0">
                <i class="fas fa-comments"></i> Threads
            </a>
            <a href="?tab=posts" class="btn <?= $tab === 'posts' ? 'btn-accent' : 'btn-ghost' ?> btn-sm" style="border-radius:var(--r) var(--r) 0 0">
                <i class="fas fa-message"></i> Posts
            </a>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="search" class="form-input" placeholder="Search <?= $tab ?>..." value="<?= e($search) ?>" style="flex:1;min-width:200px">
            <select name="show" class="form-select" style="width:auto">
                <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All</option>
                <option value="hidden" <?= $show === 'hidden' ? 'selected' : '' ?>>Hidden Only</option>
            </select>
            <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Filter</button>
        </form>

        <?php if ($tab === 'threads'): ?>
        <!-- Threads Table -->
        <table class="admin-table">
            <thead>
                <tr><th>Title</th><th>Author</th><th>Category</th><th>Replies</th><th>Status</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($threads as $t): ?>
                <tr style="<?= $t['is_hidden'] ? 'opacity:0.5' : '' ?>">
                    <td>
                        <a href="<?= SITE_URL ?>/thread.php?id=<?= $t['id'] ?>" target="_blank" style="color:var(--t0);text-decoration:none;font-weight:600">
                            <?= e(substr($t['title'], 0, 60)) ?>
                        </a>
                    </td>
                    <td style="color:var(--red)"><?= e($t['username']) ?></td>
                    <td>
                        <span style="color:<?= e($t['cat_color']) ?>;font-size:0.8rem"><?= e($t['cat_name']) ?></span>
                    </td>
                    <td><?= $t['reply_count'] ?></td>
                    <td style="font-size:0.8rem">
                        <?php if ($t['is_hidden']): ?>
                            <span style="color:var(--red)"><i class="fas fa-eye-slash"></i> Hidden</span>
                        <?php else: ?>
                            <?php if ($t['is_pinned']): ?><span style="color:var(--gold)"><i class="fas fa-thumbtack"></i> </span><?php endif; ?>
                            <?php if ($t['is_locked']): ?><span style="color:var(--red)"><i class="fas fa-lock"></i> </span><?php endif; ?>
                            <span style="color:var(--green)"><i class="fas fa-eye"></i> Visible</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.78rem;color:var(--t2)"><?= timeAgo($t['created_at']) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:4px;flex-wrap:wrap">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button name="action" value="pin_thread" class="btn btn-ghost btn-sm" title="<?= $t['is_pinned'] ? 'Unpin' : 'Pin' ?>"><i class="fas fa-thumbtack"></i></button>
                            <button name="action" value="lock_thread" class="btn btn-ghost btn-sm" title="<?= $t['is_locked'] ? 'Unlock' : 'Lock' ?>"><i class="fas fa-lock"></i></button>
                            <?php if ($t['is_hidden']): ?>
                                <button name="action" value="restore_thread" class="btn btn-success btn-sm"><i class="fas fa-eye"></i></button>
                            <?php else: ?>
                                <button name="action" value="hide_thread" class="btn btn-ghost btn-sm"><i class="fas fa-eye-slash"></i></button>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                                <button name="action" value="delete_thread" class="btn btn-danger btn-sm" data-confirm="Permanently delete this thread and all its posts?"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($threads)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--t2);padding:30px">No threads found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php else: ?>
        <!-- Posts Table -->
        <table class="admin-table">
            <thead>
                <tr><th>Content</th><th>Author</th><th>Thread</th><th>Status</th><th>Posted</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $p): ?>
                <tr style="<?= $p['is_hidden'] ? 'opacity:0.5' : '' ?>">
                    <td style="max-width:300px">
                        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.85rem;color:var(--t1)">
                            <?= e(substr(strip_tags($p['content']), 0, 80)) ?>...
                        </div>
                    </td>
                    <td style="color:var(--red)"><?= e($p['username']) ?></td>
                    <td>
                        <a href="<?= SITE_URL ?>/thread.php?id=<?= $p['thread_id'] ?>#post-<?= $p['id'] ?>" target="_blank" style="color:var(--t1);text-decoration:none;font-size:0.82rem">
                            <?= e(substr($p['thread_title'], 0, 40)) ?>...
                        </a>
                    </td>
                    <td>
                        <?php if ($p['is_hidden']): ?>
                            <span style="color:var(--red);font-size:0.8rem"><i class="fas fa-eye-slash"></i> Hidden</span>
                        <?php else: ?>
                            <span style="color:var(--green);font-size:0.8rem"><i class="fas fa-eye"></i> Visible</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.78rem;color:var(--t2)"><?= timeAgo($p['created_at']) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:4px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="tab" value="posts">
                            <?php if ($p['is_hidden']): ?>
                                <button name="action" value="restore_post" class="btn btn-success btn-sm"><i class="fas fa-eye"></i></button>
                            <?php else: ?>
                                <button name="action" value="hide_post" class="btn btn-ghost btn-sm"><i class="fas fa-eye-slash"></i></button>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                                <button name="action" value="delete_post" class="btn btn-danger btn-sm" data-confirm="Permanently delete this post?"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($posts)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--t2);padding:30px">No posts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?= renderPagination($pag, SITE_URL . '/admin/threads.php?tab=' . $tab . '&search=' . urlencode($search) . '&show=' . $show) ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
