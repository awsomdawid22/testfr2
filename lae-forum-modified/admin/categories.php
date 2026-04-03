<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Categories · Admin';
$message = '';
$error = '';

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $icon  = trim($_POST['icon'] ?? 'fas fa-folder');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#e91e8c';
        $slug  = slug($name);
        $sort  = (int)($_POST['sort_order'] ?? 99);

        if (!$name) { $error = 'Name is required.'; }
        else {
            $check = $db->prepare("SELECT id FROM categories WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) $slug .= '-' . time();
            $db->prepare("INSERT INTO categories (name, description, slug, icon, color, sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$name, $desc, $slug, $icon, $color, $sort]);
            logAudit($currentUser['id'], 'create_category', 'category', $db->lastInsertId(), $name);
            $message = 'Category created.';
        }
    }

    if ($action === 'update') {
        $id    = (int)$_POST['cat_id'];
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $icon  = trim($_POST['icon'] ?? 'fas fa-folder');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#e91e8c';
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $vis   = isset($_POST['is_visible']) ? 1 : 0;

        $db->prepare("UPDATE categories SET name=?, description=?, icon=?, color=?, sort_order=?, is_visible=? WHERE id=?")
           ->execute([$name, $desc, $icon, $color, $sort, $vis, $id]);
        logAudit($currentUser['id'], 'update_category', 'category', $id, $name);
        $message = 'Category updated.';
    }

    if ($action === 'delete') {
        $id = (int)$_POST['cat_id'];
        $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        logAudit($currentUser['id'], 'delete_category', 'category', $id);
        $message = 'Category deleted.';
    }

    // ── Save per-category role permissions ──────────────────────────────────
    if ($action === 'save_permissions') {
        $catId = (int)$_POST['cat_id'];

        // Delete all existing rules for this category first
        $db->prepare("DELETE FROM category_permissions WHERE category_id = ?")->execute([$catId]);

        $mode = $_POST['perm_mode'] ?? 'global'; // 'global' = use role defaults, 'custom' = use table

        if ($mode === 'custom') {
            // Read submitted role checkboxes
            $postRoles   = $_POST['perm_post']   ?? [];
            $threadRoles = $_POST['perm_thread']  ?? [];

            // Get all unique role IDs mentioned in either list
            $allRoleIds = array_unique(array_merge(
                array_map('intval', $postRoles),
                array_map('intval', $threadRoles)
            ));

            // Also get ALL roles to insert rows for ones explicitly denied
            $allRoles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
            $allRoleIds = array_unique(array_merge($allRoleIds, $allRoles));

            $stmt = $db->prepare("INSERT INTO category_permissions (category_id, role_id, can_post, can_create_threads) VALUES (?,?,?,?)");
            foreach ($allRoleIds as $roleId) {
                $roleId = (int)$roleId;
                $canPost   = in_array($roleId, array_map('intval', $postRoles))   ? 1 : 0;
                $canThread = in_array($roleId, array_map('intval', $threadRoles)) ? 1 : 0;
                // Only save rows where at least one permission is relevant (skip all-zero non-mod rows unless they exist)
                $stmt->execute([$catId, $roleId, $canPost, $canThread]);
            }
            logAudit($currentUser['id'], 'update_category_permissions', 'category', $catId, "Custom permissions set");
            $message = 'Permissions saved.';
        } else {
            // mode = global — just deleted above, nothing more to insert
            logAudit($currentUser['id'], 'update_category_permissions', 'category', $catId, "Permissions reset to global");
            $message = 'Permissions reset to global role defaults.';
        }

        // Stay on the permissions tab
        header('Location: ' . SITE_URL . '/admin/categories.php?perms=' . $catId . '&msg=' . urlencode($message));
        exit;
    }
}

if (isset($_GET['msg'])) $message = $_GET['msg'];

$categories = $db->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
$allRoles    = $db->query("SELECT * FROM roles ORDER BY priority DESC")->fetchAll();

$editCat  = null;
$permsCat = null;

if (isset($_GET['edit'])) {
    foreach ($categories as $c) {
        if ($c['id'] == (int)$_GET['edit']) { $editCat = $c; break; }
    }
}
if (isset($_GET['perms'])) {
    foreach ($categories as $c) {
        if ($c['id'] == (int)$_GET['perms']) { $permsCat = $c; break; }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-folder" style="color:var(--red)"></i> Categories</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <!-- ── PERMISSIONS PANEL ──────────────────────────────────────────── -->
        <?php if ($permsCat): ?>
        <?php
            $existingPerms = getCategoryPermissions($permsCat['id']);
            $hasCustomPerms = count($existingPerms) > 0;
        ?>
        <div class="card" style="margin-bottom:20px;border-color:var(--red)">
            <div class="card-header">
                <h3><i class="fas fa-shield-halved" style="color:var(--red)"></i>
                    Role Permissions —
                    <span style="color:var(--red)"><?= e($permsCat['name']) ?></span>
                </h3>
                <a href="<?= SITE_URL ?>/admin/categories.php" class="btn btn-ghost btn-sm">← Back to Categories</a>
            </div>
            <div style="padding:20px">

                <div class="alert alert-info" style="margin-bottom:20px">
                    <i class="fas fa-info-circle"></i>
                    <div style="font-size:0.88rem">
                        <strong>Global mode</strong> uses each role's own post/thread permissions (the default). <strong>Custom mode</strong> overrides those — only the roles you tick will be able to post or create threads here. Moderators and above always bypass category restrictions.
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="save_permissions">
                    <input type="hidden" name="cat_id" value="<?= $permsCat['id'] ?>">

                    <!-- Mode toggle -->
                    <div style="display:flex;gap:12px;margin-bottom:24px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 16px;border-radius:var(--r);border:1px solid var(--b0);flex:1;<?= !$hasCustomPerms ? 'border-color:var(--red);background:rgba(233,30,140,0.07)' : '' ?>">
                            <input type="radio" name="perm_mode" value="global" <?= !$hasCustomPerms ? 'checked' : '' ?> id="mode_global" style="accent-color:var(--red)">
                            <div>
                                <div style="font-weight:700;font-size:0.9rem">Global Mode</div>
                                <div style="font-size:0.75rem;color:var(--t2)">Use each role's own permissions</div>
                            </div>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 16px;border-radius:var(--r);border:1px solid var(--b0);flex:1;<?= $hasCustomPerms ? 'border-color:var(--red);background:rgba(233,30,140,0.07)' : '' ?>">
                            <input type="radio" name="perm_mode" value="custom" <?= $hasCustomPerms ? 'checked' : '' ?> id="mode_custom" style="accent-color:var(--red)">
                            <div>
                                <div style="font-weight:700;font-size:0.9rem">Custom Mode</div>
                                <div style="font-size:0.75rem;color:var(--t2)">Pick exactly which roles can post/create threads here</div>
                            </div>
                        </label>
                    </div>

                    <!-- Role permission grid (shown in custom mode) -->
                    <div id="custom-perms-panel" style="<?= !$hasCustomPerms ? 'display:none' : '' ?>">
                        <table class="admin-table" style="margin-bottom:20px">
                            <thead>
                                <tr>
                                    <th style="width:50%">Role</th>
                                    <th style="text-align:center;width:25%">
                                        <i class="fas fa-comment" style="color:var(--red);margin-right:4px"></i>
                                        Can Post Replies
                                    </th>
                                    <th style="text-align:center;width:25%">
                                        <i class="fas fa-plus-circle" style="color:var(--red);margin-right:4px"></i>
                                        Can Create Threads
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allRoles as $role): ?>
                                <?php
                                    $perm = $existingPerms[$role['id']] ?? null;
                                    $chkPost   = $perm ? (bool)$perm['can_post']           : (bool)$role['can_post'];
                                    $chkThread = $perm ? (bool)$perm['can_create_threads'] : (bool)$role['can_create_threads'];
                                    $isMod = $role['can_moderate'] || $role['can_admin'];
                                ?>
                                <tr <?= $isMod ? 'style="opacity:0.55"' : '' ?>>
                                    <td>
                                        <span style="background:<?= e($role['badge_color']) ?>;color:<?= e($role['color']) ?>;padding:2px 8px;border-radius:3px;font-size:0.78rem;font-weight:700">
                                            <?= e($role['display_name']) ?>
                                        </span>
                                        <?php if ($isMod): ?>
                                        <span style="font-size:0.7rem;color:var(--t2);margin-left:6px">always allowed (mod+)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center">
                                        <?php if ($isMod): ?>
                                            <i class="fas fa-check" style="color:var(--green)"></i>
                                        <?php else: ?>
                                            <input type="checkbox" name="perm_post[]" value="<?= $role['id'] ?>"
                                                   <?= $chkPost ? 'checked' : '' ?>
                                                   style="width:18px;height:18px;accent-color:var(--red);cursor:pointer">
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center">
                                        <?php if ($isMod): ?>
                                            <i class="fas fa-check" style="color:var(--green)"></i>
                                        <?php else: ?>
                                            <input type="checkbox" name="perm_thread[]" value="<?= $role['id'] ?>"
                                                   <?= $chkThread ? 'checked' : '' ?>
                                                   style="width:18px;height:18px;accent-color:var(--red);cursor:pointer">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Quick preset buttons -->
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
                            <span style="font-size:0.75rem;color:var(--t2);align-self:center;margin-right:4px">Presets:</span>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="setPreset('all')"><i class="fas fa-users"></i> All Roles</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="setPreset('none')"><i class="fas fa-ban"></i> None</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="setPreset('staff')"><i class="fas fa-shield-halved"></i> Staff Only</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="setPreset('members')"><i class="fas fa-user"></i> Members+</button>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px">
                        <button type="submit" class="btn btn-accent"><i class="fas fa-save"></i> Save Permissions</button>
                        <a href="<?= SITE_URL ?>/admin/categories.php" class="btn btn-ghost">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        // Show/hide custom panel based on radio
        document.querySelectorAll('input[name="perm_mode"]').forEach(r => {
            r.addEventListener('change', () => {
                document.getElementById('custom-perms-panel').style.display =
                    document.getElementById('mode_custom').checked ? '' : 'none';
            });
        });

        // Role data for presets
        const roleData = <?php
            $roleJson = [];
            foreach ($allRoles as $r) {
                $roleJson[] = [
                    'id' => $r['id'],
                    'canPost' => (bool)$r['can_post'],
                    'canThread' => (bool)$r['can_create_threads'],
                    'isMod' => (bool)($r['can_moderate'] || $r['can_admin']),
                    'priority' => (int)$r['priority'],
                    'name' => $r['name'],
                ];
            }
            echo json_encode($roleJson);
        ?>;

        // Staff role names (priority >= 50 in your schema = moderator+)
        const staffPriority = 50;
        const memberPriority = 10; // 'member' role

        function setPreset(type) {
            document.getElementById('mode_custom').checked = true;
            document.getElementById('custom-perms-panel').style.display = '';

            roleData.forEach(role => {
                if (role.isMod) return; // skip mod rows — always allowed
                const postCb   = document.querySelector(`input[name="perm_post[]"][value="${role.id}"]`);
                const threadCb = document.querySelector(`input[name="perm_thread[]"][value="${role.id}"]`);
                if (!postCb || !threadCb) return;

                let allow = false;
                if (type === 'all')     allow = true;
                if (type === 'none')    allow = false;
                if (type === 'staff')   allow = role.priority >= staffPriority;
                if (type === 'members') allow = role.priority >= memberPriority;

                postCb.checked   = allow;
                threadCb.checked = allow;
            });
        }
        </script>

        <?php else: ?>

        <!-- ── EDIT FORM ───────────────────────────────────────────────────── -->
        <?php if ($editCat): ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h3><i class="fas fa-pen"></i> Edit Category — <?= e($editCat['name']) ?></h3>
                <a href="<?= SITE_URL ?>/admin/categories.php" class="btn btn-ghost btn-sm">← Cancel</a>
            </div>
            <div style="padding:20px">
                <form method="POST" style="display:grid;gap:14px">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="cat_id" value="<?= $editCat['id'] ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-input" value="<?= e($editCat['name']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-input" value="<?= e($editCat['sort_order']) ?>" min="0">
                        </div>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-input" value="<?= e($editCat['description'] ?? '') ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Icon Class</label>
                            <input type="text" name="icon" class="form-input" value="<?= e($editCat['icon']) ?>" placeholder="fas fa-folder">
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Colour</label>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="color" name="color" value="<?= e($editCat['color']) ?>" style="width:40px;height:38px;border:1px solid var(--b0);border-radius:var(--r);cursor:pointer;background:none;padding:2px">
                                <input type="text" id="color_text" class="form-input" value="<?= e($editCat['color']) ?>" style="flex:1" placeholder="#e91e8c">
                            </div>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Visible</label>
                            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;cursor:pointer">
                                <input type="checkbox" name="is_visible" <?= $editCat['is_visible'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--red)">
                                <span style="font-size:0.88rem">Show to members</span>
                            </label>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <button type="submit" class="btn btn-accent"><i class="fas fa-save"></i> Save Changes</button>
                        <a href="<?= SITE_URL ?>/admin/categories.php?perms=<?= $editCat['id'] ?>" class="btn btn-ghost"><i class="fas fa-shield-halved"></i> Manage Permissions</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

            <!-- Category list -->
            <div>
                <table class="admin-table">
                    <thead><tr><th>Category</th><th>Threads</th><th>Permissions</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <?php
                            $catPerms = getCategoryPermissions($cat['id']);
                            $hasCustom = count($catPerms) > 0;
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <i class="<?= e($cat['icon']) ?>" style="color:<?= e($cat['color']) ?>;width:16px;text-align:center"></i>
                                    <div>
                                        <div style="font-weight:700"><?= e($cat['name']) ?></div>
                                        <div style="font-size:0.72rem;color:var(--t2)">/<?= e($cat['slug']) ?> <?= $cat['is_visible'] ? '' : '· <span style="color:var(--red)">Hidden</span>' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:0.85rem;color:var(--t2)"><?= number_format($cat['thread_count']) ?></td>
                            <td>
                                <?php if ($hasCustom): ?>
                                <span style="background:rgba(233,30,140,0.12);color:var(--red);border:1px solid rgba(233,30,140,0.3);padding:2px 8px;border-radius:3px;font-size:0.72rem;font-weight:700">
                                    <i class="fas fa-shield-halved" style="font-size:0.65rem"></i> Custom
                                </span>
                                <?php else: ?>
                                <span style="color:var(--t2);font-size:0.78rem">Global</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <a href="?edit=<?= $cat['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-pen"></i> Edit</a>
                                    <a href="?perms=<?= $cat['id'] ?>" class="btn btn-ghost btn-sm" style="color:var(--red)"><i class="fas fa-shield-halved"></i> Permissions</a>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red)" data-confirm="Delete '<?= e($cat['name']) ?>'? All threads inside will be deleted.">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Create new category -->
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-plus"></i> New Category</h3></div>
                <div style="padding:16px">
                    <form method="POST" style="display:grid;gap:12px">
                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-input" placeholder="e.g. General Discussion" required>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-input" placeholder="Short description">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Icon</label>
                                <input type="text" name="icon" class="form-input" value="fas fa-folder" placeholder="fas fa-folder">
                            </div>
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Colour</label>
                                <input type="color" name="color" value="#e91e8c" style="width:100%;height:38px;border:1px solid var(--b0);border-radius:var(--r);cursor:pointer;background:none;padding:2px">
                            </div>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-input" value="99" min="0">
                        </div>
                        <div style="font-size:0.78rem;color:var(--t2);background:var(--bg1);padding:10px 12px;border-radius:var(--r)">
                            <i class="fas fa-info-circle" style="color:var(--red)"></i>
                            After creating, use <strong>Permissions</strong> to control which roles can post.
                        </div>
                        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Create Category</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
