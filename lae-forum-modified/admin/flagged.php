<?php
/**
 * Flagged Accounts Queue
 * Review and manage flagged user accounts
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();

$pageTitle = 'Flagged Accounts · Admin';
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($action === 'clear_flag') {
        $db->prepare("UPDATE users SET is_flagged = 0, flag_reason = NULL WHERE id = ?")->execute([$userId]);
        logAudit($currentUser['id'], 'clear_flag', 'user', $userId);
        $message = 'Flag cleared.';
    } elseif ($action === 'ban_user' && $currentUser['can_ban']) {
        $reason = trim($_POST['reason'] ?? 'Flagged account review');
        $db->prepare("UPDATE users SET is_banned = 1, ban_reason = ?, is_flagged = 0 WHERE id = ?")
           ->execute([$reason, $userId]);
        logAudit($currentUser['id'], 'ban_user', 'user', $userId, $reason);
        $message = 'User banned.';
    } elseif ($action === 'trust_user') {
        $db->prepare("UPDATE users SET is_trusted = 1, is_flagged = 0, flag_reason = NULL WHERE id = ?")->execute([$userId]);
        logAudit($currentUser['id'], 'trust_user', 'user', $userId);
        $message = 'User marked as trusted.';
    }
}

// Get flagged accounts
$flaggedUsers = $db->query("
    SELECT u.*, r.display_name as role_display, r.color as role_color,
           (SELECT COUNT(*) FROM automod_log WHERE user_id = u.id) as automod_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.is_flagged = 1
    ORDER BY u.created_at DESC
")->fetchAll();

// Get accounts with high risk scores
$highRiskUsers = $db->query("
    SELECT u.*, r.display_name as role_display, r.color as role_color,
           (SELECT COUNT(*) FROM automod_log WHERE user_id = u.id) as automod_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.risk_score >= 50 AND u.is_flagged = 0 AND u.is_banned = 0
    ORDER BY u.risk_score DESC
    LIMIT 20
")->fetchAll();

// Get recently auto-moderated users
$recentAutomod = $db->query("
    SELECT u.id, u.username, u.email, u.created_at, 
           r.display_name as role_display, r.color as role_color,
           COUNT(al.id) as action_count,
           MAX(al.created_at) as last_action
    FROM automod_log al
    JOIN users u ON al.user_id = u.id
    JOIN roles r ON u.role_id = r.id
    WHERE al.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND u.is_banned = 0 AND u.is_flagged = 0
    GROUP BY u.id
    HAVING action_count >= 2
    ORDER BY action_count DESC
    LIMIT 20
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-flag" style="color:var(--red)"></i> Flagged Accounts</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:16px;margin-bottom:24px">
            <div class="card" style="padding:20px;text-align:center;border-color:#9b59b6">
                <div style="font-size:2.5rem;font-weight:700;color:#9b59b6"><?= count($flaggedUsers) ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Flagged</div>
            </div>
            <div class="card" style="padding:20px;text-align:center;border-color:var(--red)">
                <div style="font-size:2.5rem;font-weight:700;color:var(--red)"><?= count($highRiskUsers) ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">High Risk</div>
            </div>
            <div class="card" style="padding:20px;text-align:center;border-color:#f39c12">
                <div style="font-size:2.5rem;font-weight:700;color:#f39c12"><?= count($recentAutomod) ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">AutoMod Today</div>
            </div>
        </div>

        <!-- Flagged Users -->
        <?php if ($flaggedUsers): ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header" style="background:rgba(155,89,182,0.1)">
                <h3><i class="fas fa-flag" style="color:#9b59b6"></i> Flagged for Review (<?= count($flaggedUsers) ?>)</h3>
            </div>
            <div style="padding:16px">
                <?php foreach ($flaggedUsers as $user): ?>
                <div style="display:flex;gap:16px;align-items:center;padding:16px;background:var(--bg0);border-radius:8px;margin-bottom:12px;border-left:4px solid #9b59b6">
                    <img src="<?= e(getAvatarUrl($user['avatar'], $user['username'])) ?>" style="width:50px;height:50px;border-radius:8px">
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                            <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $user['id'] ?>" 
                               style="font-weight:700;font-size:1.1rem;color:var(--t0);text-decoration:none"><?= e($user['username']) ?></a>
                            <?= getRoleBadge($user) ?>
                            <?php if ($user['automod_count'] > 0): ?>
                            <span style="font-size:0.7rem;color:var(--red)"><i class="fas fa-robot"></i> <?= $user['automod_count'] ?> violations</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.85rem;color:var(--t2)"><?= e($user['email']) ?></div>
                        <?php if ($user['flag_reason']): ?>
                        <div style="margin-top:6px;padding:8px 12px;background:rgba(155,89,182,0.1);border-radius:4px;font-size:0.85rem;color:#9b59b6">
                            <i class="fas fa-info-circle"></i> <?= e($user['flag_reason']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $user['id'] ?>" class="btn btn-ghost btn-sm">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="clear_flag">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Clear</button>
                        </form>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="trust_user">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-shield-check"></i> Trust</button>
                        </form>
                        <?php if ($currentUser['can_ban']): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="ban_user">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="reason" value="Flagged account review">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Ban this user?"><i class="fas fa-ban"></i> Ban</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <!-- High Risk Users -->
            <div class="card">
                <div class="card-header" style="background:rgba(231,76,60,0.1)">
                    <h3><i class="fas fa-exclamation-triangle" style="color:var(--red)"></i> High Risk Accounts</h3>
                </div>
                <div style="padding:16px;max-height:400px;overflow-y:auto">
                    <?php if ($highRiskUsers): ?>
                    <?php foreach ($highRiskUsers as $user): ?>
                    <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $user['id'] ?>" 
                       style="display:flex;gap:12px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px;margin-bottom:10px;text-decoration:none;color:inherit">
                        <div style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(231,76,60,0.2);border-radius:50%;color:var(--red);font-weight:700">
                            <?= $user['risk_score'] ?>
                        </div>
                        <div style="flex:1">
                            <div style="font-weight:600"><?= e($user['username']) ?></div>
                            <div style="font-size:0.75rem;color:var(--t2)"><?= e($user['email']) ?></div>
                        </div>
                        <i class="fas fa-chevron-right" style="color:var(--t2)"></i>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-shield-check" style="font-size:2rem;margin-bottom:12px;display:block;color:var(--green);opacity:0.5"></i>
                        No high-risk accounts
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent AutoMod Activity -->
            <div class="card">
                <div class="card-header" style="background:rgba(243,156,18,0.1)">
                    <h3><i class="fas fa-robot" style="color:#f39c12"></i> Recent AutoMod Activity</h3>
                </div>
                <div style="padding:16px;max-height:400px;overflow-y:auto">
                    <?php if ($recentAutomod): ?>
                    <?php foreach ($recentAutomod as $user): ?>
                    <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $user['id'] ?>" 
                       style="display:flex;gap:12px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px;margin-bottom:10px;text-decoration:none;color:inherit">
                        <div style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(243,156,18,0.2);border-radius:50%;color:#f39c12;font-weight:700">
                            <?= $user['action_count'] ?>
                        </div>
                        <div style="flex:1">
                            <div style="font-weight:600"><?= e($user['username']) ?></div>
                            <div style="font-size:0.75rem;color:var(--t2)">Last: <?= timeAgo($user['last_action']) ?></div>
                        </div>
                        <i class="fas fa-chevron-right" style="color:var(--t2)"></i>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-robot" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.5"></i>
                        No AutoMod activity today
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$flaggedUsers && !$highRiskUsers && !$recentAutomod): ?>
        <div class="card" style="margin-top:20px">
            <div style="padding:60px;text-align:center;color:var(--green)">
                <i class="fas fa-check-circle" style="font-size:3rem;margin-bottom:16px;display:block"></i>
                <h2 style="margin-bottom:8px">All Clear!</h2>
                <p style="color:var(--t2)">No accounts currently require review.</p>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
