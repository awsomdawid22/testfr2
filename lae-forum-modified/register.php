<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

// Load settings to check if registrations are open
$settingsFile = __DIR__ . '/includes/settings.php';
$siteSettings = file_exists($settingsFile) ? include $settingsFile : [];
$registrationsOpen = ($siteSettings['registrations_open'] ?? '1') === '1';

$currentUser = getCurrentUser();
if ($currentUser) { header('Location: ' . SITE_URL . '/'); exit; }

$pageTitle = 'Register · LAE Forums';
$error = '';
$success = '';

// Check if registrations are closed
if (!$registrationsOpen) {
    $error = 'Registrations are currently closed. Please check back later or contact staff.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registrationsOpen) {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } elseif ($_POST['password'] !== $_POST['password_confirm']) {
        $error = 'Passwords do not match.';
    } elseif (empty($_POST['agree'])) {
        $error = 'You must agree to the rules and terms.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $result   = register($username, $email, $_POST['password'] ?? '');
        if ($result['success']) {
            // Generate email verification token
            $db = getDB();
            $verifyToken = bin2hex(random_bytes(32));
            $db->prepare("UPDATE users SET verification_token = ?, email_verified = 0 WHERE username = ?")
               ->execute([$verifyToken, $username]);
            // Send welcome/verify email (non-blocking — failure doesn't break registration)
            sendWelcomeEmail($email, $username, $verifyToken);
            // Auto-login
            login($username, $_POST['password']);
            header('Location: ' . SITE_URL . '/?welcome=1');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-split-card">

        <!-- Left panel: branding -->
        <div class="auth-split-left">
            <img src="<?= SITE_URL ?>/public/images/LARPWhite.png"
                 alt="Los Angeles Experience"
                 style="width:170px;height:auto;display:block;margin:0 auto 24px;filter:drop-shadow(0 4px 20px rgba(0,0,0,0.8))">
            <h2 class="auth-split-title">Join the Community</h2>
            <p class="auth-split-sub">Los Angeles Experience</p>
            <div class="auth-split-divider"></div>
            <ul class="auth-split-perks">
                <li><i class="fas fa-check-circle" style="color:var(--green)"></i> Post in community forums</li>
                <li><i class="fas fa-check-circle" style="color:var(--green)"></i> Submit staff applications</li>
                <li><i class="fas fa-check-circle" style="color:var(--green)"></i> File ban appeals</li>
                <li><i class="fas fa-check-circle" style="color:var(--green)"></i> Message other members</li>
            </ul>
        </div>

        <!-- Right panel: form -->
        <div class="auth-split-right">
            <h1 class="auth-split-form-title"><?= $registrationsOpen ? 'Create Account' : 'Registrations Closed' ?></h1>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
            <?php endif; ?>

            <?php if (!$registrationsOpen): ?>
            <div style="text-align:center;padding:20px 0">
                <i class="fas fa-user-lock" style="font-size:2.5rem;color:var(--t2);margin-bottom:14px;display:block"></i>
                <p style="color:var(--t1);font-size:0.88rem;margin-bottom:20px;line-height:1.6">
                    New account registration is currently disabled.<br>
                    Please check back later or contact staff.
                </p>
                <div style="display:flex;gap:10px;justify-content:center">
                    <a href="<?= SITE_URL ?>/login.php" class="btn btn-accent"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <a href="<?= e($siteSettings['discord_url'] ?? '#') ?>" class="btn btn-ghost" target="_blank"><i class="fab fa-discord"></i> Discord</a>
                </div>
            </div>
            <?php else: ?>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-input" placeholder="3-30 chars, letters/numbers/underscores"
                           value="<?= e($_POST['username'] ?? '') ?>" minlength="3" maxlength="30" pattern="[a-zA-Z0-9_]+" required autofocus>
                    <div class="form-hint">This will be your display name across the forum.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="your@email.com"
                           value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Minimum 8 characters" minlength="8" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password_confirm" class="form-input" placeholder="Repeat your password" required>
                </div>

                <div style="margin-bottom:20px;display:flex;gap:10px;align-items:flex-start">
                    <input type="checkbox" name="agree" id="agree" style="margin-top:3px;accent-color:var(--red)">
                    <label for="agree" style="font-size:0.85rem;color:var(--t0);cursor:pointer">
                        I agree to the <a href="<?= SITE_URL ?>/rules.php" target="_blank" style="color:var(--red)">Server Rules</a> and understand that violations may result in a ban.
                    </label>
                </div>

                <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:12px;font-size:0.9rem">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>

                <div style="margin-top:14px;display:flex;gap:10px;align-items:flex-start;background:rgba(52,152,219,0.08);border:1px solid rgba(52,152,219,0.2);border-radius:var(--radius);padding:12px 14px">
                    <i class="fas fa-envelope" style="color:#3498db;margin-top:2px;flex-shrink:0"></i>
                    <div style="font-size:0.82rem;color:var(--t0);line-height:1.6">
                        A <strong style="color:var(--t0)">verification email</strong> will be sent to your address after registration.
                        Check your inbox (and spam folder) to verify your account.
                    </div>
                </div>
            </form>

            <div style="margin-top:16px;text-align:center;font-size:0.82rem;color:var(--t1)">
                Already have an account? <a href="<?= SITE_URL ?>/login.php" style="color:var(--red);text-decoration:none;font-weight:700">Sign in →</a>
            </div>
            <?php endif; ?>
        </div><!-- /auth-split-right -->
    </div><!-- /auth-split-card -->
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
