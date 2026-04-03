<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Staff Applications · Admin';
$message = '';

// Handle review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $appId = (int)$_POST['app_id'];
    $status = $_POST['status'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    if (in_array($status, ['reviewing', 'accepted', 'denied'])) {
        $db->prepare("UPDATE applications SET status = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$status, $currentUser['id'], $notes, $appId]);
        logAudit($currentUser['id'], 'review_application', 'application', $appId, "Status: $status");

        // Send email notification to applicant
        $appData = $db->prepare("SELECT a.position, u.email, u.username FROM applications a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $appData->execute([$appId]);
        $appData = $appData->fetch();
        if ($appData) {
            sendApplicationStatusEmail($appData['email'], $appData['username'], $appData['position'], $status, $notes);
        }

        $message = 'Application updated.';
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
$apps = $db->prepare("
    SELECT a.*, u.username, u.avatar,
           th.id as thread_id
    FROM applications a JOIN users u ON a.user_id = u.id
    LEFT JOIN threads th ON th.application_id = a.id
    WHERE a.status = ?
    ORDER BY a.created_at DESC
");
$apps->execute([$statusFilter]);
$apps = $apps->fetchAll();

$view = null;
if (isset($_GET['view'])) {
    $stmt = $db->prepare("SELECT a.*, u.username, u.email, u.avatar, th.id as thread_id FROM applications a JOIN users u ON a.user_id = u.id LEFT JOIN threads th ON th.application_id = a.id WHERE a.id = ?");
    $stmt->execute([(int)$_GET['view']]);
    $view = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-file-alt" style="color:var(--red)"></i> Staff Applications</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><?= e($message) ?></div><?php endif; ?>

        <?php if ($view): ?>
        <!-- Application detail -->
        <div class="card" style="margin-bottom:20px;border-color:var(--red)">
            <div class="card-header">
                <h3><i class="fas fa-user"></i> Application from <?= e($view['username']) ?> — <?= e($view['position']) ?></h3>
                <div style="display:flex;gap:8px">
                    <?php if ($view['thread_id']): ?>
                    <a href="<?= SITE_URL ?>/thread.php?id=<?= $view['thread_id'] ?>" class="btn btn-ghost btn-sm" target="_blank"><i class="fas fa-external-link-alt"></i> View Thread</a>
                    <?php endif; ?>
                    <a href="<?= SITE_URL ?>/admin/applications.php" class="btn btn-ghost btn-sm">← Back</a>
                </div>
            </div>
            <div style="padding:20px;display:grid;gap:16px">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                    <div class="card" style="padding:12px"><div class="form-label">Age</div><div><?= e($view['age']) ?></div></div>
                    <div class="card" style="padding:12px"><div class="form-label">Timezone</div><div><?= e($view['timezone']) ?></div></div>
                    <div class="card" style="padding:12px"><div class="form-label">Hours/Week</div><div><?= e($view['hours_available']) ?></div></div>
                </div>

                <div class="card" style="padding:14px"><div class="form-label">Previous Experience</div><div style="margin-top:8px;color:var(--t1)"><?= nl2br(e($view['previous_experience'])) ?></div></div>
                <div class="card" style="padding:14px"><div class="form-label">Why Apply?</div><div style="margin-top:8px;color:var(--t1)"><?= nl2br(e($view['why_apply'])) ?></div></div>
                <div class="card" style="padding:14px"><div class="form-label">Scenario Answer</div><div style="margin-top:8px;color:var(--t1)"><?= nl2br(e($view['scenario_answer'])) ?></div></div>
                <?php if ($view['additional_info']): ?>
                <div class="card" style="padding:14px"><div class="form-label">Additional Info</div><div style="margin-top:8px;color:var(--t1)"><?= nl2br(e($view['additional_info'])) ?></div></div>
                <?php endif; ?>

                <!-- Review form -->
                <form method="POST" style="background:var(--bg1);padding:16px;border-radius:var(--r);border:1px solid var(--b0)">
                    <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="app_id" value="<?= $view['id'] ?>">
                    <div class="form-label" style="margin-bottom:10px">Review Decision</div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <select name="status" class="form-select" style="width:auto">
                            <option value="reviewing">Under Review</option>
                            <option value="accepted">Accept</option>
                            <option value="denied">Deny</option>
                        </select>
                        <input type="text" name="notes" class="form-input" placeholder="Review notes (optional)" style="flex:1">
                        <button type="submit" class="btn btn-accent"><i class="fas fa-gavel"></i> Submit Decision</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter tabs -->
        <div style="display:flex;gap:8px;margin-bottom:16px">
            <?php foreach (['pending','reviewing','accepted','denied'] as $s): ?>
            <a href="?status=<?= $s ?>" class="btn <?= $statusFilter === $s ? 'btn-accent' : 'btn-ghost' ?> btn-sm"><?= ucfirst($s) ?></a>
            <?php endforeach; ?>
        </div>

        <table class="admin-table">
            <thead><tr><th>Applicant</th><th>Position</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($apps as $app): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <img src="<?= e(getAvatarUrl($app['avatar'], $app['username'])) ?>" style="width:28px;height:28px;border-radius:50%">
                            <?= e($app['username']) ?>
                        </div>
                    </td>
                    <td><?= e($app['position']) ?></td>
                    <td style="color:var(--t2);font-size:0.8rem"><?= timeAgo($app['created_at']) ?></td>
                    <td>
                        <?php
                        $colors = ['pending'=>'var(--gold)','reviewing'=>'var(--blue)','accepted'=>'var(--green)','denied'=>'var(--red)'];
                        echo "<span style=\"color:{$colors[$app['status']]};font-weight:700;font-size:0.82rem\">" . ucfirst($app['status']) . "</span>";
                        ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <a href="?status=<?= $statusFilter ?>&view=<?= $app['id'] ?>" class="btn btn-ghost btn-sm">View</a>
                            <?php if ($app['thread_id']): ?>
                            <a href="<?= SITE_URL ?>/thread.php?id=<?= $app['thread_id'] ?>" class="btn btn-ghost btn-sm" target="_blank"><i class="fas fa-external-link-alt"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($apps)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--t2);padding:30px">No <?= $statusFilter ?> applications</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
