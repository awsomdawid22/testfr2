<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$currentUser = getCurrentUser();
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$db = getDB();

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
    echo json_encode(['success' => true, 'content' => e($content)]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);

if ($action === 'poll_thread') {
    $threadId = (int)($data['thread_id'] ?? 0);
    if (!$threadId) { echo json_encode(['success'=>false]); exit; }
    $stmt = $db->prepare("SELECT reply_count, last_reply_user_id FROM threads WHERE id=?");
    $stmt->execute([$threadId]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['success'=>false]); exit; }
    $lastUser = null;
    if ($row['last_reply_user_id']) {
        $uStmt = $db->prepare("SELECT username FROM users WHERE id=?");
        $uStmt->execute([$row['last_reply_user_id']]);
        $lastUser = $uStmt->fetchColumn();
    }
    echo json_encode(['success'=>true,'reply_count'=>(int)$row['reply_count'],'last_reply_user'=>$lastUser]);
    exit;
}

if ($action === 'poll_counts') {
    if (!$currentUser) { echo json_encode(['success'=>false]); exit; }
    $notifs = (int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")
                      ->execute([$currentUser['id']]) ? 0 : 0;
    $nStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $nStmt->execute([$currentUser['id']]);
    $notifs = (int)$nStmt->fetchColumn();

    $mStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
    $mStmt->execute([$currentUser['id']]);
    $msgs = (int)$mStmt->fetchColumn();

    echo json_encode(['success'=>true,'notifications'=>$notifs,'messages'=>$msgs]);
    exit;
}
