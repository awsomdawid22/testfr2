<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Allow banned users to access this page
startSecureSession();
$db = getDB();

// Get user even if banned
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display,
               r.color as role_color, r.badge_color, r.can_post, r.can_create_threads,
               r.can_moderate, r.can_admin, r.can_ban, r.priority as role_priority
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch() ?: null;
}

if (!$currentUser) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$pageTitle = 'Ban Appeal · LAE Forums';
$error = '';
$success = '';

// Check for existing pending appeal
$existing = $db->prepare("SELECT id, status FROM ban_appeals WHERE user_id = ? AND status IN ('pending','reviewing')");
$existing->execute([$currentUser['id']]);
$existing = $existing->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } elseif ($existing) {
        $error = 'You already have a pending ban appeal.';
    } else {
        $appealReason = trim($_POST['appeal_reason'] ?? '');
        $banReason    = trim($_POST['ban_reason_stated'] ?? '');

        if (strlen($appealReason) < 50) {
            $error = 'Please provide a more detailed reason for your appeal (minimum 50 characters).';
        } else {
            // Insert appeal record
            $db->prepare("INSERT INTO ban_appeals (user_id, ban_reason, appeal_reason) VALUES (?,?,?)")
               ->execute([$currentUser['id'], $banReason ?: $currentUser['ban_reason'], $appealReason]);
            $appealId = $db->lastInsertId();

            // Create linked thread in Ban Appeals category
            $appealCat = $db->query("SELECT id FROM categories WHERE slug = 'appeals' LIMIT 1")->fetch();
            if ($appealCat) {
                $threadTitle = 'Ban Appeal — ' . $currentUser['username'];
                $slug = 'ban-appeal-' . strtolower($currentUser['username']) . '-' . $appealId;
                $content  = "**Ban Reason (as stated by user):** " . ($banReason ?: ($currentUser['ban_reason'] ?? 'Not specified')) . "\n\n";
                $content .= "**Appeal Reason:**\n" . $appealReason;
                $db->prepare("INSERT INTO threads (title, slug, category_id, user_id, thread_type, appeal_id, is_locked) VALUES (?,?,?,?,?,?,1)")
                   ->execute([$threadTitle, $slug, $appealCat['id'], $currentUser['id'], 'appeal', $appealId]);
                $threadId = $db->lastInsertId();
                $db->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?,?,?)")
                   ->execute([$threadId, $currentUser['id'], $content]);
                $db->prepare("UPDATE categories SET thread_count = thread_count + 1 WHERE id = ?")
                   ->execute([$appealCat['id']]);
            }

            $success = 'Your ban appeal has been submitted. Staff will review it shortly.';
            $existing = ['status' => 'pending'];
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:700px">
    <div class="breadcrumb">
        <a href="<?= SITE_URL ?>/">Home</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php?cat=appeals">Ban Appeals</a>
        <i class="fas fa-chevron-right"></i>
        <span>Submit Appeal</span>
    </div>

    <?php
    $authTitle    = 'Ban Appeal';
    $authSubtitle = 'Submit a formal appeal to have your ban reviewed by the staff team.';
    ?>
    <div style="text-align:center;margin-bottom:32px">
        <?php include __DIR__ . '/includes/auth-logo.php'; ?>
    </div>

    <?php if (!empty($currentUser['is_banned'])): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <i class="fas fa-ban"></i>
        <div>
            <strong>You are currently banned.</strong>
            <?php if (!empty($currentUser['ban_reason'])): ?>
            <div style="margin-top:4px;font-size:0.9rem">Reason: <?= e($currentUser['ban_reason']) ?></div>
            <?php endif; ?>
            <?php if (!empty($currentUser['ban_expires'])): ?>
            <div style="font-size:0.85rem;margin-top:4px;color:var(--t2)">Expires: <?= date('F j, Y \a\t g:i A', strtotime($currentUser['ban_expires'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($existing && !$success): ?>
        <div class="alert alert-warning"><i class="fas fa-clock"></i> You have a pending appeal with status: <strong><?= ucfirst($existing['status']) ?></strong>. Please wait for staff to review it before submitting another.</div>
    <?php endif; ?>

    <?php if (!$existing || $success): ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-gavel"></i> Appeal Form</h2></div>
        <div style="padding:24px">

            <div class="alert alert-info" style="margin-bottom:20px">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Before you appeal:</strong> Make sure you have read the <a href="<?= SITE_URL ?>/rules.php" style="color:var(--red)">Community Rules</a> and understand why you were banned.
                    Appeals are reviewed by staff members not involved in the original ban. Be honest — dishonesty may result in a permanent ban.
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label">What is the stated reason for your ban?</label>
                    <div class="form-hint" style="margin-bottom:8px">Enter the ban reason as you understand it.</div>
                    <input type="text" name="ban_reason_stated" class="form-input"
                           placeholder="e.g. RDM, FailRP, Harassment..."
                           value="<?= e($_POST['ban_reason_stated'] ?? ($currentUser['ban_reason'] ?? '')) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Why should your ban be lifted?</label>
                    <div class="form-hint" style="margin-bottom:8px">Minimum 50 characters. Be honest, specific, and respectful. Explain what happened from your perspective and why you believe the ban was incorrect or should be reconsidered.</div>
                    <textarea name="appeal_reason" class="form-textarea" style="min-height:160px"
                              placeholder="Provide a detailed and honest explanation for your appeal..."
                              required><?= e($_POST['appeal_reason'] ?? '') ?></textarea>
                </div>

                <div class="alert alert-warning" style="margin-bottom:20px">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div>By submitting this appeal you confirm all information is truthful. Providing false information may result in additional disciplinary action. All appeal decisions are final.</div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <a href="<?= SITE_URL ?>/" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-accent btn-lg"><i class="fas fa-paper-plane"></i> Submit Appeal</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
