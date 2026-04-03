<?php
$adminPage = basename($_SERVER['PHP_SELF']);
$isAdmin = $currentUser['can_admin'] ?? false;

// Check maintenance mode status
$sidebarSettings = $siteSettings ?? [];
$sidebarMaintenanceMode = ($sidebarSettings['maintenance_mode'] ?? '0') === '1';

function adminLink($href, $icon, $label, $current): string {
    $active = basename($current) === basename($href) ? 'active' : '';
    return "<a href=\"" . SITE_URL . "/admin/{$href}\" class=\"admin-nav-link {$active}\"><i class=\"fas {$icon}\"></i>{$label}</a>";
}
?>
<div class="admin-sidebar">
    <div style="padding:16px 20px;border-bottom:1px solid var(--b0);margin-bottom:8px">
        <div style="font-family:var(--font-head);font-size:1.2rem;letter-spacing:3px;color:var(--red)">LAE ADMIN</div>
        <div style="font-size:0.72rem;letter-spacing:2px;color:var(--t1);text-transform:uppercase;margin-top:2px"><?= e($currentUser['role_display'] ?? 'Staff') ?></div>
    </div>
    
    <?php if ($sidebarMaintenanceMode): ?>
    <div style="padding:10px 16px;margin:0 8px 8px;background:rgba(231,76,60,0.15);border:1px solid rgba(231,76,60,0.3);border-radius:6px;display:flex;align-items:center;gap:8px">
        <i class="fas fa-wrench" style="color:var(--red)"></i>
        <div>
            <div style="font-size:0.75rem;font-weight:700;color:var(--red);text-transform:uppercase;letter-spacing:1px">Maintenance Mode</div>
            <div style="font-size:0.7rem;color:var(--t1)">Site locked to public</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="admin-sidebar-title">Main</div>
    <?= adminLink('index.php', 'fa-gauge', 'Dashboard', $adminPage) ?>
    <?= adminLink('users.php', 'fa-users', 'Users', $adminPage) ?>
    <?= adminLink('threads.php', 'fa-comments', 'Threads & Posts', $adminPage) ?>
    <?= adminLink('categories.php', 'fa-folder', 'Categories', $adminPage) ?>

    <div class="admin-sidebar-title" style="margin-top:10px">Applications</div>
    <?= adminLink('applications.php', 'fa-file-alt', 'Staff Applications', $adminPage) ?>
    <?= adminLink('appeals.php', 'fa-gavel', 'Ban Appeals', $adminPage) ?>
    <?= adminLink('bans.php', 'fa-ban', 'Ban Management', $adminPage) ?>

    <?php if ($isAdmin): ?>
    <div class="admin-sidebar-title" style="margin-top:10px">Administration</div>
    <?= adminLink('roles.php', 'fa-shield-halved', 'Roles & Permissions', $adminPage) ?>
    <?= adminLink('audit.php', 'fa-clipboard-list', 'Audit Log', $adminPage) ?>
    <?= adminLink('settings.php', 'fa-gear', 'Site Settings', $adminPage) ?>
    <?php endif; ?>

    <div style="padding:16px 20px;margin-top:10px;border-top:1px solid var(--b0)">
        <a href="<?= SITE_URL ?>/" class="admin-nav-link" style="padding:8px 0"><i class="fas fa-arrow-left"></i> Back to Forum</a>
    </div>
</div>
