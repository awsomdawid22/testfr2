<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Messages · ' . SITE_NAME;
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send') {
        $recipientUsername = trim($_POST['recipient'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if (!$recipientUsername || !$content) {
            $error = 'Recipient and message content are required.';
        } else {
            // Find recipient
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND is_banned = 0");
            $stmt->execute([$recipientUsername]);
            $recipient = $stmt->fetch();
            
            if (!$recipient) {
                $error = 'User not found.';
            } elseif ($recipient['id'] == $currentUser['id']) {
                $error = 'You cannot message yourself.';
            } else {
                $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, subject, content) VALUES (?, ?, ?, ?)");
                $stmt->execute([$currentUser['id'], $recipient['id'], $subject ?: 'No Subject', $content]);
                $messageId = $db->lastInsertId();
                
                // Create in-app notification
                $db->prepare("INSERT INTO notifications (user_id, type, title, content, link) VALUES (?, 'message', 'New Message', ?, ?)")
                   ->execute([$recipient['id'], 'You have a new message from ' . $currentUser['username'], SITE_URL . '/messages.php']);
                
                // Send email notification if recipient has an email
                if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                    require_once __DIR__ . '/includes/mailer.php';
                    $recipientFull = $db->prepare("SELECT email, username FROM users WHERE id = ?");
                    $recipientFull->execute([$recipient['id']]);
                    $recipientFull = $recipientFull->fetch();
                    if ($recipientFull && $recipientFull['email']) {
                        sendNewMessageEmail(
                            $recipientFull['email'],
                            $recipientFull['username'],
                            $currentUser['username'],
                            $subject ?: 'No Subject',
                            $content
                        );
                    }
                }
                
                $message = 'Message sent successfully!';
            }
        }
    } elseif ($action === 'delete') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $db->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)")
           ->execute([$msgId, $currentUser['id'], $currentUser['id']]);
        $message = 'Message deleted.';
    } elseif ($action === 'mark_read') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?")
           ->execute([$msgId, $currentUser['id']]);
    }
}

// Get current view
$view = $_GET['view'] ?? 'inbox';
$msgId = (int)($_GET['msg'] ?? 0);

// Get conversations/messages
if ($view === 'inbox') {
    $messages = $db->prepare("
        SELECT m.*, u.username as sender_name, u.avatar as sender_avatar,
               r.color as sender_role_color, r.display_name as sender_role_display
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE m.receiver_id = ?
        ORDER BY m.created_at DESC
    ");
    $messages->execute([$currentUser['id']]);
    $messages = $messages->fetchAll();
} elseif ($view === 'sent') {
    $messages = $db->prepare("
        SELECT m.*, u.username as receiver_name, u.avatar as receiver_avatar,
               r.color as receiver_role_color
        FROM messages m
        JOIN users u ON m.receiver_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE m.sender_id = ?
        ORDER BY m.created_at DESC
    ");
    $messages->execute([$currentUser['id']]);
    $messages = $messages->fetchAll();
}

// View single message
$viewMessage = null;
if ($msgId) {
    $stmt = $db->prepare("
        SELECT m.*, 
               s.username as sender_name, s.avatar as sender_avatar, sr.color as sender_role_color, sr.display_name as sender_role_display, sr.badge_color as sender_badge_color,
               rec.username as receiver_name, rec.avatar as receiver_avatar
        FROM messages m
        JOIN users s ON m.sender_id = s.id
        JOIN users rec ON m.receiver_id = rec.id
        JOIN roles sr ON s.role_id = sr.id
        WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)
    ");
    $stmt->execute([$msgId, $currentUser['id'], $currentUser['id']]);
    $viewMessage = $stmt->fetch();
    
    // Mark as read if receiver
    if ($viewMessage && $viewMessage['receiver_id'] == $currentUser['id'] && !$viewMessage['is_read']) {
        $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ?")->execute([$msgId]);
    }
}

// Get unread count
$unreadMessages = getUnreadMessageCount($currentUser['id']);

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:1000px">
    <div class="card animate-fade-in">
        <div class="card-header">
            <h3><i class="fas fa-envelope"></i> Messages</h3>
            <button onclick="document.getElementById('composeModal').style.display='flex'" class="btn btn-accent btn-sm">
                <i class="fas fa-pen"></i> Compose
            </button>
        </div>
        
        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>
        
        <div style="display:flex;border-bottom:1px solid var(--b0)">
            <a href="?view=inbox" class="tab-link <?= $view === 'inbox' ? 'active' : '' ?>">
                <i class="fas fa-inbox"></i> Inbox
                <?php if ($unreadMessages > 0): ?>
                    <span class="msg-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
            <a href="?view=sent" class="tab-link <?= $view === 'sent' ? 'active' : '' ?>">
                <i class="fas fa-paper-plane"></i> Sent
            </a>
        </div>
        
        <?php if ($viewMessage): ?>
            <!-- Single Message View -->
            <div style="padding:20px">
                <a href="?view=<?= $view ?>" class="btn btn-ghost btn-sm" style="margin-bottom:16px">
                    <i class="fas fa-arrow-left"></i> Back to <?= ucfirst($view) ?>
                </a>
                
                <div class="message-view">
                    <div class="message-header">
                        <div style="display:flex;align-items:center;gap:12px">
                            <img src="<?= e(getAvatarUrl($viewMessage['sender_avatar'], $viewMessage['sender_name'])) ?>" 
                                 style="width:48px;height:48px;border-radius:50%;border:2px solid <?= e($viewMessage['sender_role_color']) ?>">
                            <div>
                                <div style="font-weight:700;font-size:1rem">
                                    <a href="<?= SITE_URL ?>/profile.php?user=<?= urlencode($viewMessage['sender_name']) ?>" 
                                       style="color:<?= e($viewMessage['sender_role_color']) ?>;text-decoration:none">
                                        <?= e($viewMessage['sender_name']) ?>
                                    </a>
                                    <?= getRoleBadge(['role_color' => $viewMessage['sender_role_color'], 'badge_color' => $viewMessage['sender_badge_color'], 'role_display' => $viewMessage['sender_role_display']]) ?>
                                </div>
                                <div style="font-size:0.82rem;color:var(--t1)">
                                    <?= formatDate($viewMessage['created_at']) ?>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <?php if ($viewMessage['sender_id'] != $currentUser['id']): ?>
                            <button onclick="replyTo('<?= e($viewMessage['sender_name']) ?>', '<?= e($viewMessage['subject']) ?>')" 
                                    class="btn btn-ghost btn-sm">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this message?')">
                                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="message_id" value="<?= $viewMessage['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <h2 style="font-family:var(--font-head);font-size:1.5rem;letter-spacing:1px;margin:16px 0">
                        <?= e($viewMessage['subject']) ?>
                    </h2>
                    <div class="message-content">
                        <?= nl2br(e($viewMessage['content'])) ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Message List -->
            <div class="message-list">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No messages in your <?= $view ?>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php 
                        $otherUser = $view === 'inbox' ? [
                            'name' => $msg['sender_name'],
                            'avatar' => $msg['sender_avatar'],
                            'color' => $msg['sender_role_color']
                        ] : [
                            'name' => $msg['receiver_name'],
                            'avatar' => $msg['receiver_avatar'],
                            'color' => $msg['receiver_role_color']
                        ];
                        $isUnread = $view === 'inbox' && !$msg['is_read'];
                        ?>
                        <a href="?view=<?= $view ?>&msg=<?= $msg['id'] ?>" 
                           class="message-row <?= $isUnread ? 'unread' : '' ?>">
                            <img src="<?= e(getAvatarUrl($otherUser['avatar'], $otherUser['name'])) ?>" 
                                 class="message-avatar" 
                                 style="border-color:<?= e($otherUser['color']) ?>">
                            <div class="message-info">
                                <div class="message-top">
                                    <span class="message-sender" style="color:<?= e($otherUser['color']) ?>">
                                        <?= e($otherUser['name']) ?>
                                    </span>
                                    <span class="message-date"><?= timeAgo($msg['created_at']) ?></span>
                                </div>
                                <div class="message-subject"><?= e($msg['subject']) ?></div>
                                <div class="message-preview"><?= e(substr($msg['content'], 0, 80)) ?>...</div>
                            </div>
                            <?php if ($isUnread): ?>
                                <span class="unread-dot"></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Compose Modal -->
<div id="composeModal" class="modal-overlay" style="display:none">
    <div class="modal-content animate-scale-in">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> New Message</h3>
            <button onclick="document.getElementById('composeModal').style.display='none'" class="modal-close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action" value="send">
            
            <div class="form-group">
                <label class="form-label">To</label>
                <input type="text" name="recipient" id="recipient" class="form-input" placeholder="Username" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" id="subject" class="form-input" placeholder="Subject (optional)">
            </div>
            
            <div class="form-group">
                <label class="form-label">Message</label>
                <textarea name="content" class="form-textarea" placeholder="Write your message..." required style="min-height:150px"></textarea>
            </div>
            
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" onclick="document.getElementById('composeModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-accent"><i class="fas fa-paper-plane"></i> Send</button>
            </div>
        </form>
    </div>
</div>

<style>
.tab-link {
    padding: 14px 20px;
    text-decoration: none;
    color: var(--t0);
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid transparent;
    transition: var(--transition);
}
.tab-link:hover { color: var(--t0); background: var(--bg3); }
.tab-link.active { color: var(--t0); border-bottom-color: var(--t0); }
.msg-badge {
    background: var(--t0);
    color: var(--bg0);
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 700;
}

.message-list { }
.message-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--b0);
    text-decoration: none;
    color: inherit;
    transition: var(--transition);
}
.message-row:hover { background: var(--bg3); }
.message-row.unread { background: rgba(255,255,255,0.02); }
.message-row.unread .message-subject { font-weight: 700; color: var(--t0); }

.message-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 2px solid var(--b0);
    flex-shrink: 0;
}

.message-info { flex: 1; min-width: 0; }
.message-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.message-sender { font-weight: 600; font-size: 0.9rem; }
.message-date { font-size: 0.78rem; color: var(--t1); }
.message-subject { font-size: 0.9rem; color: var(--t0); margin-bottom: 2px; }
.message-preview { font-size: 0.82rem; color: var(--t1); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.unread-dot {
    width: 10px;
    height: 10px;
    background: var(--t0);
    border-radius: 50%;
    flex-shrink: 0;
    animation: pulse 2s ease-in-out infinite;
}

.message-view { }
.message-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--b0);
}
.message-content {
    padding: 20px 0;
    line-height: 1.8;
    font-size: 0.95rem;
}

/* Modal */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 20px;
}
.modal-content {
    background: var(--bg2);
    border: 1px solid var(--b0);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--b0);
}
.modal-header h3 {
    font-family: var(--font-head);
    letter-spacing: 2px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-close {
    background: none;
    border: none;
    color: var(--t1);
    cursor: pointer;
    font-size: 1.2rem;
    padding: 5px;
    transition: var(--transition);
}
.modal-close:hover { color: var(--t0); }
.modal-content form { padding: 20px; }
</style>

<script>
function replyTo(username, subject) {
    document.getElementById('recipient').value = username;
    document.getElementById('subject').value = subject.startsWith('Re: ') ? subject : 'Re: ' + subject;
    document.getElementById('composeModal').style.display = 'flex';
}

// Close modal on outside click
document.getElementById('composeModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
