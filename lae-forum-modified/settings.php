<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Settings · LAE Forums';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $bio = substr(trim($_POST['bio'] ?? ''), 0, 500);
            $discord = trim($_POST['discord_id'] ?? '');
            $steam = trim($_POST['steam_hex'] ?? '');
            $db->prepare("UPDATE users SET bio = ?, discord_id = ?, steam_hex = ? WHERE id = ?")
               ->execute([$bio ?: null, $discord ?: null, $steam ?: null, $currentUser['id']]);
            $message = 'Profile updated.';
            $currentUser = getCurrentUser();
        }

        if ($action === 'change_password') {
            $current  = $_POST['current_password'] ?? '';
            $new      = $_POST['new_password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            if (!password_verify($current, $currentUser['password_hash'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $currentUser['id']]);
                logAudit($currentUser['id'], 'change_password', 'user', $currentUser['id'], 'Password changed');
                $message = 'Password updated successfully.';
            }
        }

        // Avatar upload
        if ($action === 'upload_avatar' && isset($_FILES['avatar'])) {
            $file = $_FILES['avatar'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                if ($file['size'] > MAX_AVATAR_SIZE) {
                    $error = 'Avatar too large (max 2MB).';
                } elseif (!in_array($file['type'], ALLOWED_IMAGE_TYPES)) {
                    $error = 'Invalid file type. Use JPG, PNG, GIF, or WebP.';
                } else {
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $dir = __DIR__ . '/assets/img/avatars/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $filename = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                        $db->prepare("UPDATE users SET avatar = ? WHERE id = ?")
                           ->execute(['assets/img/avatars/' . $filename, $currentUser['id']]);
                        $message = 'Avatar updated.';
                        $currentUser = getCurrentUser();
                    } else {
                        $error = 'Upload failed. Check file permissions.';
                    }
                }
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:800px">
    <h1 style="font-family:var(--font-head);font-size:2rem;letter-spacing:3px;margin-bottom:24px">
        <i class="fas fa-gear" style="color:var(--red)"></i> SETTINGS
    </h1>

    <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

    <div style="display:grid;gap:20px">

        <!-- Avatar -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-image"></i> Profile Picture</h3></div>
            <div style="padding:20px;display:flex;align-items:center;gap:20px">
                <img src="<?= e(getAvatarUrl($currentUser['avatar'], $currentUser['username'])) ?>" style="width:80px;height:80px;border-radius:50%;border:2px solid var(--red)">
                <form method="POST" enctype="multipart/form-data" style="flex:1">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="upload_avatar">
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <input type="file" name="avatar" accept="image/*" class="form-input" style="flex:1">
                        <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-upload"></i> Upload</button>
                    </div>
                    <div class="form-hint">Max 2MB. JPG, PNG, GIF, or WebP. Square images recommended.</div>
                </form>
            </div>
        </div>

        <!-- Profile Info -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Profile Information</h3></div>
            <div style="padding:20px">
                <?php
                // Show Discord OAuth result messages if redirected back from discord-callback.php
                if (!empty($_GET['discord_msg'])): ?>
                <div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['discord_msg']) ?></div>
                <?php endif; ?>
                <?php if (!empty($_GET['discord_err'])): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_GET['discord_err']) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-group">
                        <label class="form-label">Bio <span style="color:var(--t2)">(max 500 chars)</span></label>
                        <textarea name="bio" class="form-textarea" style="min-height:100px" maxlength="500" data-maxlength="500" data-counter="bio-counter"><?= e($currentUser['bio'] ?? '') ?></textarea>
                        <div class="form-hint"><span id="bio-counter"><?= 500 - strlen($currentUser['bio'] ?? '') ?></span> characters remaining</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fab fa-steam" style="color:#1b2838"></i> Steam Hex</label>
                        <input type="text" name="steam_hex" class="form-input" placeholder="steam:110000..." value="<?= e($currentUser['steam_hex'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-accent"><i class="fas fa-save"></i> Save Profile</button>
                </form>
            </div>
        </div>

        <!-- Discord Linking -->
        <div class="card" style="border-color:rgba(88,101,242,0.4)">
            <div class="card-header" style="background:rgba(88,101,242,0.06)">
                <h3><i class="fab fa-discord" style="color:#5865f2"></i> Discord Account</h3>
                <?php if ($currentUser['discord_id']): ?>
                <span style="background:rgba(46,204,113,0.15);color:#2ecc71;border:1px solid rgba(46,204,113,0.3);padding:3px 10px;border-radius:4px;font-size:0.75rem;font-weight:700">
                    <i class="fas fa-check"></i> Linked
                </span>
                <?php endif; ?>
            </div>
            <div style="padding:20px">
                <?php if ($currentUser['discord_id']): ?>
                <!-- Already linked -->
                <div style="display:flex;align-items:center;gap:14px;background:var(--bg1);border:1px solid var(--b0);border-radius:var(--r);padding:14px 16px;margin-bottom:16px">
                    <i class="fab fa-discord" style="font-size:2rem;color:#5865f2"></i>
                    <div style="flex:1">
                        <div style="font-weight:700;font-size:0.95rem">Discord ID: <code style="color:#5865f2"><?= e($currentUser['discord_id']) ?></code></div>
                        <div style="font-size:0.78rem;color:var(--t2);margin-top:2px">
                            Your Discord account is linked and <?php echo (defined('DISCORD_ENABLED') && DISCORD_ENABLED) ? '<span style="color:#2ecc71">your verified role has been assigned in the server</span>' : 'connected'; ?>.
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <a href="<?= SITE_URL ?>/discord-callback.php?connect=1" class="btn btn-discord btn-sm">
                        <i class="fab fa-discord"></i> Re-link Discord
                    </a>
                    <a href="<?= SITE_URL ?>/discord-callback.php?unlink=1&csrf=<?= generateCSRF() ?>"
                       class="btn btn-ghost btn-sm" style="color:var(--red)"
                       data-confirm="Unlink your Discord account? You may lose your verified role.">
                        <i class="fas fa-unlink"></i> Unlink
                    </a>
                </div>

                <?php else: ?>
                <!-- Not linked -->
                <div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:20px">
                    <i class="fab fa-discord" style="font-size:2.5rem;color:#5865f2;opacity:0.7;margin-top:4px"></i>
                    <div>
                        <div style="font-weight:700;margin-bottom:6px">Link your Discord account</div>
                        <div style="font-size:0.88rem;color:var(--t1);line-height:1.7">
                            Connecting your Discord account will automatically assign you the
                            <strong style="color:#5865f2">Verified</strong> role in the LAE Discord server,
                            confirming your forum membership.
                        </div>
                    </div>
                </div>
                <?php if (!defined('DISCORD_ENABLED') || !DISCORD_ENABLED): ?>
                <div class="alert alert-warning" style="margin-bottom:16px">
                    <i class="fas fa-triangle-exclamation"></i>
                    Discord integration is not yet configured by the server admin. Check back soon.
                </div>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/discord-callback.php?connect=1"
                   class="btn btn-discord">>
                    <i class="fab fa-discord" style="font-size:1.1rem"></i> Connect with Discord
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-lock"></i> Change Password</h3></div>
            <div style="padding:20px">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-input" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-accent"><i class="fas fa-key"></i> Update Password</button>
                </form>
            </div>
        </div>

        <!-- Account Info -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-circle-info"></i> Account Information</h3></div>
            <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div>
                    <div class="form-label">Username</div>
                    <div style="color:var(--t0);font-weight:600"><?= e($currentUser['username']) ?></div>
                </div>
                <div>
                    <div class="form-label">Email</div>
                    <div style="color:var(--t1)"><?= e($currentUser['email']) ?></div>
                </div>
                <div>
                    <div class="form-label">Role</div>
                    <div><?= getRoleBadge($currentUser) ?></div>
                </div>
                <div>
                    <div class="form-label">Member Since</div>
                    <div style="color:var(--t1)"><?= formatDate($currentUser['created_at']) ?></div>
                </div>
                <div>
                    <div class="form-label">Post Count</div>
                    <div style="font-family:var(--font-head);font-size:1.4rem;color:var(--red)"><?= number_format($currentUser['post_count']) ?></div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
