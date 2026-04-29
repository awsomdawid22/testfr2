<?php
/**
 * AutoMod Configuration Page
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/automod.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();

$pageTitle = 'AutoMod Settings · Admin';
$message = '';
$error = '';

// Load current settings
$settingsFile = __DIR__ . '/../includes/settings.php';
$siteSettings = file_exists($settingsFile) ? include $settingsFile : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? 'save_settings';
    
    if ($action === 'save_settings') {
        $siteSettings['automod_enabled'] = isset($_POST['automod_enabled']) ? '1' : '0';
        $siteSettings['automod_spam_threshold'] = max(1, (int)($_POST['spam_threshold'] ?? 3));
        $siteSettings['automod_spam_window'] = max(10, (int)($_POST['spam_window'] ?? 60));
        $siteSettings['automod_new_user_days'] = max(0, (int)($_POST['new_user_days'] ?? 7));
        $siteSettings['automod_new_user_post_limit'] = max(1, (int)($_POST['new_user_post_limit'] ?? 10));
        $siteSettings['automod_link_check'] = isset($_POST['link_check']) ? '1' : '0';
        $siteSettings['automod_profanity_action'] = $_POST['profanity_action'] ?? 'flag';
        $siteSettings['automod_spam_action'] = $_POST['spam_action'] ?? 'warn';
        $siteSettings['automod_auto_ban_threshold'] = max(0, (int)($_POST['auto_ban_threshold'] ?? 5));
        
        // Save settings
        $export = "<?php\nreturn " . var_export($siteSettings, true) . ";\n";
        file_put_contents($settingsFile, $export);
        
        logAudit($currentUser['id'], 'update_automod_settings', 'settings', null, 'Updated AutoMod settings');
        $message = 'AutoMod settings updated successfully.';
    }
}

// Initialize AutoMod and get stats
AutoMod::init($db);
$stats = AutoMod::getStats();

// Get recent automod actions
$recentActions = $db->query("
    SELECT al.*, u.username 
    FROM automod_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    ORDER BY al.created_at DESC 
    LIMIT 20
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-robot" style="color:var(--red)"></i> AutoMod Settings</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <!-- Statistics Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:16px;margin-bottom:24px">
            <div class="card" style="padding:20px;text-align:center">
                <div style="font-size:2.5rem;font-weight:700;color:var(--red)"><?= array_sum($stats['today'] ?? []) ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Actions Today</div>
            </div>
            <div class="card" style="padding:20px;text-align:center">
                <div style="font-size:2.5rem;font-weight:700;color:#f39c12"><?= $stats['today']['warn'] ?? 0 ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Warnings</div>
            </div>
            <div class="card" style="padding:20px;text-align:center">
                <div style="font-size:2.5rem;font-weight:700;color:#9b59b6"><?= $stats['today']['flag'] ?? 0 ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">Flagged</div>
            </div>
            <div class="card" style="padding:20px;text-align:center">
                <div style="font-size:2.5rem;font-weight:700;color:var(--green)"><?= $stats['week_total'] ?? 0 ?></div>
                <div style="font-size:0.8rem;color:var(--t2);text-transform:uppercase;letter-spacing:1px">This Week</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <!-- Settings Form -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cog"></i> Configuration</h3>
                </div>
                <form method="POST" style="padding:20px">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="save_settings">
                    
                    <!-- Master Toggle -->
                    <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--bg0);border-radius:8px;margin-bottom:20px">
                        <input type="checkbox" name="automod_enabled" id="automod_enabled" 
                               <?= ($siteSettings['automod_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                               style="width:20px;height:20px;accent-color:var(--green)">
                        <label for="automod_enabled" style="flex:1;cursor:pointer">
                            <div style="font-weight:700">Enable AutoMod</div>
                            <div style="font-size:0.8rem;color:var(--t2)">Automatically moderate content and detect spam</div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Spam Detection</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label style="font-size:0.75rem;color:var(--t2)">Max posts in window</label>
                                <input type="number" name="spam_threshold" class="form-input" 
                                       value="<?= e($siteSettings['automod_spam_threshold'] ?? 3) ?>" min="1" max="20">
                            </div>
                            <div>
                                <label style="font-size:0.75rem;color:var(--t2)">Time window (seconds)</label>
                                <input type="number" name="spam_window" class="form-input" 
                                       value="<?= e($siteSettings['automod_spam_window'] ?? 60) ?>" min="10" max="600">
                            </div>
                        </div>
                        <div class="form-hint">Users posting more than X messages in Y seconds trigger spam detection</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">New User Restrictions</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label style="font-size:0.75rem;color:var(--t2)">New user period (days)</label>
                                <input type="number" name="new_user_days" class="form-input" 
                                       value="<?= e($siteSettings['automod_new_user_days'] ?? 7) ?>" min="0" max="30">
                            </div>
                            <div>
                                <label style="font-size:0.75rem;color:var(--t2)">Daily post limit</label>
                                <input type="number" name="new_user_post_limit" class="form-input" 
                                       value="<?= e($siteSettings['automod_new_user_post_limit'] ?? 10) ?>" min="1" max="100">
                            </div>
                        </div>
                        <div class="form-hint">Restrict new accounts to prevent spam and abuse</div>
                    </div>

                    <div class="form-group">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg0);border-radius:8px">
                            <input type="checkbox" name="link_check" id="link_check" 
                                   <?= ($siteSettings['automod_link_check'] ?? '1') === '1' ? 'checked' : '' ?>
                                   style="width:18px;height:18px;accent-color:var(--red)">
                            <label for="link_check" style="flex:1;cursor:pointer">
                                <div style="font-weight:600;font-size:0.9rem">Link Checking</div>
                                <div style="font-size:0.75rem;color:var(--t2)">Flag links from new users and block suspicious domains</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Profanity Action</label>
                        <select name="profanity_action" class="form-select">
                            <option value="flag" <?= ($siteSettings['automod_profanity_action'] ?? 'flag') === 'flag' ? 'selected' : '' ?>>Flag for review</option>
                            <option value="warn" <?= ($siteSettings['automod_profanity_action'] ?? '') === 'warn' ? 'selected' : '' ?>>Warn user</option>
                            <option value="block" <?= ($siteSettings['automod_profanity_action'] ?? '') === 'block' ? 'selected' : '' ?>>Block post</option>
                            <option value="shadow" <?= ($siteSettings['automod_profanity_action'] ?? '') === 'shadow' ? 'selected' : '' ?>>Shadow hide</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Spam Action</label>
                        <select name="spam_action" class="form-select">
                            <option value="warn" <?= ($siteSettings['automod_spam_action'] ?? 'warn') === 'warn' ? 'selected' : '' ?>>Warn user</option>
                            <option value="mute" <?= ($siteSettings['automod_spam_action'] ?? '') === 'mute' ? 'selected' : '' ?>>Temporarily mute</option>
                            <option value="block" <?= ($siteSettings['automod_spam_action'] ?? '') === 'block' ? 'selected' : '' ?>>Block post</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Auto-Mute Threshold</label>
                        <input type="number" name="auto_ban_threshold" class="form-input" 
                               value="<?= e($siteSettings['automod_auto_ban_threshold'] ?? 5) ?>" min="0" max="50">
                        <div class="form-hint">Auto-mute users after this many violations in 7 days (0 to disable)</div>
                    </div>

                    <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>

            <!-- Recent Actions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Actions</h3>
                </div>
                <div style="padding:16px;max-height:500px;overflow-y:auto">
                    <?php if ($recentActions): ?>
                    <?php foreach ($recentActions as $action): ?>
                    <div style="padding:12px;background:var(--bg0);border-radius:8px;margin-bottom:10px;border-left:3px solid <?= 
                        $action['severity'] === 'critical' ? 'var(--red)' : 
                        ($action['severity'] === 'high' ? '#e67e22' : 
                        ($action['severity'] === 'medium' ? '#f39c12' : 'var(--t2)')) ?>">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span class="badge" style="font-size:0.7rem"><?= e($action['action_type']) ?></span>
                                <?php if ($action['username']): ?>
                                <a href="<?= SITE_URL ?>/admin/user-detail.php?id=<?= $action['user_id'] ?>" 
                                   style="font-weight:600;color:var(--t0);text-decoration:none"><?= e($action['username']) ?></a>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:0.72rem;color:var(--t2)"><?= timeAgo($action['created_at']) ?></span>
                        </div>
                        <div style="font-size:0.85rem;color:var(--t1)"><?= e($action['reason']) ?></div>
                        <?php if ($action['content_preview']): ?>
                        <div style="margin-top:6px;padding:8px;background:var(--bg1);border-radius:4px;font-size:0.8rem;color:var(--t2);font-family:monospace">
                            <?= e(substr($action['content_preview'], 0, 100)) ?>...
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-robot" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.5"></i>
                        No recent AutoMod actions
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Triggered Filters -->
        <?php if (!empty($stats['top_filters'])): ?>
        <div class="card" style="margin-top:20px">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Most Triggered Filters</h3>
            </div>
            <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));gap:12px">
                <?php foreach ($stats['top_filters'] as $filter): ?>
                <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg0);border-radius:8px">
                    <span class="badge"><?= e($filter['type']) ?></span>
                    <div style="flex:1;min-width:0">
                        <code style="font-size:0.85rem;word-break:break-all"><?= e($filter['value']) ?></code>
                    </div>
                    <span style="font-weight:700;color:var(--red)"><?= number_format($filter['match_count']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
