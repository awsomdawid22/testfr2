<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Ban Appeals · Admin';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $appealId = (int)$_POST['appeal_id'];
    $status   = $_POST['status'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');

    if (in_array($status, ['reviewing','accepted','denied'])) {
        $db->prepare("UPDATE ban_appeals SET status=?, reviewed_by=?, review_notes=?, reviewed_at=NOW() WHERE id=?")
           ->execute([$status, $currentUser['id'], $notes, $appealId]);

        if ($status === 'accepted') {
            $appeal = $db->prepare("SELECT user_id FROM ban_appeals WHERE id=?");
            $appeal->execute([$appealId]);
            $a = $appeal->fetch();
            if ($a) $db->prepare("UPDATE users SET is_banned=0, ban_reason=NULL WHERE id=?")->execute([$a['user_id']]);
        }
        logAudit($currentUser['id'], 'review_appeal', 'ban_appeal', $appealId, "Status: $status");

        // Send email notification to the user who appealed
        $userData = $db->prepare("SELECT u.email, u.username FROM ban_appeals ba JOIN users u ON ba.user_id = u.id WHERE ba.id = ?");
        $userData->execute([$appealId]);
        $ud = $userData->fetch();
        if ($ud) {
            sendAppealStatusEmail($ud['email'], $ud['username'], $status, $notes);
        }

        $message = 'Appeal updated.';
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
$appeals = $db->prepare("
    SELECT ba.*, u.username, u.avatar, u.ban_reason
    FROM ban_appeals ba JOIN users u ON ba.user_id = u.id
    WHERE ba.status = ?
    ORDER BY ba.created_at DESC
");
$appeals->execute([$statusFilter]);
$appeals = $appeals->fetchAll();

$view = null;
if (isset($_GET['view'])) {
    $stmt = $db->prepare("SELECT ba.*, u.username, u.email, u.ban_reason FROM ban_appeals ba JOIN users u ON ba.user_id = u.id WHERE ba.id = ?");
    $stmt->execute([(int)$_GET['view']]);
    $view = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-gavel" style="color:var(--red)"></i> Ban Appeals</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><?= e($message) ?></div><?php endif; ?>

        <?php if ($view): ?>
        <div class="card" style="margin-bottom:20px;border-color:var(--red)">
            <div class="card-header">
                <h3><i class="fas fa-user"></i> Appeal from <?= e($view['username']) ?></h3>
                <a href="<?= SITE_URL ?>/admin/appeals.php" class="btn btn-ghost btn-sm">← Back</a>
            </div>
            <div style="padding:20px;display:grid;gap:16px">
                <div class="card" style="padding:14px">
                    <div class="form-label">Original Ban Reason</div>
                    <div style="color:var(--red);margin-top:6px"><?= e($view['ban_reason'] ?? 'No reason recorded') ?></div>
                </div>
                <div class="card" style="padding:14px">
                    <div class="form-label">Appeal Reason</div>
                    <div style="margin-top:6px;color:var(--t1)"><?= nl2br(e($view['appeal_reason'])) ?></div>
                </div>
                <form method="POST" style="background:var(--bg1);padding:16px;border-radius:var(--r);border:1px solid var(--b0)">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="appeal_id" value="<?= $view['id'] ?>">
                    <div class="form-label" style="margin-bottom:10px">Decision</div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <select name="status" class="form-select" style="width:auto">
                            <option value="reviewing">Under Review</option>
                            <option value="accepted">Accept (Unban)</option>
                            <option value="denied">Deny</option>
                        </select>
                        <input type="text" name="notes" class="form-input" placeholder="Notes..." style="flex:1">
                        <button type="submit" class="btn btn-accent"><i class="fas fa-gavel"></i> Submit</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-bottom:16px">
            <?php foreach (['pending','reviewing','accepted','denied'] as $s): ?>
            <a href="?status=<?= $s ?>" class="btn <?= $statusFilter === $s ? 'btn-accent' : 'btn-ghost' ?> btn-sm"><?= ucfirst($s) ?></a>
            <?php endforeach; ?>
        </div>

        <table class="admin-table">
            <thead><tr><th>User</th><th>Ban Reason</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($appeals as $appeal): ?>
                <tr>
                    <td><?= e($appeal['username']) ?></td>
                    <td style="color:var(--t2);font-size:0.82rem"><?= e(substr($appeal['ban_reason'] ?? 'N/A', 0, 60)) ?></td>
                    <td style="font-size:0.8rem;color:var(--t2)"><?= timeAgo($appeal['created_at']) ?></td>
                    <td>
                        <?php
                        $colors = ['pending'=>'var(--gold)','reviewing'=>'var(--blue)','accepted'=>'var(--green)','denied'=>'var(--red)'];
                        echo "<span style=\"color:{$colors[$appeal['status']]};font-weight:700;font-size:0.82rem\">" . ucfirst($appeal['status']) . "</span>";
                        ?>
                    </td>
                    <td><a href="?status=<?= $statusFilter ?>&view=<?= $appeal['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($appeals)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--t2);padding:30px">No <?= $statusFilter ?> appeals</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
