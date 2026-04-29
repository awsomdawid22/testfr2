<?php
/**
 * IP Lookup Tool
 * Search for users by IP address
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

if (!$canViewSensitive) {
    header('Location: ' . SITE_URL . '/admin/');
    exit;
}

$pageTitle = 'IP Lookup · Admin';
$searchIp = trim($_GET['ip'] ?? '');
$results = [];
$ipHistory = [];

if ($searchIp) {
    // Search for users with this IP
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.username, u.email, u.created_at, u.last_seen, u.is_banned,
               u.registration_ip, u.last_ip,
               r.display_name as role_display, r.color as role_color
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.registration_ip LIKE ? OR u.last_ip LIKE ?
        ORDER BY u.created_at DESC
        LIMIT 100
    ");
    $stmt->execute(["%$searchIp%", "%$searchIp%"]);
    $results = $stmt->fetchAll();

    // Get IP history for this address
    try {
        $histStmt = $db->prepare("
            SELECT iph.*, u.username 
            FROM ip_history iph 
            JOIN users u ON iph.user_id = u.id 
            WHERE iph.ip_address LIKE ? 
            ORDER BY iph.created_at DESC 
            LIMIT 100
        ");
        $histStmt->execute(["%$searchIp%"]);
        $ipHistory = $histStmt->fetchAll();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-search-location" style="color:var(--red)"></i> IP Lookup</h1>
        </div>

        <!-- Search Form -->
        <div class="card" style="margin-bottom:20px">
            <div style="padding:20px">
                <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
                    <div style="flex:1;min-width:250px">
                        <label class="form-label">IP Address</label>
                        <input type="text" name="ip" class="form-input" placeholder="Enter full or partial IP address..." 
                               value="<?= e($searchIp) ?>" style="font-family:monospace">
                    </div>
                    <button type="submit" class="btn btn-accent">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
                <div class="form-hint" style="margin-top:8px">
                    Search by full IP (192.168.1.1) or partial (192.168.)
                </div>
            </div>
        </div>

        <?php if ($searchIp): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <!-- Users Found -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Users Found (<?= count($results) ?>)</h3>
                </div>
                <div style="padding:16px;max-height:600px;overflow-y:auto">
                    <?php if ($results): ?>
                    <?php foreach ($results as $user): ?>
                    <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $user['id'] ?>" 
                       style="display:flex;gap:12px;align-items:center;padding:12px;background:var(--bg0);border-radius:8px;margin-bottom:10px;text-decoration:none;color:inherit">
                        <div style="flex:1">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="font-weight:700"><?= e($user['username']) ?></span>
                                <?php if ($user['is_banned']): ?>
                                <span style="font-size:0.7rem;color:var(--red)"><i class="fas fa-ban"></i></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.8rem;color:var(--t2)"><?= e($user['email']) ?></div>
                            <div style="display:flex;gap:12px;margin-top:6px;font-size:0.75rem;color:var(--t2)">
                                <span>Reg: <code><?= e($user['registration_ip'] ?? 'N/A') ?></code></span>
                                <span>Last: <code><?= e($user['last_ip'] ?? 'N/A') ?></code></span>
                            </div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-size:0.75rem;color:var(--t2)">Joined</div>
                            <div style="font-size:0.85rem"><?= date('M j, Y', strtotime($user['created_at'])) ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-user-slash" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.5"></i>
                        No users found with this IP
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- IP History -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Activity History (<?= count($ipHistory) ?>)</h3>
                </div>
                <div style="padding:16px;max-height:600px;overflow-y:auto">
                    <?php if ($ipHistory): ?>
                    <?php foreach ($ipHistory as $entry): ?>
                    <div style="display:flex;gap:12px;align-items:center;padding:10px;background:var(--bg0);border-radius:6px;margin-bottom:8px">
                        <div style="flex:1">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span class="badge" style="font-size:0.7rem"><?= e($entry['action']) ?></span>
                                <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $entry['user_id'] ?>" 
                                   style="font-weight:600;color:var(--t0);text-decoration:none"><?= e($entry['username']) ?></a>
                            </div>
                            <code style="font-size:0.75rem;color:var(--t2)"><?= e($entry['ip_address']) ?></code>
                        </div>
                        <span style="font-size:0.75rem;color:var(--t2)"><?= timeAgo($entry['created_at']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-history" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.5"></i>
                        No activity history found
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div style="padding:60px;text-align:center;color:var(--t2)">
                <i class="fas fa-search-location" style="font-size:3rem;margin-bottom:16px;display:block;opacity:0.3"></i>
                <p>Enter an IP address above to search for associated users and activity.</p>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
