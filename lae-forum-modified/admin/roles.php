<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Roles & Permissions · Admin';
$message = '';
$error = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_role') {
        $roleId = (int)$_POST['role_id'];
        $animation = trim($_POST['animation'] ?? '');
        $validAnimations = ['', 'glow', 'shimmer', 'pulse', 'rainbow', 'sparkle', 'fire', 'ice', 'electric', 'shadow', 'gold'];
        $animation = in_array($animation, $validAnimations) ? $animation : '';
        
        $fields = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'color'        => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#ffffff',
            'badge_color'  => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['badge_color'] ?? '') ? $_POST['badge_color'] : '#111111',
            'animation'    => $animation ?: null,
            'priority'     => (int)($_POST['priority'] ?? 0),
            'can_post'     => isset($_POST['can_post']) ? 1 : 0,
            'can_create_threads' => isset($_POST['can_create_threads']) ? 1 : 0,
            'can_moderate' => isset($_POST['can_moderate']) ? 1 : 0,
            'can_admin'    => isset($_POST['can_admin']) ? 1 : 0,
            'can_ban'      => isset($_POST['can_ban']) ? 1 : 0,
        ];
        $db->prepare("UPDATE roles SET display_name=?, color=?, badge_color=?, animation=?, priority=?, can_post=?, can_create_threads=?, can_moderate=?, can_admin=?, can_ban=? WHERE id=?")
           ->execute(array_merge(array_values($fields), [$roleId]));
        logAudit($currentUser['id'], 'update_role', 'role', $roleId, 'Role settings updated');
        $message = 'Role updated.';
    } elseif ($action === 'create_role') {
        $name = trim(strtolower(preg_replace('/[^a-z0-9_]/', '_', $_POST['name'] ?? '')));
        $display = trim($_POST['display_name'] ?? '');
        $createAnimation = trim($_POST['animation'] ?? '');
        $validAnimations = ['', 'glow', 'shimmer', 'pulse', 'rainbow', 'sparkle', 'fire', 'ice', 'electric', 'shadow', 'gold'];
        $createAnimation = in_array($createAnimation, $validAnimations) ? $createAnimation : '';
        
        if ($name && $display) {
            $db->prepare("INSERT INTO roles (name, display_name, color, badge_color, animation, priority) VALUES (?,?,?,?,?,?)")
               ->execute([$name, $display, $_POST['color'] ?? '#ffffff', $_POST['badge_color'] ?? '#111', $createAnimation ?: null, (int)($_POST['priority'] ?? 5)]);
            $message = 'Role created.';
        } else { $error = 'Name and display name are required.'; }
    }
}

$roles = $db->query("SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count FROM roles r ORDER BY priority DESC")->fetchAll();
$editRole = null;
if (isset($_GET['edit'])) {
    foreach ($roles as $r) { if ($r['id'] == $_GET['edit']) { $editRole = $r; break; } }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-shield-halved" style="color:var(--red)"></i> Roles & Permissions</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($editRole): ?>
        <div class="card" style="margin-bottom:20px;border-color:var(--red)">
            <div class="card-header">
                <h3><i class="fas fa-pen"></i> Editing: <?= e($editRole['display_name']) ?></h3>
                <a href="<?= SITE_URL ?>/admin/roles.php" class="btn btn-ghost btn-sm">Cancel</a>
            </div>
            <form method="POST" style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="role_id" value="<?= $editRole['id'] ?>">

                <div class="form-group">
                    <label class="form-label">Display Name</label>
                    <input type="text" name="display_name" class="form-input" value="<?= e($editRole['display_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority (higher = more important)</label>
                    <input type="number" name="priority" class="form-input" value="<?= $editRole['priority'] ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Text Color</label>
                    <div style="display:flex;gap:8px">
                        <input type="color" name="color" value="<?= e($editRole['color']) ?>" style="width:50px;height:38px;border:1px solid var(--b0);background:none;cursor:pointer;border-radius:var(--r)">
                        <input type="text" value="<?= e($editRole['color']) ?>" class="form-input" readonly id="colorText" style="font-family:var(--font-mono)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Badge Background Color</label>
                    <div style="display:flex;gap:8px">
                        <input type="color" name="badge_color" value="<?= e($editRole['badge_color']) ?>" style="width:50px;height:38px;border:1px solid var(--b0);background:none;cursor:pointer;border-radius:var(--r)">
                        <input type="text" value="<?= e($editRole['badge_color']) ?>" class="form-input" readonly id="badgeColorText" style="font-family:var(--font-mono)">
                    </div>
                </div>

                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Badge Animation</label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-top:8px">
                        <?php 
                        $animations = [
                            '' => 'None',
                            'glow' => 'Glow',
                            'shimmer' => 'Shimmer',
                            'pulse' => 'Pulse',
                            'rainbow' => 'Rainbow',
                            'sparkle' => 'Sparkle',
                            'fire' => 'Fire',
                            'ice' => 'Ice',
                            'electric' => 'Electric',
                            'shadow' => 'Shadow',
                            'gold' => 'Gold'
                        ];
                        $currentAnim = $editRole['animation'] ?? '';
                        foreach ($animations as $value => $label): 
                        ?>
                        <label class="anim-option <?= $currentAnim === $value ? 'selected' : '' ?>">
                            <input type="radio" name="animation" value="<?= $value ?>" <?= $currentAnim === $value ? 'checked' : '' ?> style="display:none">
                            <span class="role-badge role-anim-<?= $value ?>" style="color:<?= e($editRole['color']) ?>;background:<?= e($editRole['badge_color']) ?>;border-color:<?= e($editRole['color']) ?>"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="grid-column:1/-1">
                    <label class="form-label">Permissions</label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:8px">
                        <?php
                        $perms = ['can_post'=>'Can Post','can_create_threads'=>'Can Create Threads','can_moderate'=>'Can Moderate','can_admin'=>'Is Admin','can_ban'=>'Can Ban Users'];
                        foreach ($perms as $field => $label): ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem">
                            <input type="checkbox" name="<?= $field ?>" style="accent-color:var(--red)" <?= $editRole[$field] ? 'checked' : '' ?>>
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="grid-column:1/-1">
                    <button type="submit" class="btn btn-accent"><i class="fas fa-save"></i> Save Role</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 350px;gap:20px">
            <!-- Roles Table -->
            <div>
                <table class="admin-table">
                    <thead><tr><th>Role</th><th>Users</th><th>Priority</th><th>Permissions</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                        <tr>
                            <td>
                                <?= getRoleBadge($role) ?>
                                <div style="font-size:0.72rem;color:var(--t2);margin-top:3px;font-family:var(--font-mono)"><?= e($role['name']) ?></div>
                            </td>
                            <td style="font-family:var(--font-head);font-size:1.2rem;color:var(--red)"><?= $role['user_count'] ?></td>
                            <td><?= $role['priority'] ?></td>
                            <td style="font-size:0.75rem">
                                <?php
                                $icons = ['can_post'=>['fa-message','Post'],'can_create_threads'=>['fa-plus','Thread'],'can_moderate'=>['fa-shield','Mod'],'can_admin'=>['fa-cog','Admin'],'can_ban'=>['fa-ban','Ban']];
                                foreach ($icons as $f => [$icon, $label]):
                                    $active = $role[$f] ? 'var(--green)' : 'var(--b0)';
                                ?>
                                <span style="color:<?= $active ?>" title="<?= $label ?>"><i class="fas <?= $icon ?>"></i></span>
                                <?php endforeach; ?>
                            </td>
                            <td><a href="?edit=<?= $role['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-pen"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Create new role -->
            <div class="card" style="height:fit-content">
                <div class="card-header"><h3><i class="fas fa-plus"></i> New Role</h3></div>
                <form method="POST" style="padding:16px;display:grid;gap:12px">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="create_role">
                    <div class="form-group">
                        <label class="form-label">Internal Name</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g. vip_gold" required pattern="[a-zA-Z0-9_]+">
                        <div class="form-hint">Lowercase, letters/numbers/underscores only</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Display Name</label>
                        <input type="text" name="display_name" class="form-input" placeholder="e.g. VIP Gold" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="form-group">
                            <label class="form-label">Text Color</label>
                            <input type="color" name="color" value="#ffffff" style="width:100%;height:38px;border:1px solid var(--b0);background:none;cursor:pointer;border-radius:var(--r)">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Badge BG</label>
                            <input type="color" name="badge_color" value="#111111" style="width:100%;height:38px;border:1px solid var(--b0);background:none;cursor:pointer;border-radius:var(--r)">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <input type="number" name="priority" class="form-input" value="10" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Animation</label>
                        <select name="animation" class="form-select">
                            <option value="">None</option>
                            <option value="glow">Glow</option>
                            <option value="shimmer">Shimmer</option>
                            <option value="pulse">Pulse</option>
                            <option value="rainbow">Rainbow</option>
                            <option value="sparkle">Sparkle</option>
                            <option value="fire">Fire</option>
                            <option value="ice">Ice</option>
                            <option value="electric">Electric</option>
                            <option value="shadow">Shadow</option>
                            <option value="gold">Gold</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Create Role</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.anim-option {
    cursor: pointer;
    padding: 8px 12px;
    background: var(--bg1);
    border: 2px solid var(--b0);
    border-radius: var(--r);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--dur) var(--ease);
}
.anim-option:hover {
    border-color: var(--t1);
}
.anim-option.selected {
    border-color: var(--t0);
    background: rgba(255,255,255,0.05);
}
.anim-option input:checked + .role-badge {
    transform: scale(1.05);
}
</style>

<script>
document.querySelectorAll('.anim-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.anim-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
