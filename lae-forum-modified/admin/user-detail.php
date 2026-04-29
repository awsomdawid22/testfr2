<?php
/**
 * Advanced User Intelligence Panel
 * Shows sensitive user information to President/Staff roles
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();

// Check if user has permission to view sensitive data
$canViewSensitive = $currentUser['can_admin'] || 
                   in_array($currentUser['role_name'], ['president', 'vice_president', 'staff', 'administrator']);

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: ' . SITE_URL . '/admin/users.php');
    exit;
}

// Get user data with role info
$stmt = $db->prepare("
    SELECT u.*, r.display_name as role_display, r.name as role_name, r.color as role_color, r.badge_color
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . SITE_URL . '/admin/users.php?error=user_not_found');
    exit;
}

$pageTitle = 'User: ' . $user['username'] . ' · Admin';
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'toggle_muted':
            $newState = $user['is_muted'] ? 0 : 1;
            $reason = trim($_POST['reason'] ?? '');
            $until = !empty($_POST['until']) ? $_POST['until'] : null;
            $db->prepare("UPDATE users SET is_muted = ?, mute_reason = ?, muted_until = ? WHERE id = ?")
               ->execute([$newState, $reason ?: null, $until, $userId]);
            logAudit($currentUser['id'], $newState ? 'mute_user' : 'unmute_user', 'user', $userId, $reason);
            $message = $newState ? 'User muted.' : 'User unmuted.';
            break;
            
        case 'toggle_flagged':
            $newState = $user['is_flagged'] ? 0 : 1;
            $reason = trim($_POST['reason'] ?? '');
            $db->prepare("UPDATE users SET is_flagged = ?, flag_reason = ? WHERE id = ?")
               ->execute([$newState, $reason ?: null, $userId]);
            logAudit($currentUser['id'], $newState ? 'flag_user' : 'unflag_user', 'user', $userId, $reason);
            $message = $newState ? 'User flagged for review.' : 'Flag removed.';
            break;
            
        case 'toggle_trusted':
            $newState = $user['is_trusted'] ? 0 : 1;
            $db->prepare("UPDATE users SET is_trusted = ? WHERE id = ?")->execute([$newState, $userId]);
            logAudit($currentUser['id'], $newState ? 'trust_user' : 'untrust_user', 'user', $userId);
            $message = $newState ? 'User marked as trusted.' : 'Trusted status removed.';
            break;
            
        case 'toggle_restricted':
            $newState = $user['is_restricted'] ? 0 : 1;
            $db->prepare("UPDATE users SET is_restricted = ? WHERE id = ?")->execute([$newState, $userId]);
            logAudit($currentUser['id'], $newState ? 'restrict_user' : 'unrestrict_user', 'user', $userId);
            $message = $newState ? 'User restricted.' : 'Restrictions removed.';
            break;
            
        case 'toggle_locked':
            $newState = $user['account_locked'] ? 0 : 1;
            $reason = trim($_POST['reason'] ?? '');
            $db->prepare("UPDATE users SET account_locked = ?, lock_reason = ? WHERE id = ?")
               ->execute([$newState, $reason ?: null, $userId]);
            logAudit($currentUser['id'], $newState ? 'lock_account' : 'unlock_account', 'user', $userId, $reason);
            $message = $newState ? 'Account locked.' : 'Account unlocked.';
            break;
            
        case 'force_password_reset':
            $db->prepare("UPDATE users SET force_password_reset = 1 WHERE id = ?")->execute([$userId]);
            logAudit($currentUser['id'], 'force_password_reset', 'user', $userId);
            $message = 'User will be required to reset password on next login.';
            break;
            
        case 'force_logout':
            $db->prepare("UPDATE users SET force_logout = 1 WHERE id = ?")->execute([$userId]);
            $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
            logAudit($currentUser['id'], 'force_logout', 'user', $userId);
            $message = 'All user sessions terminated.';
            break;
            
        case 'add_note':
            $note = trim($_POST['note'] ?? '');
            if ($note) {
                $db->prepare("INSERT INTO staff_notes (user_id, author_id, note) VALUES (?, ?, ?)")
                   ->execute([$userId, $currentUser['id'], $note]);
                $message = 'Note added.';
            }
            break;
            
        case 'delete_note':
            $noteId = (int)($_POST['note_id'] ?? 0);
            $db->prepare("DELETE FROM staff_notes WHERE id = ? AND user_id = ?")->execute([$noteId, $userId]);
            $message = 'Note deleted.';
            break;
            
        case 'update_risk_score':
            if ($currentUser['can_admin']) {
                $riskScore = max(0, min(100, (int)($_POST['risk_score'] ?? 0)));
                $trustScore = max(0, min(100, (int)($_POST['trust_score'] ?? 50)));
                $db->prepare("UPDATE users SET risk_score = ?, trust_score = ? WHERE id = ?")
                   ->execute([$riskScore, $trustScore, $userId]);
                $message = 'Scores updated.';
            }
            break;
    }
    
    // Refresh user data
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}

// Get IP history
$ipHistory = [];
if ($canViewSensitive) {
    try {
        $ipStmt = $db->prepare("SELECT * FROM ip_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $ipStmt->execute([$userId]);
        $ipHistory = $ipStmt->fetchAll();
    } catch (Exception $e) {}
}

// Get active sessions
$sessions = [];
if ($canViewSensitive) {
    try {
        $sessStmt = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC LIMIT 20");
        $sessStmt->execute([$userId]);
        $sessions = $sessStmt->fetchAll();
    } catch (Exception $e) {}
}

// Get linked accounts (same IP)
$linkedAccounts = [];
if ($canViewSensitive && !empty($user['registration_ip'])) {
    try {
        $linkStmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.created_at, u.registration_ip, u.is_banned,
                   r.display_name as role_display, r.color as role_color
            FROM users u 
            JOIN roles r ON u.role_id = r.id
            WHERE u.id != ? AND (
                u.registration_ip = ? OR 
                u.last_ip = ? OR
                u.registration_ip = ? OR
                u.last_ip = ?
            )
            ORDER BY u.created_at DESC
            LIMIT 20
        ");
        $linkStmt->execute([$userId, $user['registration_ip'], $user['registration_ip'], $user['last_ip'] ?? '', $user['last_ip'] ?? '']);
        $linkedAccounts = $linkStmt->fetchAll();
    } catch (Exception $e) {}
}

// Get staff notes
$staffNotes = [];
try {
    $notesStmt = $db->prepare("
        SELECT sn.*, u.username as author_name 
        FROM staff_notes sn 
        JOIN users u ON sn.author_id = u.id 
        WHERE sn.user_id = ? 
        ORDER BY sn.is_pinned DESC, sn.created_at DESC
    ");
    $notesStmt->execute([$userId]);
    $staffNotes = $notesStmt->fetchAll();
} catch (Exception $e) {}

// Get automod actions against this user
$automodActions = [];
try {
    $automodStmt = $db->prepare("SELECT * FROM automod_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $automodStmt->execute([$userId]);
    $automodActions = $automodStmt->fetchAll();
} catch (Exception $e) {}

// Get recent audit log entries
$auditLog = $db->prepare("
    SELECT * FROM audit_log 
    WHERE user_id = ? OR (target_type = 'user' AND target_id = ?)
    ORDER BY created_at DESC LIMIT 30
");
$auditLog->execute([$userId, $userId]);
$auditEntries = $auditLog->fetchAll();

// Calculate risk assessment
$riskFactors = [];
$riskScore = $user['risk_score'] ?? 0;

// Check email quality
if (preg_match('/[0-9]{5,}/', $user['email'])) {
    $riskFactors[] = ['level' => 'medium', 'text' => 'Email contains many numbers'];
}

// Check for linked accounts
if (count($linkedAccounts) > 0) {
    $riskFactors[] = ['level' => 'high', 'text' => count($linkedAccounts) . ' potential alt account(s) detected'];
}

// Check ban history
$banCount = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE target_id = ? AND action IN ('ban_user', 'warn_user')");
$banCount->execute([$userId]);
$bans = (int)$banCount->fetchColumn();
if ($bans > 0) {
    $riskFactors[] = ['level' => $bans > 2 ? 'high' : 'medium', 'text' => "$bans previous warning(s)/ban(s)"];
}

// Account age
$accountAge = (time() - strtotime($user['created_at'])) / 86400;
if ($accountAge < 7) {
    $riskFactors[] = ['level' => 'low', 'text' => 'New account (less than 7 days)'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        
        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <!-- User Header -->
        <div class="card" style="margin-bottom:20px">
            <div style="padding:24px;display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">
                <img src="<?= e(getAvatarUrl($user['avatar'], $user['username'])) ?>" 
                     style="width:100px;height:100px;border-radius:12px;border:3px solid <?= e($user['role_color'] ?? 'var(--b0)') ?>">
                <div style="flex:1;min-width:200px">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px">
                        <h1 style="font-size:1.8rem;margin:0"><?= e($user['username']) ?></h1>
                        <?= getRoleBadge($user) ?>
                        <?php if ($user['is_banned']): ?>
                            <span class="badge" style="background:var(--red);color:#fff"><i class="fas fa-ban"></i> Banned</span>
                        <?php endif; ?>
                        <?php if ($user['is_muted'] ?? false): ?>
                            <span class="badge" style="background:#f39c12;color:#000"><i class="fas fa-volume-mute"></i> Muted</span>
                        <?php endif; ?>
                        <?php if ($user['is_flagged'] ?? false): ?>
                            <span class="badge" style="background:#9b59b6;color:#fff"><i class="fas fa-flag"></i> Flagged</span>
                        <?php endif; ?>
                        <?php if ($user['is_trusted'] ?? false): ?>
                            <span class="badge" style="background:var(--green);color:#fff"><i class="fas fa-shield-check"></i> Trusted</span>
                        <?php endif; ?>
                        <?php if ($user['account_locked'] ?? false): ?>
                            <span class="badge" style="background:#e74c3c;color:#fff"><i class="fas fa-lock"></i> Locked</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:0.88rem;color:var(--t1)">
                        <span><i class="fas fa-envelope" style="width:16px"></i> <?= e($user['email']) ?></span>
                        <span><i class="fas fa-calendar" style="width:16px"></i> Joined <?= date('M j, Y', strtotime($user['created_at'])) ?></span>
                        <span><i class="fas fa-clock" style="width:16px"></i> Last seen <?= timeAgo($user['last_seen']) ?></span>
                        <span><i class="fas fa-comments" style="width:16px"></i> <?= number_format($user['post_count']) ?> posts</span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a href="<?= SITE_URL ?>/profile.php?user=<?= $user['id'] ?>" class="btn btn-ghost btn-sm" target="_blank">
                        <i class="fas fa-external-link"></i> View Profile
                    </a>
                    <a href="<?= SITE_URL ?>/admin/users.php?edit=<?= $user['id'] ?>" class="btn btn-ghost btn-sm">
                        <i class="fas fa-pen"></i> Edit Role
                    </a>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(350px, 1fr));gap:20px">
            
            <!-- Left Column -->
            <div style="display:flex;flex-direction:column;gap:20px">
                
                <!-- Risk Assessment -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-shield-halved" style="color:var(--red)"></i> Risk Assessment</h3>
                    </div>
                    <div style="padding:16px">
                        <div style="display:flex;gap:20px;margin-bottom:16px">
                            <div style="flex:1;text-align:center;padding:16px;background:var(--bg0);border-radius:8px">
                                <div style="font-size:2rem;font-weight:700;color:<?= ($user['risk_score'] ?? 0) > 50 ? 'var(--red)' : ($user['risk_score'] ?? 0) > 25 ? '#f39c12' : 'var(--green)' ?>">
                                    <?= $user['risk_score'] ?? 0 ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Risk Score</div>
                            </div>
                            <div style="flex:1;text-align:center;padding:16px;background:var(--bg0);border-radius:8px">
                                <div style="font-size:2rem;font-weight:700;color:<?= ($user['trust_score'] ?? 50) > 70 ? 'var(--green)' : ($user['trust_score'] ?? 50) > 40 ? '#f39c12' : 'var(--red)' ?>">
                                    <?= $user['trust_score'] ?? 50 ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Trust Score</div>
                            </div>
                        </div>
                        
                        <?php if ($riskFactors): ?>
                        <div style="margin-bottom:16px">
                            <div style="font-size:0.8rem;font-weight:600;color:var(--t1);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px">Risk Factors</div>
                            <?php foreach ($riskFactors as $factor): ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(<?= $factor['level'] === 'high' ? '231,76,60' : ($factor['level'] === 'medium' ? '243,156,18' : '46,204,113') ?>,0.1);border-radius:6px;margin-bottom:6px;font-size:0.85rem">
                                <i class="fas fa-<?= $factor['level'] === 'high' ? 'exclamation-triangle' : ($factor['level'] === 'medium' ? 'exclamation-circle' : 'info-circle') ?>" 
                                   style="color:<?= $factor['level'] === 'high' ? 'var(--red)' : ($factor['level'] === 'medium' ? '#f39c12' : 'var(--green)') ?>"></i>
                                <?= e($factor['text']) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="padding:16px;background:rgba(46,204,113,0.1);border-radius:8px;text-align:center;color:var(--green)">
                            <i class="fas fa-check-circle"></i> No risk factors detected
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($currentUser['can_admin']): ?>
                        <form method="POST" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--b0)">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="update_risk_score">
                            <div style="display:flex;gap:10px;align-items:flex-end">
                                <div style="flex:1">
                                    <label style="font-size:0.75rem;color:var(--t2)">Risk (0-100)</label>
                                    <input type="number" name="risk_score" class="form-input" value="<?= $user['risk_score'] ?? 0 ?>" min="0" max="100" style="padding:6px 10px">
                                </div>
                                <div style="flex:1">
                                    <label style="font-size:0.75rem;color:var(--t2)">Trust (0-100)</label>
                                    <input type="number" name="trust_score" class="form-input" value="<?= $user['trust_score'] ?? 50 ?>" min="0" max="100" style="padding:6px 10px">
                                </div>
                                <button type="submit" class="btn btn-ghost btn-sm">Update</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Account Controls -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-sliders" style="color:var(--red)"></i> Account Controls</h3>
                    </div>
                    <div style="padding:16px;display:flex;flex-direction:column;gap:12px">
                        
                        <!-- Mute Toggle -->
                        <form method="POST" style="display:flex;gap:10px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="toggle_muted">
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:0.9rem">
                                    <i class="fas fa-volume-mute" style="width:20px;color:#f39c12"></i> Muted
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2)">Cannot post or reply</div>
                            </div>
                            <input type="text" name="reason" placeholder="Reason..." class="form-input" style="width:140px;padding:6px 10px;font-size:0.8rem" value="<?= e($user['mute_reason'] ?? '') ?>">
                            <button type="submit" class="btn <?= ($user['is_muted'] ?? false) ? 'btn-success' : 'btn-ghost' ?> btn-sm">
                                <?= ($user['is_muted'] ?? false) ? 'Unmute' : 'Mute' ?>
                            </button>
                        </form>
                        
                        <!-- Flag Toggle -->
                        <form method="POST" style="display:flex;gap:10px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="toggle_flagged">
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:0.9rem">
                                    <i class="fas fa-flag" style="width:20px;color:#9b59b6"></i> Flagged
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2)">Under review, posts need approval</div>
                            </div>
                            <input type="text" name="reason" placeholder="Reason..." class="form-input" style="width:140px;padding:6px 10px;font-size:0.8rem" value="<?= e($user['flag_reason'] ?? '') ?>">
                            <button type="submit" class="btn <?= ($user['is_flagged'] ?? false) ? 'btn-success' : 'btn-ghost' ?> btn-sm">
                                <?= ($user['is_flagged'] ?? false) ? 'Unflag' : 'Flag' ?>
                            </button>
                        </form>
                        
                        <!-- Trusted Toggle -->
                        <form method="POST" style="display:flex;gap:10px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="toggle_trusted">
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:0.9rem">
                                    <i class="fas fa-shield-check" style="width:20px;color:var(--green)"></i> Trusted
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2)">Bypasses some AutoMod checks</div>
                            </div>
                            <button type="submit" class="btn <?= ($user['is_trusted'] ?? false) ? 'btn-danger' : 'btn-success' ?> btn-sm">
                                <?= ($user['is_trusted'] ?? false) ? 'Remove Trust' : 'Mark Trusted' ?>
                            </button>
                        </form>
                        
                        <!-- Restricted Toggle -->
                        <form method="POST" style="display:flex;gap:10px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="toggle_restricted">
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:0.9rem">
                                    <i class="fas fa-user-slash" style="width:20px;color:#e67e22"></i> Restricted
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2)">Limited functionality</div>
                            </div>
                            <button type="submit" class="btn <?= ($user['is_restricted'] ?? false) ? 'btn-success' : 'btn-ghost' ?> btn-sm">
                                <?= ($user['is_restricted'] ?? false) ? 'Unrestrict' : 'Restrict' ?>
                            </button>
                        </form>
                        
                        <!-- Lock Account Toggle -->
                        <form method="POST" style="display:flex;gap:10px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="toggle_locked">
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:0.9rem">
                                    <i class="fas fa-lock" style="width:20px;color:var(--red)"></i> Account Locked
                                </div>
                                <div style="font-size:0.75rem;color:var(--t2)">Cannot login</div>
                            </div>
                            <input type="text" name="reason" placeholder="Reason..." class="form-input" style="width:140px;padding:6px 10px;font-size:0.8rem" value="<?= e($user['lock_reason'] ?? '') ?>">
                            <button type="submit" class="btn <?= ($user['account_locked'] ?? false) ? 'btn-success' : 'btn-danger' ?> btn-sm">
                                <?= ($user['account_locked'] ?? false) ? 'Unlock' : 'Lock' ?>
                            </button>
                        </form>
                        
                        <div style="border-top:1px solid var(--b0);padding-top:12px;margin-top:4px;display:flex;gap:8px;flex-wrap:wrap">
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                <input type="hidden" name="action" value="force_password_reset">
                                <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Force this user to reset their password?">
                                    <i class="fas fa-key"></i> Force Password Reset
                                </button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                <input type="hidden" name="action" value="force_logout">
                                <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Terminate all sessions for this user?">
                                    <i class="fas fa-right-from-bracket"></i> Force Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Staff Notes -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-sticky-note" style="color:var(--red)"></i> Staff Notes</h3>
                    </div>
                    <div style="padding:16px">
                        <form method="POST" style="margin-bottom:16px">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="add_note">
                            <textarea name="note" class="form-input" rows="2" placeholder="Add a note about this user..." style="margin-bottom:8px"></textarea>
                            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-plus"></i> Add Note</button>
                        </form>
                        
                        <?php if ($staffNotes): ?>
                        <div style="display:flex;flex-direction:column;gap:10px;max-height:300px;overflow-y:auto">
                            <?php foreach ($staffNotes as $note): ?>
                            <div style="padding:12px;background:var(--bg0);border-radius:8px;border-left:3px solid var(--red)">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                    <span style="font-weight:600;font-size:0.85rem"><?= e($note['author_name']) ?></span>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <span style="font-size:0.75rem;color:var(--t2)"><?= timeAgo($note['created_at']) ?></span>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                            <input type="hidden" name="action" value="delete_note">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 6px" data-confirm="Delete this note?">
                                                <i class="fas fa-trash" style="font-size:0.7rem"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div style="font-size:0.88rem;color:var(--t0);white-space:pre-wrap"><?= e($note['note']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="text-align:center;color:var(--t2);padding:20px;font-size:0.85rem">
                            <i class="fas fa-sticky-note" style="font-size:1.5rem;margin-bottom:8px;display:block;opacity:0.5"></i>
                            No staff notes yet
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Right Column -->
            <div style="display:flex;flex-direction:column;gap:20px">
                
                <?php if ($canViewSensitive): ?>
                <!-- Sensitive Information -->
                <div class="card" style="border-color:var(--red)">
                    <div class="card-header" style="background:rgba(231,76,60,0.1)">
                        <h3><i class="fas fa-user-secret" style="color:var(--red)"></i> Sensitive Information</h3>
                    </div>
                    <div style="padding:16px">
                        <div style="display:grid;gap:12px">
                            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <span style="color:var(--t2);font-size:0.85rem">Registration IP</span>
                                <code style="font-size:0.85rem;color:var(--red)"><?= e($user['registration_ip'] ?? 'N/A') ?></code>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <span style="color:var(--t2);font-size:0.85rem">Last IP</span>
                                <code style="font-size:0.85rem;color:var(--red)"><?= e($user['last_ip'] ?? 'N/A') ?></code>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <span style="color:var(--t2);font-size:0.85rem">Email</span>
                                <code style="font-size:0.85rem"><?= e($user['email']) ?></code>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <span style="color:var(--t2);font-size:0.85rem">Email Verified</span>
                                <span style="color:<?= $user['email_verified'] ? 'var(--green)' : 'var(--red)' ?>">
                                    <i class="fas fa-<?= $user['email_verified'] ? 'check' : 'times' ?>"></i>
                                    <?= $user['email_verified'] ? 'Yes' : 'No' ?>
                                </span>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <span style="color:var(--t2);font-size:0.85rem">Failed Logins</span>
                                <span style="color:<?= ($user['failed_login_attempts'] ?? 0) > 3 ? 'var(--red)' : 'var(--t0)' ?>">
                                    <?= $user['failed_login_attempts'] ?? 0 ?>
                                </span>
                            </div>
                            <?php if (!empty($user['last_user_agent'])): ?>
                            <div style="padding:10px 12px;background:var(--bg0);border-radius:6px">
                                <div style="color:var(--t2);font-size:0.85rem;margin-bottom:4px">Last User Agent</div>
                                <code style="font-size:0.75rem;word-break:break-all;color:var(--t1)"><?= e(substr($user['last_user_agent'], 0, 200)) ?></code>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Linked Accounts -->
                <?php if ($linkedAccounts): ?>
                <div class="card" style="border-color:#f39c12">
                    <div class="card-header" style="background:rgba(243,156,18,0.1)">
                        <h3><i class="fas fa-users" style="color:#f39c12"></i> Potential Alt Accounts (<?= count($linkedAccounts) ?>)</h3>
                    </div>
                    <div style="padding:16px;max-height:300px;overflow-y:auto">
                        <?php foreach ($linkedAccounts as $linked): ?>
                        <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $linked['id'] ?>" 
                           style="display:flex;gap:12px;align-items:center;padding:10px;background:var(--bg0);border-radius:8px;margin-bottom:8px;text-decoration:none;color:inherit">
                            <div style="flex:1">
                                <div style="font-weight:600"><?= e($linked['username']) ?></div>
                                <div style="font-size:0.75rem;color:var(--t2)"><?= e($linked['email']) ?></div>
                            </div>
                            <div style="text-align:right">
                                <div style="font-size:0.75rem;color:var(--t2)">Joined <?= timeAgo($linked['created_at']) ?></div>
                                <?php if ($linked['is_banned']): ?>
                                <span style="font-size:0.7rem;color:var(--red)"><i class="fas fa-ban"></i> Banned</span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Active Sessions -->
                <?php if ($sessions): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-desktop" style="color:var(--red)"></i> Active Sessions (<?= count($sessions) ?>)</h3>
                    </div>
                    <div style="padding:16px;max-height:250px;overflow-y:auto">
                        <?php foreach ($sessions as $session): ?>
                        <div style="display:flex;gap:12px;align-items:center;padding:10px;background:var(--bg0);border-radius:8px;margin-bottom:8px">
                            <i class="fas fa-<?= $session['is_active'] ? 'circle text-green' : 'circle text-gray' ?>" style="color:<?= $session['is_active'] ? 'var(--green)' : 'var(--t2)' ?>"></i>
                            <div style="flex:1">
                                <code style="font-size:0.8rem"><?= e($session['ip_address']) ?></code>
                                <div style="font-size:0.72rem;color:var(--t2)"><?= timeAgo($session['last_activity']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- IP History -->
                <?php if ($ipHistory): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history" style="color:var(--red)"></i> IP History</h3>
                    </div>
                    <div style="padding:16px;max-height:300px;overflow-y:auto">
                        <?php foreach ($ipHistory as $ip): ?>
                        <div style="display:flex;gap:12px;align-items:center;padding:8px 10px;background:var(--bg0);border-radius:6px;margin-bottom:6px;font-size:0.85rem">
                            <code style="flex:1"><?= e($ip['ip_address']) ?></code>
                            <span class="badge" style="font-size:0.7rem"><?= e($ip['action']) ?></span>
                            <span style="color:var(--t2);font-size:0.75rem"><?= timeAgo($ip['created_at']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- AutoMod Actions -->
                <?php if ($automodActions): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-robot" style="color:var(--red)"></i> AutoMod History</h3>
                    </div>
                    <div style="padding:16px;max-height:250px;overflow-y:auto">
                        <?php foreach ($automodActions as $action): ?>
                        <div style="padding:10px;background:var(--bg0);border-radius:8px;margin-bottom:8px;border-left:3px solid <?= $action['severity'] === 'critical' ? 'var(--red)' : ($action['severity'] === 'high' ? '#e67e22' : ($action['severity'] === 'medium' ? '#f39c12' : 'var(--t2)')) ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                                <span class="badge"><?= e($action['action_type']) ?></span>
                                <span style="font-size:0.75rem;color:var(--t2)"><?= timeAgo($action['created_at']) ?></span>
                            </div>
                            <div style="font-size:0.85rem;color:var(--t1)"><?= e($action['reason']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list" style="color:var(--red)"></i> Activity Log</h3>
                    </div>
                    <div style="padding:16px;max-height:300px;overflow-y:auto">
                        <?php foreach ($auditEntries as $entry): ?>
                        <div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--b0);font-size:0.85rem">
                            <div style="flex:1">
                                <span class="badge" style="font-size:0.7rem"><?= e($entry['action']) ?></span>
                                <?php if ($entry['details']): ?>
                                <span style="color:var(--t1);margin-left:6px"><?= e(substr($entry['details'], 0, 50)) ?></span>
                                <?php endif; ?>
                            </div>
                            <span style="color:var(--t2);font-size:0.75rem"><?= timeAgo($entry['created_at']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
