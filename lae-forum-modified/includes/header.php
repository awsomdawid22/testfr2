<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Load site settings
$siteSettings = [];
$settingsFile = __DIR__ . '/settings.php';
if (file_exists($settingsFile)) {
    $siteSettings = include $settingsFile;
}
$siteName = $siteSettings['site_name'] ?? SITE_NAME;
$siteTagline = $siteSettings['site_tagline'] ?? 'FiveM Roleplay Community';
$discordUrl = $siteSettings['discord_url'] ?? '#';
$twitterUrl = $siteSettings['twitter_url'] ?? '#';
$youtubeUrl = $siteSettings['youtube_url'] ?? '#';
$tiktokUrl = $siteSettings['tiktok_url'] ?? '#';
$fivemConnect = $siteSettings['fivem_connect'] ?? 'fivem://connect/play.laexperience.com';
$serverIp = $siteSettings['server_ip'] ?? 'play.laexperience.com';

$currentUser = getCurrentUser();

// ============================================
// Maintenance Mode Check
// ============================================
$maintenanceMode = ($siteSettings['maintenance_mode'] ?? '0') === '1';
$isAdminUser = $currentUser && ($currentUser['can_admin'] ?? false);
$isAdminPage = strpos($_SERVER['REQUEST_URI'], '/admin/') !== false;
$isLoginPage = strpos($_SERVER['REQUEST_URI'], '/login.php') !== false;
$isApiRequest = strpos($_SERVER['REQUEST_URI'], '/api/') !== false;

// If maintenance mode is on and user is not admin, show maintenance page
// Allow access to: login page, API endpoints, and admin pages (for admins logging in)
if ($maintenanceMode && !$isAdminUser && !$isLoginPage && !$isApiRequest) {
    // Show maintenance page
    include __DIR__ . '/maintenance.php';
    exit;
}
$unreadCount = $currentUser ? getUnreadCount($currentUser['id']) : 0;
$unreadMessages = $currentUser ? getUnreadMessageCount($currentUser['id']) : 0;
$csrf = generateCSRF();
$pageTitle = $pageTitle ?? $siteName . ' Forums';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="Los Angeles Experience FiveM Community Forums">
    <meta name="csrf-token" content="<?= generateCSRF() ?>">
    <meta name="site-url" content="<?= SITE_URL ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,400;0,600;0,700;0,800;0,900;1,700&family=Barlow:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= SITE_URL ?>/public/images/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= SITE_URL ?>/public/images/favicon-32.png">
    <link rel="apple-touch-icon" sizes="192x192" href="<?= SITE_URL ?>/public/images/favicon-192.png">
</head>
<body>
    <!-- Background Grid -->
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-inner">
            <a href="<?= SITE_URL ?>/" class="nav-logo">
                <img src="<?= SITE_URL ?>/public/images/LARPWhite.png" alt="<?= e($siteName) ?>" class="logo-image">
            </a>

            <div class="nav-links">
                <a href="<?= SITE_URL ?>/" class="nav-link"><i class="fas fa-home"></i> Home</a>
                <a href="<?= SITE_URL ?>/forum.php" class="nav-link"><i class="fas fa-comments"></i> Forums</a>
                <a href="<?= SITE_URL ?>/rules.php" class="nav-link"><i class="fas fa-scroll"></i> Rules</a>
                <a href="<?= SITE_URL ?>/staff.php" class="nav-link"><i class="fas fa-shield-halved"></i> Staff</a>
                <?php if ($currentUser && $currentUser['can_moderate']): ?>
                <a href="<?= SITE_URL ?>/admin/" class="nav-link nav-admin"><i class="fas fa-cog"></i> Admin</a>
                <?php endif; ?>
            </div>

            <div class="nav-actions">
                <?php if ($currentUser): ?>
                    <a href="<?= SITE_URL ?>/messages.php" class="notif-btn" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unreadMessages > 0): ?>
                            <span class="notif-badge msg-badge"><?= $unreadMessages > 99 ? '99+' : $unreadMessages ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= SITE_URL ?>/notifications.php" class="notif-btn" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="user-menu">
                        <button class="user-trigger">
                            <img src="<?= e(getAvatarUrl($currentUser['avatar'], $currentUser['username'])) ?>" alt="avatar" class="nav-avatar">
                            <span class="nav-username"><?= e($currentUser['username']) ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="user-dropdown">
                            <div class="dropdown-header">
                                <img src="<?= e(getAvatarUrl($currentUser['avatar'], $currentUser['username'])) ?>" alt="avatar">
                                <div>
                                    <strong><?= e($currentUser['username']) ?></strong>
                                    <?= getRoleBadge($currentUser) ?>
                                </div>
                            </div>
                            <a href="<?= SITE_URL ?>/profile.php?user=<?= $currentUser['id'] ?>"><i class="fas fa-user"></i> Profile</a>
                            <a href="<?= SITE_URL ?>/messages.php"><i class="fas fa-envelope"></i> Messages</a>
                            <a href="<?= SITE_URL ?>/settings.php"><i class="fas fa-gear"></i> Settings</a>
                            <?php if ($currentUser['can_admin']): ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= SITE_URL ?>/admin/" class="admin-link"><i class="fas fa-shield-halved"></i> Admin Panel</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= SITE_URL ?>/logout.php?csrf=<?= $csrf ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= SITE_URL ?>/login.php" class="btn btn-ghost">Login</a>
                    <a href="<?= SITE_URL ?>/register.php" class="btn btn-accent">Join Now</a>
                <?php endif; ?>
            </div>

            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="<?= SITE_URL ?>/">Home</a>
            <a href="<?= SITE_URL ?>/forum.php">Forums</a>
            <a href="<?= SITE_URL ?>/rules.php">Rules</a>
            <a href="<?= SITE_URL ?>/staff.php">Staff</a>
            <?php if ($currentUser): ?>
                <a href="<?= SITE_URL ?>/profile.php?user=<?= $currentUser['id'] ?>">Profile</a>
                <a href="<?= SITE_URL ?>/logout.php?csrf=<?= $csrf ?>">Logout</a>
            <?php else: ?>
                <a href="<?= SITE_URL ?>/login.php">Login</a>
                <a href="<?= SITE_URL ?>/register.php">Register</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="page-wrapper">
