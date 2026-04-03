<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Verify Email · LAE Forums';
$token = trim($_GET['token'] ?? '');
$success = false;
$username = '';

if ($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username FROM users WHERE verification_token = ? AND email_verified = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $db->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?")
           ->execute([$user['id']]);
        logAudit($user['id'], 'email_verified', 'user', $user['id'], 'Email verified');
        $success  = true;
        $username = $user['username'];
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <?php
$authTitle    = null;
$authSubtitle = null;
include __DIR__ . '/includes/auth-logo.php'; ?>
        </div>
        <div class="auth-body" style="text-align:center">
            <?php if ($success): ?>
                <div style="font-size:3rem;color:var(--green);margin-bottom:16px"><i class="fas fa-circle-check"></i></div>
                <h2 style="color:var(--t0);margin-bottom:8px">Email Verified!</h2>
                <p style="color:var(--t1)">
                    Welcome to LAE Forums, <strong style="color:var(--t0)"><?= e($username) ?></strong>!<br>
                    Your email has been verified. You now have full access to the community.
                </p>
                <a href="<?= SITE_URL ?>/login.php" class="btn btn-accent" style="margin-top:20px">
                    <i class="fas fa-right-to-bracket"></i> Login Now
                </a>
            <?php else: ?>
                <div style="font-size:3rem;color:var(--red);margin-bottom:16px"><i class="fas fa-circle-xmark"></i></div>
                <h2 style="color:var(--t0);margin-bottom:8px">Invalid Link</h2>
                <p style="color:var(--t1)">
                    This verification link is invalid or your email has already been verified.
                </p>
                <a href="<?= SITE_URL ?>/login.php" class="btn btn-ghost" style="margin-top:20px">Back to Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
