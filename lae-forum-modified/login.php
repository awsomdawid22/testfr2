<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$settingsFile = __DIR__ . '/includes/settings.php';
$siteSettings = file_exists($settingsFile) ? include $settingsFile : [];
$maintenanceMode = ($siteSettings['maintenance_mode'] ?? '0') === '1';

$currentUser = getCurrentUser();
if ($currentUser) { header('Location: ' . SITE_URL . '/'); exit; }

$pageTitle = 'Login · LAE Forums';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid. Please try again.';
    } else {
        $result = login($_POST['username'] ?? '', $_POST['password'] ?? '', ['remember' => !empty($_POST['remember'])]);
        if ($result['success']) {
            if (!empty($result['banned'])) {
                header('Location: ' . SITE_URL . '/appeal.php');
            } else {
                $redirect = $_GET['redirect'] ?? SITE_URL . '/';
                header('Location: ' . $redirect);
            }
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
            <h2 class="auth-split-title">Welcome Back</h2>
            <p class="auth-split-sub">Los Angeles Experience Forums</p>
            <div class="auth-split-divider"></div>
            <p class="auth-split-desc">Your West Coast FiveM roleplay community. Sign in to continue the story.</p>
        </div>

        <!-- Right panel: form -->
        <div class="auth-split-right">
            <h1 class="auth-split-form-title">Sign In</h1>

            <?php if ($maintenanceMode): ?>
                <div class="alert alert-warning"><i class="fas fa-wrench"></i> Site is in maintenance mode. Only staff can access.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label">Username or Email</label>
                    <input type="text" name="username" class="form-input"
                           placeholder="Enter your username or email"
                           value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input"
                           placeholder="Enter your password" required>
                </div>

                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                    <input type="checkbox" name="remember" id="remember" value="1"
                           style="width:15px;height:15px;accent-color:var(--red);cursor:pointer;flex-shrink:0">
                    <label for="remember" style="font-size:0.83rem;color:var(--t1);cursor:pointer;user-select:none">
                        Stay signed in for 30 days
                    </label>
                </div>
                <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center;padding:11px;margin-bottom:12px">
                    <i class="fas fa-right-to-bracket"></i> Login
                </button>
            </form>

            <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.82rem">
                <a href="<?= SITE_URL ?>/forgot-password.php" style="color:var(--t1);text-decoration:none">
                    <i class="fas fa-key" style="font-size:0.72rem;margin-right:4px"></i>Forgot password?
                </a>
                <a href="<?= SITE_URL ?>/register.php" style="color:var(--red);text-decoration:none;font-weight:700">
                    Create account →
                </a>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
