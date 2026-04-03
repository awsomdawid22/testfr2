<?php
require_once __DIR__ . '/config.php';

// ============================================
// Utility / Helper Functions
// ============================================

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function slug(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

function timeAgo(string $datetime): string {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 604800) return floor($time/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function formatDate(string $datetime): string {
    return date('M j, Y g:i A', strtotime($datetime));
}

function paginate(int $total, int $perPage, int $current): array {
    $totalPages = max(1, ceil($total / $perPage));
    $current = max(1, min($current, $totalPages));
    $offset = ($current - 1) * $perPage;
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $current,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $current > 1,
        'has_next' => $current < $totalPages,
    ];
}

function renderPagination(array $pag, string $baseUrl): string {
    if ($pag['total_pages'] <= 1) return '';
    $html = '<div class="pagination">';
    if ($pag['has_prev']) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($pag['current_page']-1) . '" class="page-btn">&#8592;</a>';
    }
    $start = max(1, $pag['current_page'] - 2);
    $end = min($pag['total_pages'], $pag['current_page'] + 2);
    if ($start > 1) $html .= '<a href="' . $baseUrl . '?page=1" class="page-btn">1</a>' . ($start > 2 ? '<span class="dots">…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pag['current_page'] ? ' active' : '';
        $html .= '<a href="' . $baseUrl . '?page=' . $i . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    if ($end < $pag['total_pages']) {
        $html .= ($end < $pag['total_pages']-1 ? '<span class="dots">…</span>' : '') . '<a href="' . $baseUrl . '?page=' . $pag['total_pages'] . '" class="page-btn">' . $pag['total_pages'] . '</a>';
    }
    if ($pag['has_next']) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($pag['current_page']+1) . '" class="page-btn">&#8594;</a>';
    }
    $html .= '</div>';
    return $html;
}

function sanitizePost(string $content): string {
    $content = e($content);

    // ── Text formatting ──────────────────────────────────────────────────
    $content = preg_replace('/\[b\](.*?)\[\/b\]/s',          '<strong>$1</strong>', $content);
    $content = preg_replace('/\[i\](.*?)\[\/i\]/s',          '<em>$1</em>', $content);
    $content = preg_replace('/\[u\](.*?)\[\/u\]/s',          '<u>$1</u>', $content);
    $content = preg_replace('/\[s\](.*?)\[\/s\]/s',          '<del>$1</del>', $content);
    $content = preg_replace('/\[sup\](.*?)\[\/sup\]/s',      '<sup>$1</sup>', $content);
    $content = preg_replace('/\[sub\](.*?)\[\/sub\]/s',      '<sub>$1</sub>', $content);

    // ── Colour & size ────────────────────────────────────────────────────
    $content = preg_replace('/\[color=([a-zA-Z#0-9]+)\](.*?)\[\/color\]/s',
        '<span style="color:$1">$2</span>', $content);
    $content = preg_replace('/\[size=([1-6])\](.*?)\[\/size\]/s',
        '<span style="font-size:$1em">$2</span>', $content);
    $content = preg_replace('/\[highlight\](.*?)\[\/highlight\]/s',
        '<mark style="background:rgba(255,220,0,0.3);color:inherit;padding:0 3px;border-radius:2px">$1</mark>', $content);

    // ── Alignment ────────────────────────────────────────────────────────
    $content = preg_replace('/\[center\](.*?)\[\/center\]/s', '<div style="text-align:center">$1</div>', $content);
    $content = preg_replace('/\[right\](.*?)\[\/right\]/s',   '<div style="text-align:right">$1</div>', $content);
    $content = preg_replace('/\[left\](.*?)\[\/left\]/s',     '<div style="text-align:left">$1</div>', $content);

    // ── Quote ────────────────────────────────────────────────────────────
    // Named quote: [quote=Username]...[/quote]
    $content = preg_replace('/\[quote=([^\]]{1,80})\](.*?)\[\/quote\]/s',
        '<blockquote class="post-quote"><cite>$1 wrote:</cite>$2</blockquote>', $content);
    $content = preg_replace('/\[quote\](.*?)\[\/quote\]/s',
        '<blockquote class="post-quote">$1</blockquote>', $content);

    // ── Code ─────────────────────────────────────────────────────────────
    // Syntax-highlighted: [code=php]...[/code]
    $content = preg_replace('/\[code=([a-z0-9+#]{1,20})\](.*?)\[\/code\]/s',
        '<pre class="post-code"><span class="post-code-lang">$1</span>$2</pre>', $content);
    $content = preg_replace('/\[code\](.*?)\[\/code\]/s',
        '<pre class="post-code">$1</pre>', $content);

    // Inline code
    $content = preg_replace('/\[icode\](.*?)\[\/icode\]/s',
        '<code class="post-icode">$1</code>', $content);

    // ── Lists ─────────────────────────────────────────────────────────────
    // [list] with [*] items
    $content = preg_replace_callback('/\[list\](.*?)\[\/list\]/s', function($m) {
        $items = preg_replace('/\[\*\](.*?)(?=\[\*\]|\[\/list\]|$)/s', '<li>$1</li>', $m[1]);
        return '<ul class="post-list">' . $items . '</ul>';
    }, $content);
    $content = preg_replace_callback('/\[list=1\](.*?)\[\/list\]/s', function($m) {
        $items = preg_replace('/\[\*\](.*?)(?=\[\*\]|\[\/list\]|$)/s', '<li>$1</li>', $m[1]);
        return '<ol class="post-list">' . $items . '</ol>';
    }, $content);

    // ── Links ─────────────────────────────────────────────────────────────
    $content = preg_replace('/\[url=(https?:\/\/[^\]]+)\](.*?)\[\/url\]/s',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="post-link">$2</a>', $content);
    $content = preg_replace('/\[url\](https?:\/\/[^\[]+)\[\/url\]/s',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="post-link">$1</a>', $content);

    // ── Media ─────────────────────────────────────────────────────────────
    $content = preg_replace('/\[img\](https?:\/\/[^\[]+)\[\/img\]/s',
        '<img src="$1" alt="image" class="post-img" loading="lazy">', $content);
    $content = preg_replace('/\[img=(\d+)x(\d+)\](https?:\/\/[^\[]+)\[\/img\]/s',
        '<img src="$3" alt="image" class="post-img" width="$1" height="$2" loading="lazy">', $content);

    // YouTube embed: [youtube]video_id[/youtube]  or full URL
    $content = preg_replace_callback('/\[youtube\](.*?)\[\/youtube\]/s', function($m) {
        $id = trim($m[1]);
        // Extract video ID from URL if full URL given
        if (preg_match('/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $id, $vm)) $id = $vm[1];
        if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $id)) return $m[0];
        return '<div class="post-video-wrap"><iframe src="https://www.youtube.com/embed/' . $id . '" allowfullscreen loading="lazy"></iframe></div>';
    }, $content);

    // ── Divider & spacer ──────────────────────────────────────────────────
    $content = preg_replace('/\[hr\]/', '<hr class="post-hr">', $content);
    $content = preg_replace('/\[br\]/', '<br>', $content);

    // ── Spoiler ───────────────────────────────────────────────────────────
    $content = preg_replace_callback('/\[spoiler(?:=([^\]]{0,80}))?\](.*?)\[\/spoiler\]/s', function($m) {
        $label = $m[1] ? htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') : 'Spoiler';
        return '<details class="post-spoiler"><summary>' . $label . '</summary><div>' . $m[2] . '</div></details>';
    }, $content);

    // ── Notice / callout ──────────────────────────────────────────────────
    $content = preg_replace('/\[notice\](.*?)\[\/notice\]/s',
        '<div class="post-notice post-notice-info">$1</div>', $content);
    $content = preg_replace('/\[warning\](.*?)\[\/warning\]/s',
        '<div class="post-notice post-notice-warn">$1</div>', $content);
    $content = preg_replace('/\[success\](.*?)\[\/success\]/s',
        '<div class="post-notice post-notice-success">$1</div>', $content);

    $content = nl2br($content);
    return $content;
}

function getRoleBadge(array $user): string {
    $color    = e($user['role_color']   ?? '#95a5a6');
    $bg       = e($user['badge_color']  ?? '#111');
    $name     = e($user['role_display'] ?? $user['display_name'] ?? 'Member');
    $roleName = $user['role_name'] ?? $user['name'] ?? '';
    $animation = $user['animation'] ?? $user['role_animation'] ?? '';
    $animClass = $animation ? ' role-anim-' . e($animation) : '';

    // Icon for every role — always shown
    $iconMap = [
        'owner'        => 'fa-crown',
        'co_owner'     => 'fa-crown',
        'head_admin'   => 'fa-shield-halved',
        'admin'        => 'fa-shield-halved',
        'senior_mod'   => 'fa-shield',
        'moderator'    => 'fa-shield',
        'support'      => 'fa-headset',
        'senior_dev'   => 'fa-code',
        'developer'    => 'fa-code',
        'police_chief' => 'fa-star',
        'ems_chief'    => 'fa-star-of-life',
        'vip_plus'     => 'fa-gem',
        'vip'          => 'fa-gem',
        'trusted'      => 'fa-check-circle',
        'member'       => 'fa-user',
    ];
    $canMod = !empty($user['can_moderate']) || !empty($user['can_admin']);
    $icon = $iconMap[$roleName] ?? ($canMod ? 'fa-shield' : 'fa-user');
    $iconHtml = '<i class="fas ' . $icon . '" style="font-size:0.58rem;opacity:0.9"></i>';

    return "<span class=\"role-badge{$animClass}\" style=\"color:{$color};background:{$bg};border-color:{$color}40\">{$iconHtml} {$name}</span>";
}

function getAvatarUrl(?string $avatar, string $username): string {
    if ($avatar && file_exists(__DIR__ . '/../' . $avatar)) {
        return SITE_URL . '/' . $avatar;
    }
    return 'https://ui-avatars.com/api/?name=' . urlencode($username) . '&background=1a1a2e&color=e91e8c&bold=true&size=128';
}

function getUnreadCount(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function getUnreadMessageCount(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function getRoleBadgeWithAnimation(array $user): string {
    // Just delegates to getRoleBadge — keeps consistent icon logic
    $color = e($user['role_color'] ?? '#95a5a6');
    return getRoleBadge($user) . '';
}

function getForumStats(): array {
    $db = getDB();
    return [
        'members' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'threads' => $db->query("SELECT COUNT(*) FROM threads WHERE is_hidden = 0")->fetchColumn(),
        'posts' => $db->query("SELECT COUNT(*) FROM posts WHERE is_hidden = 0")->fetchColumn(),
        'newest' => $db->query("SELECT username FROM users ORDER BY created_at DESC LIMIT 1")->fetchColumn(),
    ];
}

// ============================================
// Per-Category Permission Helpers
// ============================================

/**
 * Check if a user's role can perform an action in a specific category.
 * Falls back to the role's global permission if no category-specific rules exist.
 *
 * @param int    $categoryId  The category ID
 * @param array  $user        Current user array (needs role_id, can_post, can_create_threads, can_moderate)
 * @param string $permission  'can_post' or 'can_create_threads'
 * @return bool
 */
function canUserInCategory(int $categoryId, array $user, string $permission): bool {
    // Moderators and above always bypass category restrictions
    if (!empty($user['can_moderate'])) return true;

    $db = getDB();
    $stmt = $db->prepare("SELECT $permission FROM category_permissions WHERE category_id = ? AND role_id = ?");
    $stmt->execute([$categoryId, $user['role_id']]);
    $row = $stmt->fetch();

    if ($row !== false) {
        // A category-specific rule exists — use it
        return (bool)$row[$permission];
    }

    // No category-specific rule — check if ANY rules exist for this category
    $hasRules = $db->prepare("SELECT COUNT(*) FROM category_permissions WHERE category_id = ?");
    $hasRules->execute([$categoryId]);
    if ((int)$hasRules->fetchColumn() > 0) {
        // Rules exist but this role isn't listed — deny by default
        return false;
    }

    // No rules at all for this category — fall back to global role permission
    return !empty($user[$permission]);
}

/**
 * Get all category permissions for a specific category (for admin UI).
 * Returns array keyed by role_id.
 */
function getCategoryPermissions(int $categoryId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT cp.*, r.display_name, r.color, r.priority FROM category_permissions cp JOIN roles r ON cp.role_id = r.id WHERE cp.category_id = ? ORDER BY r.priority DESC");
    $stmt->execute([$categoryId]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['role_id']] = $row;
    }
    return $result;
}
