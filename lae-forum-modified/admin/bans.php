<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Ban Management · Admin';
$message = '';
$error = '';

$isMod   = $currentUser['can_moderate'] ?? false;
$canPerm = $currentUser['can_ban'] ?? false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'ban') {
        $reason  = trim($_POST['ban_reason'] ?? 'No reason given');
        $expires = !empty($_POST['ban_expires']) ? $_POST['ban_expires'] : null;
        $isPerm  = ($expires === null);

        if ($isPerm && !$canPerm) {
            $error = 'You do not have permission to issue permanent bans. Please select a duration.';
        } elseif ($userId <= 0) {
            $error = 'Invalid user selected.';
        } else {
            // Prevent self-ban
            if ($userId === $currentUser['id']) {
                $error = 'You cannot ban yourself.';
            } else {
                $db->prepare("UPDATE users SET is_banned=1, ban_reason=?, ban_expires=? WHERE id=?")
                   ->execute([$reason, $expires, $userId]);
                $banType = $isPerm ? 'permanent ban' : 'temporary ban until ' . date('M j, Y', strtotime($expires));
                logAudit($currentUser['id'], 'ban_user', 'user', $userId, "Reason: $reason | $banType");
                $message = 'User banned successfully.';
            }
        }
    }

    if ($action === 'unban') {
        $db->prepare("UPDATE users SET is_banned=0, ban_reason=NULL, ban_expires=NULL WHERE id=?")->execute([$userId]);
        logAudit($currentUser['id'], 'unban_user', 'user', $userId, 'User unbanned');
        $message = 'User unbanned successfully.';
    }
}

// User search (AJAX or GET)
$searchUser = trim($_GET['search_user'] ?? '');
$foundUser  = null;
if ($searchUser) {
    $stmt = $db->prepare("SELECT u.*, r.display_name as role_display, r.color as role_color, r.badge_color FROM users u JOIN roles r ON u.role_id = r.id WHERE (u.username LIKE ? OR u.id = ?) AND u.id != ? LIMIT 1");
    $stmt->execute(["%$searchUser%", (int)$searchUser, $currentUser['id']]);
    $foundUser = $stmt->fetch();
}

// Ban list filters
$banFilter = $_GET['filter'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$whereClause = 'u.is_banned = 1';
if ($banFilter === 'temp')    $whereClause .= ' AND u.ban_expires IS NOT NULL AND u.ban_expires > NOW()';
if ($banFilter === 'perm')    $whereClause .= ' AND u.ban_expires IS NULL';
if ($banFilter === 'expired') $whereClause = 'u.is_banned = 1 AND u.ban_expires IS NOT NULL AND u.ban_expires < NOW()';

$total = (int)$db->query("SELECT COUNT(*) FROM users u WHERE $whereClause")->fetchColumn();
$pag = paginate($total, 20, $page);
$banned = $db->prepare("
    SELECT u.*, r.display_name as role_display, r.color as role_color, r.badge_color,
           bu.username as banned_by_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN audit_log al ON al.target_id = u.id AND al.action = 'ban_user'
        AND al.id = (SELECT MAX(id) FROM audit_log WHERE target_id = u.id AND action = 'ban_user')
    LEFT JOIN users bu ON al.user_id = bu.id
    WHERE $whereClause
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
");
$banned->execute([$pag['per_page'], $pag['offset']]);
$banned = $banned->fetchAll();

// Stats
$tempCount    = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_banned=1 AND ban_expires IS NOT NULL AND ban_expires > NOW()")->fetchColumn();
$permCount    = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_banned=1 AND ban_expires IS NULL")->fetchColumn();
$appealsCount = (int)$db->query("SELECT COUNT(*) FROM ban_appeals WHERE status='pending'")->fetchColumn();
$totalBanned  = $tempCount + $permCount;

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-ban" style="color:var(--red)"></i> Ban Management</h1>
            <?php if (!$canPerm): ?>
            <div style="font-size:0.8rem;color:var(--gold);background:rgba(243,156,18,0.1);border:1px solid rgba(243,156,18,0.3);padding:6px 14px;border-radius:var(--r);display:flex;align-items:center;gap:6px">
                <i class="fas fa-shield-halved"></i> Moderator — temporary bans only
            </div>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <!-- Stats row -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
            <div class="card" style="padding:16px;text-align:center;border-color:rgba(243,156,18,0.3)">
                <div style="font-family:var(--font-head);font-size:2.2rem;color:var(--gold);line-height:1"><?= $tempCount ?></div>
                <div style="font-size:0.7rem;color:var(--t2);text-transform:uppercase;letter-spacing:2px;margin-top:4px">Temp Bans</div>
            </div>
            <div class="card" style="padding:16px;text-align:center;border-color:rgba(231,76,60,0.3)">
                <div style="font-family:var(--font-head);font-size:2.2rem;color:var(--red);line-height:1"><?= $permCount ?></div>
                <div style="font-size:0.7rem;color:var(--t2);text-transform:uppercase;letter-spacing:2px;margin-top:4px">Perm Bans</div>
            </div>
            <div class="card" style="padding:16px;text-align:center;border-color:rgba(149,165,166,0.2)">
                <div style="font-family:var(--font-head);font-size:2.2rem;color:var(--t1);line-height:1"><?= $totalBanned ?></div>
                <div style="font-size:0.7rem;color:var(--t2);text-transform:uppercase;letter-spacing:2px;margin-top:4px">Total Banned</div>
            </div>
            <a href="<?= SITE_URL ?>/admin/appeals.php" class="card" style="padding:16px;text-align:center;border-color:rgba(253,121,168,0.3);text-decoration:none;display:block;transition:border-color 0.2s" onmouseover="this.style.borderColor='rgba(253,121,168,0.6)'" onmouseout="this.style.borderColor='rgba(253,121,168,0.3)'">
                <div style="font-family:var(--font-head);font-size:2.2rem;color:#fd79a8;line-height:1"><?= $appealsCount ?></div>
                <div style="font-size:0.7rem;color:var(--t2);text-transform:uppercase;letter-spacing:2px;margin-top:4px">Pending Appeals</div>
            </a>
        </div>

        <div style="display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start">

            <!-- ===== BAN LIST ===== -->
            <div>
                <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
                    <span style="font-size:0.75rem;color:var(--t2);text-transform:uppercase;letter-spacing:2px;margin-right:4px">Filter:</span>
                    <?php $filters = ['all'=>'All','temp'=>'Temporary','perm'=>'Permanent','expired'=>'Expired']; ?>
                    <?php foreach ($filters as $f => $label): ?>
                    <a href="?filter=<?= $f ?>" class="btn <?= $banFilter===$f ? 'btn-accent' : 'btn-ghost' ?> btn-sm"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr><th>User</th><th>Reason</th><th>By</th><th>Expires</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banned as $u): ?>
                        <?php $isExpired = $u['ban_expires'] && strtotime($u['ban_expires']) < time(); ?>
                        <tr <?= $isExpired ? 'style="opacity:0.55"' : '' ?>>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <img src="<?= e(getAvatarUrl($u['avatar'], $u['username'])) ?>" style="width:28px;height:28px;border-radius:50%;opacity:0.7">
                                    <div>
                                        <div style="font-weight:700;color:var(--red);font-size:0.9rem"><?= e($u['username']) ?></div>
                                        <div style="font-size:0.68rem;color:var(--t2)"><?= e($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:0.8rem;color:var(--t1);max-width:160px;word-break:break-word">
                                <?= e(substr($u['ban_reason'] ?? 'No reason', 0, 70)) ?>
                            </td>
                            <td style="font-size:0.78rem;color:var(--t2)"><?= $u['banned_by_name'] ? e($u['banned_by_name']) : '—' ?></td>
                            <td style="font-size:0.78rem;white-space:nowrap">
                                <?php if ($u['ban_expires']): ?>
                                    <?= $isExpired
                                        ? '<span style="color:var(--t2)">Expired<br>' . date('M j, Y', strtotime($u['ban_expires'])) . '</span>'
                                        : '<span style="color:var(--gold)">' . date('M j, Y', strtotime($u['ban_expires'])) . '</span>' ?>
                                <?php else: ?>
                                    <span style="color:var(--red);font-weight:700;font-size:0.75rem;letter-spacing:1px">PERMANENT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm" data-confirm="Unban <?= e($u['username']) ?>?">
                                        <i class="fas fa-user-check"></i> Unban
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($banned)): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--t2);padding:40px">No users in this category.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?= renderPagination($pag, SITE_URL . '/admin/bans.php?filter=' . $banFilter) ?>
            </div>

            <!-- ===== ISSUE BAN PANEL ===== -->
            <div style="display:grid;gap:16px">
                <div class="card" style="border-color:rgba(231,76,60,0.4)">
                    <div class="card-header" style="background:rgba(231,76,60,0.08)">
                        <h3><i class="fas fa-ban" style="color:var(--red)"></i> Issue Ban</h3>
                    </div>
                    <div style="padding:20px;display:grid;gap:16px">

                        <!-- Step 1: Find user -->
                        <div>
                            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:2px;color:var(--t2);margin-bottom:8px">
                                <span style="background:var(--red);color:#000;padding:1px 7px;border-radius:3px;font-weight:700;margin-right:6px">1</span>Find User
                            </div>
                            <form method="GET" style="display:flex;gap:8px">
                                <input type="hidden" name="filter" value="<?= e($banFilter) ?>">
                                <input type="text" name="search_user" class="form-input"
                                       placeholder="Username or user ID…"
                                       value="<?= e($searchUser) ?>" autocomplete="off">
                                <button type="submit" class="btn btn-ghost" style="white-space:nowrap"><i class="fas fa-search"></i></button>
                            </form>

                            <?php if ($searchUser && !$foundUser): ?>
                            <div style="margin-top:8px;font-size:0.82rem;color:var(--red)"><i class="fas fa-circle-exclamation"></i> No user found for "<?= e($searchUser) ?>".</div>
                            <?php endif; ?>

                            <?php if ($foundUser): ?>
                            <div style="margin-top:10px;background:var(--bg1);border:1px solid var(--b0);border-radius:var(--r);padding:10px 12px;display:flex;align-items:center;gap:10px">
                                <img src="<?= e(getAvatarUrl($foundUser['avatar'], $foundUser['username'])) ?>" style="width:36px;height:36px;border-radius:50%">
                                <div style="flex:1">
                                    <div style="font-weight:700;font-size:0.95rem"><?= e($foundUser['username']) ?></div>
                                    <div><?= getRoleBadge($foundUser) ?></div>
                                </div>
                                <?php if ($foundUser['is_banned']): ?>
                                <span style="font-size:0.75rem;color:var(--red);font-weight:700"><i class="fas fa-ban"></i> Banned</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Step 2 + 3: Ban form (only shown when user found and not already banned) -->
                        <?php if ($foundUser && !$foundUser['is_banned']): ?>
                        <form method="POST" id="ban-form" style="display:grid;gap:14px;padding-top:4px;border-top:1px solid var(--b0)">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="ban">
                            <input type="hidden" name="user_id" value="<?= $foundUser['id'] ?>">

                            <!-- Step 2: Reason -->
                            <div>
                                <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:2px;color:var(--t2);margin-bottom:8px">
                                    <span style="background:var(--red);color:#000;padding:1px 7px;border-radius:3px;font-weight:700;margin-right:6px">2</span>Ban Reason
                                </div>
                                <textarea name="ban_reason" class="form-textarea" style="min-height:75px"
                                    placeholder="Format: Category (Specific behaviour)&#10;e.g. FailRP (Ignoring Gunshot Wound RP)" required></textarea>
                            </div>

                            <!-- Step 3: Duration -->
                            <div>
                                <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:2px;color:var(--t2);margin-bottom:10px">
                                    <span style="background:var(--red);color:#000;padding:1px 7px;border-radius:3px;font-weight:700;margin-right:6px">3</span>Duration
                                    <?php if (!$canPerm): ?>
                                    <span style="color:var(--red);font-size:0.68rem;margin-left:4px">(permanent requires Admin+)</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Duration grid -->
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px" id="duration-grid">
                                    <?php
                                    $durations = [
                                        ['label'=>'1 Hour',   'hours'=>1,    'val'=>date('Y-m-d H:i:s', strtotime('+1 hour'))],
                                        ['label'=>'6 Hours',  'hours'=>6,    'val'=>date('Y-m-d H:i:s', strtotime('+6 hours'))],
                                        ['label'=>'12 Hours', 'hours'=>12,   'val'=>date('Y-m-d H:i:s', strtotime('+12 hours'))],
                                        ['label'=>'1 Day',    'hours'=>24,   'val'=>date('Y-m-d',        strtotime('+1 day'))],
                                        ['label'=>'3 Days',   'hours'=>72,   'val'=>date('Y-m-d',        strtotime('+3 days'))],
                                        ['label'=>'1 Week',   'hours'=>168,  'val'=>date('Y-m-d',        strtotime('+7 days'))],
                                        ['label'=>'2 Weeks',  'hours'=>336,  'val'=>date('Y-m-d',        strtotime('+14 days'))],
                                        ['label'=>'1 Month',  'hours'=>720,  'val'=>date('Y-m-d',        strtotime('+30 days'))],
                                        ['label'=>'3 Months', 'hours'=>2160, 'val'=>date('Y-m-d',        strtotime('+90 days'))],
                                    ];
                                    if ($canPerm) $durations[] = ['label'=>'Permanent', 'hours'=>0, 'val'=>''];
                                    foreach ($durations as $d):
                                        $isPermanent = $d['hours'] === 0;
                                    ?>
                                    <button type="button"
                                        class="btn btn-ghost btn-sm duration-btn"
                                        data-val="<?= e($d['val']) ?>"
                                        style="<?= $isPermanent ? 'color:var(--red);border-color:rgba(231,76,60,0.4);' : '' ?>">
                                        <?= $d['label'] ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Custom date fallback -->
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span style="font-size:0.75rem;color:var(--t2);white-space:nowrap">Custom date:</span>
                                    <input type="date" name="ban_expires" id="ban-expires-input" class="form-input"
                                           style="flex:1"
                                           min="<?= date('Y-m-d', strtotime('+1 hour')) ?>"
                                           <?= !$canPerm ? 'required' : '' ?>>
                                </div>
                                <div id="ban-duration-display" style="margin-top:6px;font-size:0.8rem;color:var(--t2);min-height:18px"></div>
                            </div>

                            <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center;padding:10px"
                                    data-confirm="Ban <?= e($foundUser['username']) ?>?">
                                <i class="fas fa-ban"></i> Issue Ban on <?= e($foundUser['username']) ?>
                            </button>
                        </form>

                        <?php elseif ($foundUser && $foundUser['is_banned']): ?>
                        <div style="padding-top:12px;border-top:1px solid var(--b0)">
                            <div class="alert alert-warning" style="margin:0">
                                <i class="fas fa-circle-info"></i>
                                <div><?= e($foundUser['username']) ?> is already banned. Use the <strong>Unban</strong> button in the table to lift the ban first.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Permissions card -->
                <div class="card" style="border-color:rgba(52,152,219,0.25)">
                    <div class="card-header"><h3 style="font-size:0.9rem"><i class="fas fa-shield-halved" style="color:#3498db"></i> Your Permissions</h3></div>
                    <div style="padding:12px 16px;font-size:0.82rem;color:var(--t1);line-height:2.2">
                        <div><i class="fas fa-check" style="color:var(--green);width:16px"></i> Search &amp; find users</div>
                        <div><i class="fas fa-check" style="color:var(--green);width:16px"></i> Issue temporary bans (hours/days)</div>
                        <div><i class="fas fa-<?= $canPerm ? 'check' : 'times' ?>" style="color:var(--<?= $canPerm ? 'green' : 'red' ?>);width:16px"></i> Issue permanent bans <?= !$canPerm ? '<span style="color:var(--t2)">(Admin+)</span>' : '' ?></div>
                        <div><i class="fas fa-check" style="color:var(--green);width:16px"></i> Unban users</div>
                        <div><i class="fas fa-check" style="color:var(--green);width:16px"></i> Review ban appeals</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const durationBtns  = document.querySelectorAll('.duration-btn');
const expiresInput  = document.getElementById('ban-expires-input');
const durationLabel = document.getElementById('ban-duration-display');

durationBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        // Highlight selected
        durationBtns.forEach(b => b.classList.remove('btn-accent'));
        durationBtns.forEach(b => b.classList.add('btn-ghost'));
        btn.classList.remove('btn-ghost');
        btn.classList.add('btn-accent');

        const val = btn.dataset.val;
        expiresInput.value = val ? val.substring(0, 10) : '';

        if (!val) {
            durationLabel.innerHTML = '<span style="color:var(--red);font-weight:700">⚠ Permanent ban — user will not be automatically unbanned</span>';
        } else {
            const d = new Date(val);
            durationLabel.textContent = 'Ban expires: ' + d.toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'long', year:'numeric'});
        }
    });
});

// Update label when date is typed manually
expiresInput.addEventListener('change', () => {
    durationBtns.forEach(b => { b.classList.remove('btn-accent'); b.classList.add('btn-ghost'); });
    if (expiresInput.value) {
        const d = new Date(expiresInput.value);
        durationLabel.textContent = 'Ban expires: ' + d.toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'long', year:'numeric'});
    } else {
        durationLabel.textContent = '';
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
