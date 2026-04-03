<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Site Settings · Admin';
$message = '';
$error = '';

// We store settings in a flat PHP file for simplicity
$settingsFile = __DIR__ . '/../includes/settings.php';

// Load current settings
$settings = [];
if (file_exists($settingsFile)) {
    $settings = include $settingsFile;
}

// Defaults
$defaults = [
    'site_name'           => 'Los Angeles Experience',
    'site_tagline'        => 'FiveM Roleplay Community',
    'discord_url'         => '#',
    'twitter_url'         => '#',
    'youtube_url'         => '#',
    'tiktok_url'          => '#',
    'fivem_connect'       => 'fivem://connect/play.laexperience.com',
    'server_ip'           => 'play.laexperience.com',
    'fivem_secret'        => 'CHANGE_THIS_TO_A_RANDOM_SECRET_KEY',
    'maintenance_mode'    => '0',
    'registrations_open'  => '1',
    'max_avatar_size_mb'  => '2',
    'posts_per_page'      => '15',
    'threads_per_page'    => '20',
    'welcome_message'     => 'Welcome to the Los Angeles Experience forums! Please read the rules before posting.',
];
$settings = array_merge($defaults, $settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $new = [
        'site_name'          => trim($_POST['site_name'] ?? 'Los Angeles Experience'),
        'site_tagline'       => trim($_POST['site_tagline'] ?? ''),
        'discord_url'        => trim($_POST['discord_url'] ?? '#'),
        'twitter_url'        => trim($_POST['twitter_url'] ?? '#'),
        'youtube_url'        => trim($_POST['youtube_url'] ?? '#'),
        'tiktok_url'         => trim($_POST['tiktok_url'] ?? '#'),
        'fivem_connect'      => trim($_POST['fivem_connect'] ?? ''),
        'server_ip'          => trim($_POST['server_ip'] ?? ''),
        'fivem_secret'       => trim($_POST['fivem_secret'] ?? 'CHANGE_THIS_TO_A_RANDOM_SECRET_KEY'),
        'maintenance_mode'   => isset($_POST['maintenance_mode']) ? '1' : '0',
        'registrations_open' => isset($_POST['registrations_open']) ? '1' : '0',
        'welcome_message'    => trim($_POST['welcome_message'] ?? ''),
        'posts_per_page'     => (string)(int)($_POST['posts_per_page'] ?? 15),
        'threads_per_page'   => (string)(int)($_POST['threads_per_page'] ?? 20),
    ];

    // Write settings file
    $content = "<?php\nreturn " . var_export($new, true) . ";\n";
    if (file_put_contents($settingsFile, $content) !== false) {
        $settings = $new;
        logAudit($currentUser['id'], 'update_settings', null, null, 'Site settings updated');
        $message = 'Settings saved successfully.';
    } else {
        $error = 'Could not write settings file. Check file permissions on includes/';
    }
}

// DB stats for display
$dbSize = $db->query("
    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.tables
    WHERE table_schema = '" . DB_NAME . "'
")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-gear" style="color:var(--red)"></i> Site Settings</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

                <!-- General -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-globe"></i> General</h3></div>
                    <div style="padding:20px;display:grid;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Site Name</label>
                            <input type="text" name="site_name" class="form-input" value="<?= e($settings['site_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tagline</label>
                            <input type="text" name="site_tagline" class="form-input" value="<?= e($settings['site_tagline']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Welcome Message</label>
                            <textarea name="welcome_message" class="form-textarea" style="min-height:80px"><?= e($settings['welcome_message']) ?></textarea>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem">
                                <input type="checkbox" name="registrations_open" style="accent-color:var(--red)" <?= $settings['registrations_open'] === '1' ? 'checked' : '' ?>>
                                Registrations Open
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.88rem">
                                <input type="checkbox" name="maintenance_mode" style="accent-color:var(--red)" <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                                <span>Maintenance Mode <span style="color:var(--red)">(locks out non-admins)</span></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Server -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-server"></i> FiveM Server</h3></div>
                    <div style="padding:20px;display:grid;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Server IP:Port (for live status)</label>
                            <input type="text" name="server_ip" class="form-input" value="<?= e($settings['server_ip']) ?>" placeholder="127.0.0.1:30120">
                            <small style="color:var(--t2);font-size:0.75rem;margin-top:4px;display:block">
                                Enter your server's IP and port (default port is 30120). This is used to fetch live player count.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">FiveM Direct Connect URL</label>
                            <input type="text" name="fivem_connect" class="form-input" value="<?= e($settings['fivem_connect']) ?>" placeholder="fivem://connect/...">
                            <small style="color:var(--t2);font-size:0.75rem;margin-top:4px;display:block">
                                This is the link users click to connect. Use fivem://connect/IP:PORT or cfx.re/join/CODE
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">FiveM Resource Secret Key</label>
                            <input type="text" name="fivem_secret" class="form-input" value="<?= e($settings['fivem_secret'] ?? '') ?>" placeholder="Generate a random secret key">
                            <small style="color:var(--t2);font-size:0.75rem;margin-top:4px;display:block">
                                This key must match the Config.SecretKey in your lae-web-status resource. Used to authenticate status updates.
                            </small>
                        </div>
                        <div style="background:var(--bg1);border:1px solid var(--b0);border-radius:var(--r);padding:12px">
                            <div class="form-label" style="margin-bottom:6px">Live Status Options</div>
                            <div style="font-size:0.82rem;color:var(--t1);margin-bottom:8px">
                                <strong>Option 1:</strong> Install the <code>lae-web-status</code> FiveM resource (recommended - more accurate player data)<br>
                                <strong>Option 2:</strong> Direct query using Server IP above (basic player count only)
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="<?= SITE_URL ?>/api/server-status.php" target="_blank" class="btn btn-ghost btn-sm">
                                    <i class="fas fa-external-link"></i> Test Status API
                                </a>
                                <a href="<?= SITE_URL ?>/fivem-resource/" target="_blank" class="btn btn-ghost btn-sm">
                                    <i class="fas fa-download"></i> Download Resource
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Social Links -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-share-nodes"></i> Social Links</h3></div>
                    <div style="padding:20px;display:grid;gap:14px">
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-discord" style="color:#5865f2"></i> Discord</label>
                            <input type="text" name="discord_url" class="form-input" value="<?= e($settings['discord_url']) ?>" placeholder="https://discord.gg/...">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-x-twitter"></i> Twitter/X</label>
                            <input type="text" name="twitter_url" class="form-input" value="<?= e($settings['twitter_url']) ?>" placeholder="https://twitter.com/...">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-youtube" style="color:#ff0000"></i> YouTube</label>
                            <input type="text" name="youtube_url" class="form-input" value="<?= e($settings['youtube_url']) ?>" placeholder="https://youtube.com/...">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-tiktok"></i> TikTok</label>
                            <input type="text" name="tiktok_url" class="form-input" value="<?= e($settings['tiktok_url']) ?>" placeholder="https://tiktok.com/@...">
                        </div>
                    </div>
                </div>

                <!-- Forum Config -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-sliders"></i> Forum Config</h3></div>
                    <div style="padding:20px;display:grid;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Threads Per Page</label>
                            <input type="number" name="threads_per_page" class="form-input" value="<?= e($settings['threads_per_page']) ?>" min="5" max="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Posts Per Page</label>
                            <input type="number" name="posts_per_page" class="form-input" value="<?= e($settings['posts_per_page']) ?>" min="5" max="100">
                        </div>

                        <!-- DB Info -->
                        <div style="background:var(--bg1);border:1px solid var(--b0);border-radius:var(--r);padding:12px">
                            <div class="form-label" style="margin-bottom:6px">Database Info</div>
                            <div style="font-size:0.82rem;color:var(--t1);display:grid;gap:4px">
                                <span><i class="fas fa-database" style="color:var(--red);width:16px"></i> <?= DB_NAME ?></span>
                                <span><i class="fas fa-hdd" style="color:var(--t2);width:16px"></i> <?= $dbSize ?? '?' ?> MB</span>
                                <span><i class="fas fa-server" style="color:var(--t2);width:16px"></i> PHP <?= PHP_VERSION ?></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px">
                <a href="<?= SITE_URL ?>/admin/" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-accent btn-lg"><i class="fas fa-save"></i> Save All Settings</button>
            </div>
        </form>

        <!-- Danger Zone -->
        <?php if (isAdmin()): ?>
        <div class="card" style="margin-top:30px;border-color:var(--red)">
            <div class="card-header" style="background:rgba(231,76,60,0.08)">
                <h3 style="color:var(--red)"><i class="fas fa-triangle-exclamation"></i> Danger Zone</h3>
            </div>
            <div style="padding:20px;display:grid;gap:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--bg1);border-radius:var(--r);border:1px solid var(--b0)">
                    <div>
                        <div style="font-weight:700;font-size:0.9rem">Clear Audit Log</div>
                        <div style="font-size:0.8rem;color:var(--t2)">Permanently delete all audit log entries older than 30 days</div>
                    </div>
                    <a href="<?= SITE_URL ?>/admin/audit.php?clear_old=1&csrf=<?= generateCSRF() ?>" class="btn btn-danger btn-sm" data-confirm="Clear old audit log entries?">Clear Old Logs</a>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--bg1);border-radius:var(--r);border:1px solid var(--b0)">
                    <div>
                        <div style="font-weight:700;font-size:0.9rem">Reset Category Counts</div>
                        <div style="font-size:0.8rem;color:var(--t2)">Recalculate thread/post counts for all categories</div>
                    </div>
                    <a href="?action=reset_counts&csrf=<?= generateCSRF() ?>" class="btn btn-ghost btn-sm" data-confirm="Recalculate all category counts?">Recalculate</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
// Handle utility actions
if (isset($_GET['action']) && verifyCSRF($_GET['csrf'] ?? '')) {
    if ($_GET['action'] === 'reset_counts') {
        $cats = $db->query("SELECT id FROM categories")->fetchAll();
        foreach ($cats as $cat) {
            $tc = $db->prepare("SELECT COUNT(*) FROM threads WHERE category_id=? AND is_hidden=0"); $tc->execute([$cat['id']]);
            $pc = $db->prepare("SELECT COUNT(*) FROM posts p JOIN threads t ON p.thread_id=t.id WHERE t.category_id=? AND p.is_hidden=0 AND t.is_hidden=0"); $pc->execute([$cat['id']]);
            $db->prepare("UPDATE categories SET thread_count=?, post_count=? WHERE id=?")->execute([$tc->fetchColumn(), $pc->fetchColumn(), $cat['id']]);
        }
        echo '<script>window.location="' . SITE_URL . '/admin/settings.php?recalculated=1";</script>';
        exit;
    }
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
