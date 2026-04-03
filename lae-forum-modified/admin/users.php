<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'User Management · Admin';

$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'update_role' && isAdmin()) {
        $roleId = (int)$_POST['role_id'];
        $extraRolesRaw      = $_POST['extra_roles'] ?? [];
        $extraRolesFiltered = array_values(array_diff(array_map('intval', $extraRolesRaw), [$roleId]));
        $extraRolesJson     = count($extraRolesFiltered) ? json_encode($extraRolesFiltered) : null;
        try {
            $db->prepare("UPDATE users SET role_id=?, extra_roles=? WHERE id=?")->execute([$roleId, $extraRolesJson, $userId]);
        } catch (\Throwable $e) {
            // extra_roles column may not exist yet — run migration
            $db->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$roleId, $userId]);
        }
        logAudit($currentUser['id'], 'update_role', 'user', $userId, "Changed role to $roleId");
        $message = 'Role updated successfully.';
    } elseif ($action === 'ban' && $currentUser['can_ban']) {
        $reason = trim($_POST['ban_reason'] ?? 'No reason given');
        $db->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?")->execute([$reason, $userId]);
        logAudit($currentUser['id'], 'ban_user', 'user', $userId, "Banned: $reason");
        $message = 'User banned.';
    } elseif ($action === 'unban') {
        $db->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?")->execute([$userId]);
        logAudit($currentUser['id'], 'unban_user', 'user', $userId, 'User unbanned');
        $message = 'User unbanned.';
    } elseif ($action === 'delete' && isAdmin()) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        logAudit($currentUser['id'], 'delete_user', 'user', $userId, 'User deleted');
        $message = 'User deleted.';
    }
}

// Search & filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));

$where = '1=1';
$params = [];
if ($search) { $where .= " AND (u.username LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filter === 'banned') { $where .= " AND u.is_banned = 1"; }
if ($filter === 'admins') { $where .= " AND r.can_admin = 1"; }
if ($filter === 'mods') { $where .= " AND r.can_moderate = 1"; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, 20, $page);

$stmt = $db->prepare("
    SELECT u.*, r.display_name as role_display, r.color as role_color, r.badge_color, r.name as role_name
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$users = $stmt->fetchAll();

// Edit user
$editUser = null;
if (isset($_GET['edit']) && isAdmin()) {
    $editStmt = $db->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $editStmt->execute([(int)$_GET['edit']]);
    $editUser = $editStmt->fetch();
}

$roles = $db->query("SELECT * FROM roles ORDER BY priority DESC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-users" style="color:var(--red)"></i> User Management</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <?php if ($editUser): ?>
        <!-- Edit User Modal -->
        <div class="card" style="margin-bottom:20px;border-color:var(--red)">
            <div class="card-header"><h3><i class="fas fa-pen"></i> Editing: <?= e($editUser['username']) ?></h3></div>
            <div style="padding:20px">
                <?php
                $editExtraRoles = [];
                if (!empty($editUser['extra_roles'])) {
                    $editExtraRoles = json_decode($editUser['extra_roles'], true) ?? [];
                }
                ?>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <input type="hidden" name="is_banned" value="<?= $editUser['is_banned'] ?>">
                    <input type="hidden" name="ban_reason" value="<?= e($editUser['ban_reason'] ?? '') ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                        <div class="form-group">
                            <label class="form-label">Primary Role</label>
                            <select name="role_id" class="form-select">
                                <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['id'] ?>" <?= $editUser['role_id'] == $role['id'] ? 'selected' : '' ?>>
                                    <?= e($role['display_name']) ?> (priority <?= $role['priority'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">This is the main role shown on posts and in the nav.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Additional Roles <span style="color:var(--t2)">(multi-select)</span></label>
                            <select name="extra_roles[]" class="form-select" multiple style="height:130px">
                                <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['id'] ?>"
                                    <?= in_array($role['id'], $editExtraRoles) ? 'selected' : '' ?>>
                                    <?= e($role['display_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Hold Ctrl/Cmd to select multiple. These show on the profile page.</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
                        <button type="submit" class="btn btn-accent"><i class="fas fa-save"></i> Save Changes</button>
                        <a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-ghost">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search & Filters -->
        <form method="GET" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" name="search" class="form-input" placeholder="Search by username or email..." value="<?= e($search) ?>" style="flex:1;min-width:200px">
            <select name="filter" class="form-select" style="width:auto">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Users</option>
                <option value="banned" <?= $filter === 'banned' ? 'selected' : '' ?>>Banned</option>
                <option value="admins" <?= $filter === 'admins' ? 'selected' : '' ?>>Admins</option>
                <option value="mods" <?= $filter === 'mods' ? 'selected' : '' ?>>Moderators</option>
            </select>
            <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Search</button>
        </form>

        <!-- Users table -->
        <table class="admin-table">
            <thead>
                <tr>
                    <th>User</th><th>Email</th><th>Role</th><th>Posts</th><th>Status</th><th>Joined</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <img src="<?= e(getAvatarUrl($u['avatar'], $u['username'])) ?>" style="width:32px;height:32px;border-radius:50%">
                            <a href="<?= SITE_URL ?>/profile.php?user=<?= $u['id'] ?>" style="color:var(--t0);text-decoration:none;font-weight:600"><?= e($u['username']) ?></a>
                        </div>
                    </td>
                    <td style="color:var(--t2);font-size:0.82rem"><?= e($u['email']) ?></td>
                    <td><?= getRoleBadge($u) ?></td>
                    <td><?= number_format($u['post_count']) ?></td>
                    <td>
                        <?php if ($u['is_banned']): ?>
                            <span style="color:var(--red);font-size:0.8rem;font-weight:700"><i class="fas fa-ban"></i> Banned</span>
                        <?php else: ?>
                            <span style="color:var(--green);font-size:0.8rem;font-weight:700"><i class="fas fa-check"></i> Active</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--t2);font-size:0.8rem"><?= timeAgo($u['created_at']) ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <?php if (isAdmin()): ?>
                                <a href="?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if ($currentUser['can_ban'] && $u['id'] !== $currentUser['id']): ?>
                                <?php if ($u['is_banned']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                        <input type="hidden" name="action" value="unban">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-user-check"></i></button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline" onsubmit="document.getElementById('br<?= $u['id'] ?>').required=true;">
                                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                        <input type="hidden" name="action" value="ban">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="text" id="br<?= $u['id'] ?>" name="ban_reason" class="form-input" placeholder="Ban reason..." style="width:140px;font-size:0.78rem;padding:5px 8px;display:inline-block">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Ban this user?"><i class="fas fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (isAdmin() && $u['id'] !== $currentUser['id']): ?>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Permanently delete this user and all their posts?"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?= renderPagination($pag, SITE_URL . '/admin/users.php?search=' . urlencode($search) . '&filter=' . urlencode($filter)) ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
