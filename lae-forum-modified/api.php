<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$db = getDB();

// ═══════════════════════════════════════════════════════════════════════════
// SESSION CHECK - Check if user is banned/deleted (for live ban detection)
// This endpoint works even without login to properly handle session expiry
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'check_session') {
    if (!$currentUser) {
        echo json_encode([
            'success' => true,
            'logged_in' => false,
            'status' => 'logged_out'
        ]);
        exit;
    }
    
    // Re-fetch user from database to get latest status
    $stmt = $db->prepare("SELECT id, username, is_banned, ban_reason, ban_expires FROM users WHERE id = ?");
    $stmt->execute([$currentUser['id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Account was deleted
        echo json_encode([
            'success' => true,
            'logged_in' => false,
            'status' => 'deleted',
            'message' => 'Your account has been deleted.'
        ]);
        exit;
    }
    
    if ($user['is_banned']) {
        $banInfo = [
            'success' => true,
            'logged_in' => true,
            'status' => 'banned',
            'message' => 'Your account has been banned.',
            'reason' => $user['ban_reason'] ?? null,
            'expires' => $user['ban_expires'] ?? null
        ];
        
        // Format expiry if exists
        if ($user['ban_expires']) {
            $banInfo['expires_formatted'] = date('F j, Y \a\t g:i A', strtotime($user['ban_expires']));
            $banInfo['is_permanent'] = false;
        } else {
            $banInfo['is_permanent'] = true;
        }
        
        echo json_encode($banInfo);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'logged_in' => true,
        'status' => 'active',
        'username' => $user['username']
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// All other endpoints require login
// ═══════════════════════════════════════════════════════════════════════════
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// POLL COUNTS - Get notification and message counts
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'poll_counts') {
    $nStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $nStmt->execute([$currentUser['id']]);
    $notifs = (int)$nStmt->fetchColumn();

    $mStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $mStmt->execute([$currentUser['id']]);
    $msgs = (int)$mStmt->fetchColumn();
    
    // Get latest unread notifications for toast display
    $latestNotifs = [];
    $lnStmt = $db->prepare("
        SELECT id, type, title, content, link, created_at 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $lnStmt->execute([$currentUser['id']]);
    $latestNotifs = $lnStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'notifications' => $notifs,
        'messages' => $msgs,
        'latest_notifications' => $latestNotifs
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// POLL THREAD - Get thread updates (new reply count, etc.)
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'poll_thread') {
    $threadId = (int)($input['thread_id'] ?? 0);
    $lastPostId = (int)($input['last_post_id'] ?? 0);
    
    if (!$threadId) { 
        echo json_encode(['success' => false, 'error' => 'Invalid thread ID']); 
        exit; 
    }
    
    $stmt = $db->prepare("SELECT reply_count, last_reply_user_id, is_locked FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $row = $stmt->fetch();
    
    if (!$row) { 
        echo json_encode(['success' => false, 'error' => 'Thread not found']); 
        exit; 
    }
    
    // Get last replier username
    $lastUser = null;
    if ($row['last_reply_user_id']) {
        $uStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $uStmt->execute([$row['last_reply_user_id']]);
        $lastUser = $uStmt->fetchColumn();
    }
    
    // Count new posts since last_post_id
    $newPostCount = 0;
    if ($lastPostId > 0) {
        $npStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND id > ? AND is_hidden = 0");
        $npStmt->execute([$threadId, $lastPostId]);
        $newPostCount = (int)$npStmt->fetchColumn();
    }
    
    echo json_encode([
        'success' => true,
        'reply_count' => (int)$row['reply_count'],
        'last_reply_user' => $lastUser,
        'is_locked' => (bool)$row['is_locked'],
        'new_post_count' => $newPostCount
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// GET NEW POSTS - Fetch new posts since a given post ID
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'get_new_posts') {
    $threadId = (int)($input['thread_id'] ?? 0);
    $lastPostId = (int)($input['last_post_id'] ?? 0);
    
    if (!$threadId || !$lastPostId) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    $posts = $db->prepare("
        SELECT p.*, u.username, u.avatar, u.post_count, u.bio, u.discord_id, u.created_at as joined,
               r.display_name as role_display, r.color as role_color, r.badge_color, r.name as role_name,
               r.can_moderate, r.can_admin,
               (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
               (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id AND user_id = ?) as user_liked
        FROM posts p
        JOIN users u ON p.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE p.thread_id = ? AND p.id > ? AND p.is_hidden = 0
        ORDER BY p.created_at ASC
        LIMIT 20
    ");
    $posts->execute([$currentUser['id'], $threadId, $lastPostId]);
    $newPosts = $posts->fetchAll(PDO::FETCH_ASSOC);
    
    // Process posts for JSON response
    $processedPosts = [];
    foreach ($newPosts as $post) {
        $processedPosts[] = [
            'id' => $post['id'],
            'content' => sanitizePost($post['content']),
            'content_raw' => $post['content'],
            'created_at' => $post['created_at'],
            'created_at_formatted' => formatDate($post['created_at']),
            'username' => $post['username'],
            'avatar' => getAvatarUrl($post['avatar'], $post['username']),
            'post_count' => $post['post_count'],
            'joined' => date('M Y', strtotime($post['joined'])),
            'discord_id' => $post['discord_id'],
            'role_display' => $post['role_display'],
            'role_color' => $post['role_color'],
            'badge_color' => $post['badge_color'],
            'role_name' => $post['role_name'],
            'can_moderate' => (bool)$post['can_moderate'],
            'can_admin' => (bool)$post['can_admin'],
            'like_count' => (int)$post['like_count'],
            'user_liked' => (bool)$post['user_liked'],
            'is_own_post' => $post['user_id'] == $currentUser['id'],
            'user_can_edit' => $post['user_id'] == $currentUser['id'] || $currentUser['can_moderate']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'posts' => $processedPosts,
        'count' => count($processedPosts)
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// POLL ONLINE USERS - Get currently online users
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'poll_online_users') {
    $limit = min(50, max(10, (int)($input['limit'] ?? 20)));
    
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.avatar, r.color as role_color, r.display_name as role_display
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND u.is_banned = 0
        ORDER BY r.priority DESC, u.last_seen DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total online count
    $countStmt = $db->query("SELECT COUNT(*) FROM users WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND is_banned = 0");
    $totalOnline = (int)$countStmt->fetchColumn();
    
    $processedUsers = [];
    foreach ($users as $user) {
        $processedUsers[] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'avatar' => getAvatarUrl($user['avatar'], $user['username']),
            'role_color' => $user['role_color'],
            'role_display' => $user['role_display']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'users' => $processedUsers,
        'total_online' => $totalOnline
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// MARK NOTIFICATION READ
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'mark_notification_read') {
    $notifId = (int)($input['notification_id'] ?? 0);
    
    if ($notifId) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
           ->execute([$notifId, $currentUser['id']]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// LIKE POST
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'like') {
    $postId = (int)($input['post_id'] ?? 0);
    if (!$postId) { echo json_encode(['success' => false]); exit; }

    // Check post exists
    $post = $db->prepare("SELECT id, user_id FROM posts WHERE id = ? AND is_hidden = 0");
    $post->execute([$postId]);
    $post = $post->fetch();
    if (!$post) { echo json_encode(['success' => false]); exit; }

    // Toggle like
    $check = $db->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
    $check->execute([$postId, $currentUser['id']]);

    if ($check->fetch()) {
        // Unlike
        $db->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?")->execute([$postId, $currentUser['id']]);
        $db->prepare("UPDATE posts SET likes = likes - 1 WHERE id = ? AND likes > 0")->execute([$postId]);
        $liked = false;
    } else {
        // Like
        $db->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?,?)")->execute([$postId, $currentUser['id']]);
        $db->prepare("UPDATE posts SET likes = likes + 1 WHERE id = ?")->execute([$postId]);
        $liked = true;

        // Notify post author if not self
        if ($post['user_id'] !== $currentUser['id']) {
            $db->prepare("INSERT INTO notifications (user_id, type, title, content) VALUES (?,?,?,?)")
               ->execute([$post['user_id'], 'like', 'New Like', $currentUser['username'] . ' liked your post.']);
        }
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
    $countStmt->execute([$postId]);
    $count = (int)$countStmt->fetchColumn();

    echo json_encode(['success' => true, 'liked' => $liked, 'count' => $count]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// EDIT POST
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'edit_post') {
    $postId  = (int)($input['post_id'] ?? 0);
    $content = trim($input['content'] ?? '');
    if (!$postId || strlen($content) < 5) { echo json_encode(['success' => false, 'error' => 'Invalid data']); exit; }

    // Check ownership or mod
    $post = $db->prepare("SELECT id, user_id, thread_id FROM posts WHERE id = ? AND is_hidden = 0");
    $post->execute([$postId]);
    $post = $post->fetch();
    if (!$post) { echo json_encode(['success' => false, 'error' => 'Post not found']); exit; }
    if ($post['user_id'] !== $currentUser['id'] && !$currentUser['can_moderate']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); exit;
    }

    $db->prepare("UPDATE posts SET content = ?, edited_at = NOW(), edited_by = ? WHERE id = ?")
       ->execute([$content, $currentUser['id'], $postId]);
    logAudit($currentUser['id'], 'edit_post', 'post', $postId, 'Post edited');
    echo json_encode(['success' => true, 'content' => sanitizePost($content)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// Unknown action
// ═══════════════════════════════════════════════════════════════════════════
echo json_encode(['success' => false, 'error' => 'Unknown action']);
