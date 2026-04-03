<?php
// admin/moderate.php - Quick moderation actions
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();

if (!verifyCSRF($_GET['csrf'] ?? '')) {
    header('Location: ' . SITE_URL . '/?error=csrf');
    exit;
}

$action   = $_GET['action'] ?? '';
$id       = (int)($_GET['id'] ?? 0);
$threadId = (int)($_GET['thread'] ?? 0);

switch ($action) {
    case 'pin':
        $stmt = $db->prepare("SELECT id, is_pinned FROM threads WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if ($t) {
            $db->prepare("UPDATE threads SET is_pinned = ? WHERE id = ?")->execute([$t['is_pinned'] ? 0 : 1, $id]);
            logAudit($currentUser['id'], $t['is_pinned'] ? 'unpin_thread' : 'pin_thread', 'thread', $id);
        }
        header('Location: ' . SITE_URL . '/thread.php?id=' . $id);
        break;

    case 'lock':
        $stmt = $db->prepare("SELECT id, is_locked FROM threads WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if ($t) {
            $db->prepare("UPDATE threads SET is_locked = ? WHERE id = ?")->execute([$t['is_locked'] ? 0 : 1, $id]);
            logAudit($currentUser['id'], $t['is_locked'] ? 'unlock_thread' : 'lock_thread', 'thread', $id);
        }
        header('Location: ' . SITE_URL . '/thread.php?id=' . $id);
        break;

    case 'delete_thread':
        $stmt = $db->prepare("SELECT category_id FROM threads WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if ($t) {
            $db->prepare("UPDATE threads SET is_hidden = 1 WHERE id = ?")->execute([$id]);
            logAudit($currentUser['id'], 'hide_thread', 'thread', $id, 'Thread hidden by moderator');
        }
        header('Location: ' . SITE_URL . '/forum.php');
        break;

    case 'delete_post':
        $db->prepare("UPDATE posts SET is_hidden = 1 WHERE id = ?")->execute([$id]);
        $db->prepare("UPDATE threads SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?")->execute([$threadId]);
        logAudit($currentUser['id'], 'hide_post', 'post', $id);
        header('Location: ' . SITE_URL . '/thread.php?id=' . $threadId);
        break;

    default:
        header('Location: ' . SITE_URL . '/admin/');
        break;
}
exit;
