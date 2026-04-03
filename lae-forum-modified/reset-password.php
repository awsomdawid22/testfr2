<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();
if ($currentUser) { header('Location: ' . SITE_URL . '/'); exit; }

$pageTitle = 'Reset Password · LAE Forums';
$error   = '';
$success = '';
$token   = trim($_GET['token'] ?? '');
$user    = null;

// Validate token
if ($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE reset_token = ? AND reset_expires > NOW() AND is_banned = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $postToken = trim($_POST['token'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $db = getDB();
            // Re-validate token at submit time
            $stmt = $db->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_expires > NOW()");
            $stmt->execute([$postToken]);
            $resetUser = $stmt->fetch();

            if (!$resetUser) {
                $error = 'This reset link has expired or is invalid. Please request a new one.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
                   ->execute([$hash, $resetUser['id']]);

                logAudit($resetUser['id'], 'password_reset', 'user', $resetUser['id'], 'Password reset via email link');
                $success = 'Your password has been reset successfully. You can now log in with your new password.';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card" style="max-width:440px">
        <div class="auth-header" style="text-align:center;padding:28px 28px 20px">
            <img src="<?= SITE_URL ?>/public/images/LARPWhite.png"
                 alt="LAE" style="width:140px;height:auto;display:block;margin:0 auto 16px;filter:drop-shadow(0 2px 12px rgba(0,0,0,0.6))">
            <h1 style="font-family:var(--font-head);font-size:1.8rem;font-weight:900;letter-spacing:2px;text-transform:uppercase;text-align:center">Set New Password</h1>
            <p style="color:var(--t1);font-size:0.8rem;margin-top:5px;text-align:center">Los Angeles Experience Forums</p>
        </div>
        <div class="auth-body">

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success) ?></div>
                <div style="text-align:center;margin-top:20px">
                    <a href="<?= SITE_URL ?>/login.php" class="btn btn-accent"><i class="fas fa-right-to-bracket"></i> Login Now</a>
                </div>

            <?php elseif (!$token || !$user): ?>
                <div class="alert alert-error">
                    <i class="fas fa-triangle-exclamation"></i>
                    This password reset link is <strong>invalid or has expired</strong>. Reset links are only valid for 2 hours.
                </div>
                <div style="text-align:center;margin-top:20px">
                    <a href="<?= SITE_URL ?>/forgot-password.php" class="btn btn-accent">Request New Link</a>
                </div>

            <?php else: ?>
                <div class="alert alert-info" style="margin-bottom:20px">
                    <i class="fas fa-user"></i> Resetting password for <strong><?= e($user['username']) ?></strong>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-input"
                               placeholder="Minimum 8 characters"
                               required autofocus id="pw-input">
                        <div id="pw-strength" style="height:3px;border-radius:2px;margin-top:6px;background:var(--b0);overflow:hidden">
                            <div id="pw-bar" style="height:100%;width:0;transition:width 0.3s,background 0.3s"></div>
                        </div>
                        <div id="pw-label" style="font-size:0.72rem;color:var(--t1);margin-top:4px"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirm" class="form-input"
                               placeholder="Type it again" required>
                    </div>

                    <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:12px">
                        <i class="fas fa-key"></i> Set New Password
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const pwInput = document.getElementById('pw-input');
const pwBar   = document.getElementById('pw-bar');
const pwLabel = document.getElementById('pw-label');
if (pwInput) {
    pwInput.addEventListener('input', () => {
        const v = pwInput.value;
        let score = 0;
        if (v.length >= 8)  score++;
        if (v.length >= 12) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        const colours = ['','#e74c3c','#e67e22','#f1c40f','#2ecc71','#00b894'];
        const labels  = ['','Very Weak','Weak','Fair','Strong','Very Strong'];
        pwBar.style.width   = (score * 20) + '%';
        pwBar.style.background = colours[score] || '';
        pwLabel.textContent = labels[score] || '';
        pwLabel.style.color = colours[score] || '';
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
