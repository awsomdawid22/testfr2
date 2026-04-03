<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Audit Log · Admin';

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (al.action LIKE ? OR u.username LIKE ? OR al.details LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON al.user_id = u.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, 30, $page);

$stmt = $db->prepare("
    SELECT al.*, u.username 
    FROM audit_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE $where
    ORDER BY al.created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$logs = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-clipboard-list" style="color:var(--red)"></i> Audit Log</h1>
        </div>

        <form method="GET" style="display:flex;gap:10px;margin-bottom:16px">
            <input type="text" name="search" class="form-input" placeholder="Search actions, users, details..." value="<?= e($search) ?>" style="flex:1">
            <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Search</button>
        </form>

        <div class="card">
            <?php foreach ($logs as $log): ?>
            <div style="padding:10px 16px;border-bottom:1px solid var(--b0);display:grid;grid-template-columns:140px 100px 1fr 120px;gap:12px;align-items:center;font-size:0.82rem">
                <span style="font-family:var(--font-mono);font-size:0.72rem;color:var(--t1)"><?= formatDate($log['created_at']) ?></span>
                <span style="color:var(--red);font-weight:700"><?= e($log['username'] ?? 'System') ?></span>
                <div>
                    <span style="color:var(--t0)"><?= e($log['action']) ?></span>
                    <?php if ($log['details']): ?>
                        <span style="color:var(--t1)"> — <?= e(substr($log['details'], 0, 100)) ?></span>
                    <?php endif; ?>
                    <?php if ($log['target_type'] && $log['target_id']): ?>
                        <span style="color:var(--blue);font-size:0.72rem;margin-left:6px">[<?= e($log['target_type']) ?> #<?= $log['target_id'] ?>]</span>
                    <?php endif; ?>
                </div>
                <span style="font-family:var(--font-mono);font-size:0.72rem;color:var(--t1)"><?= e($log['ip_address'] ?? '') ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <div class="empty-state"><i class="fas fa-clipboard"></i><p>No log entries found.</p></div>
            <?php endif; ?>
        </div>

        <?= renderPagination($pag, SITE_URL . '/admin/audit.php?search=' . urlencode($search)) ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
