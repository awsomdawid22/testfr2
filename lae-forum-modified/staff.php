<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Staff Team · LAE Forums';
$db = getDB();

$staffRoles = $db->query("
    SELECT r.*,
           GROUP_CONCAT(u.id        ORDER BY u.username SEPARATOR ',')  AS user_ids,
           GROUP_CONCAT(u.username  ORDER BY u.username SEPARATOR '||') AS usernames,
           GROUP_CONCAT(u.avatar    ORDER BY u.username SEPARATOR '||') AS avatars,
           GROUP_CONCAT(COALESCE(u.last_seen,'') ORDER BY u.username SEPARATOR '||') AS last_seens
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.id AND u.is_banned = 0
    WHERE r.priority >= 35
    GROUP BY r.id
    ORDER BY r.priority DESC
")->fetchAll();

$siteSettings = file_exists(__DIR__ . '/includes/settings.php') ? include __DIR__ . '/includes/settings.php' : [];

include __DIR__ . '/includes/header.php';

// Icon map for roles (same as getRoleBadge)
$iconMap = [
    'owner'       => 'fa-crown',
    'co_owner'    => 'fa-crown',
    'head_admin'  => 'fa-shield-halved',
    'admin'       => 'fa-shield-halved',
    'senior_mod'  => 'fa-shield',
    'moderator'   => 'fa-shield',
    'support'     => 'fa-headset',
    'senior_dev'  => 'fa-code',
    'developer'   => 'fa-code',
    'police_chief'=> 'fa-star',
    'ems_chief'   => 'fa-star-of-life',
    'vip_plus'    => 'fa-gem',
    'vip'         => 'fa-gem',
    'trusted'     => 'fa-check-circle',
];

$totalStaff = 0;
foreach ($staffRoles as $r) { if ($r['user_ids']) $totalStaff += count(explode(',', $r['user_ids'])); }
?>

<div class="container staff-container">

    <!-- Page header -->
    <div class="staff-page-header">
        <div class="staff-page-icon"><i class="fas fa-shield-halved"></i></div>
        <div>
            <h1 class="staff-page-title">Staff <span>Team</span></h1>
            <p class="staff-page-sub">
                <?= $totalStaff ?> dedicated members keeping Los Angeles Experience running smoothly.
            </p>
        </div>
        <a href="<?= SITE_URL ?>/forum.php?cat=applications" class="btn btn-ghost" style="margin-left:auto;flex-shrink:0">
            <i class="fas fa-file-alt"></i> Apply for Staff
        </a>
    </div>

    <?php
    $delay = 0;
    foreach ($staffRoles as $role):
        if (!$role['user_ids']) continue;
        $userIds   = explode(',',  $role['user_ids']);
        $usernames = explode('||', $role['usernames']);
        $avatars   = explode('||', $role['avatars']);
        $lastSeens = explode('||', $role['last_seens']);
        $icon      = $iconMap[$role['name']] ?? ($role['can_moderate'] ? 'fa-shield' : 'fa-user');
        $onlineInGroup = 0;
        foreach ($lastSeens as $ls) { if ($ls && strtotime($ls) > time() - 900) $onlineInGroup++; }
    ?>
    <div class="staff-group stagger-in" style="--stagger:<?= $delay++ ?>">
        <!-- Group header -->
        <div class="staff-group-header">
            <div class="staff-group-icon" style="color:<?= e($role['color']) ?>;background:<?= e($role['color']) ?>18">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div>
                <div class="staff-group-name" style="color:<?= e($role['color']) ?>"><?= e($role['display_name']) ?></div>
                <div class="staff-group-count"><?= count($userIds) ?> member<?= count($userIds) !== 1 ? 's' : '' ?><?= $onlineInGroup > 0 ? ' · <span style="color:var(--green)">' . $onlineInGroup . ' online</span>' : '' ?></div>
            </div>
            <div class="staff-group-line" style="background:<?= e($role['color']) ?>"></div>
        </div>

        <!-- Member cards -->
        <div class="staff-members-grid">
            <?php foreach ($usernames as $i => $username):
                if (!$username) continue;
                $uid      = $userIds[$i]   ?? 0;
                $avatar   = $avatars[$i]   ?? '';
                $lastSeen = $lastSeens[$i] ?? '';
                $isOnline = $lastSeen && strtotime($lastSeen) > time() - 900;
                $animClass = $role['animation'] ? ' role-anim-' . e($role['animation']) : '';
            ?>
            <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($username) ?>" class="staff-member-card">
                <!-- Top accent -->
                <div class="smc-accent" style="background:linear-gradient(90deg,<?= e($role['color']) ?>,transparent)"></div>

                <!-- Avatar -->
                <div class="smc-avatar-wrap">
                    <img src="<?= e(getAvatarUrl($avatar, $username)) ?>" class="smc-avatar" alt="<?= e($username) ?>">
                    <?php if ($isOnline): ?>
                        <span class="smc-online-dot"></span>
                    <?php endif; ?>
                </div>

                <!-- Name + Badge -->
                <div class="smc-name"><?= e($username) ?></div>
                <div class="smc-badge-wrap">
                    <span class="role-badge<?= $animClass ?>" style="color:<?= e($role['color']) ?>;background:<?= e($role['badge_color'] ?? '#111') ?>;border-color:<?= e($role['color']) ?>40">
                        <i class="fas <?= $icon ?>" style="font-size:0.6rem;filter:drop-shadow(0 0 2px currentColor)"></i>
                        <?= e($role['display_name']) ?>
                    </span>
                </div>

                <!-- Status -->
                <div class="smc-status <?= $isOnline ? 'smc-online' : '' ?>">
                    <?php if ($isOnline): ?>
                        <i class="fas fa-circle" style="font-size:0.45rem"></i> Online now
                    <?php elseif ($lastSeen): ?>
                        Last seen <?= timeAgo($lastSeen) ?>
                    <?php else: ?>
                        <span style="opacity:0.5">—</span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="alert alert-info" style="margin-top:8px">
        <i class="fas fa-circle-info"></i>
        <div>Want to join the team? Read the <a href="<?= SITE_URL ?>/rules.php" style="color:var(--red);font-weight:700">Server Rules</a> then head to <a href="<?= SITE_URL ?>/forum.php?cat=applications" style="color:var(--red);font-weight:700">Staff Applications</a>.</div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
