<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!verifyCSRF($_GET['csrf'] ?? '')) {
    header('Location: ' . SITE_URL . '/');
    exit;
}
logout();
header('Location: ' . SITE_URL . '/?logged_out=1');
exit;
