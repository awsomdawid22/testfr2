<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$currentUser = getCurrentUser();
if ($currentUser) { header('Location: ' . SITE_URL . '/'); exit; }

$pageTitle = 'Forgot Password · LAE Forums';
$error  = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $email = trim(strtolower($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, email FROM users WHERE LOWER(email) = ? AND is_banned = 0");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Always show the same message to prevent email enumeration
            $success = 'If an account with that email exists, a password reset link has been sent. Check your inbox (and spam folder).';

            if ($user) {
                // Generate secure token
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));

                $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")
                   ->execute([$token, $expires, $user['id']]);

                // Send the email
                $sent = sendPasswordResetEmail($user['email'], $user['username'], $token);
                if (!$sent) {
                    // Don't expose error to user but log it
                    error_log("[LAE] Failed to send reset email to {$user['email']}");
                }
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
            <h1 style="font-family:var(--font-head);font-size:1.8rem;font-weight:900;letter-spacing:2px;text-transform:uppercase;text-align:center">Reset Password</h1>
            <p style="color:var(--t1);font-size:0.8rem;margin-top:5px;text-align:center">Los Angeles Experience Forums</p>
        </div>
        <div class="auth-body">

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= e($success) ?>
                </div>
                <div style="text-align:center;margin-top:20px">
                    <a href="<?= SITE_URL ?>/login.php" class="btn btn-ghost">← Back to Login</a>
                </div>
            <?php else: ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input"
                               placeholder="Enter the email on your account"
                               value="<?= e($_POST['email'] ?? '') ?>"
                               required autofocus>
                    </div>

                    <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:12px">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>

                <div style="margin-top:20px;text-align:center;font-size:0.85rem;color:var(--t1)">
                    Remembered it? <a href="<?= SITE_URL ?>/login.php" style="color:var(--red);text-decoration:none;font-weight:700">Back to Login</a>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
