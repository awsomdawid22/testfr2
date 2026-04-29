<?php
/**
 * Content Filters Management
 * Manage banned words, domains, and patterns
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
$db = getDB();

$pageTitle = 'Content Filters · Admin';
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_filter') {
        $type = $_POST['type'] ?? 'word';
        $value = strtolower(trim($_POST['value'] ?? ''));
        $filterAction = $_POST['filter_action'] ?? 'block';
        $reason = trim($_POST['reason'] ?? '');
        
        if ($value) {
            try {
                $stmt = $db->prepare("INSERT INTO content_filters (type, value, action, reason, added_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$type, $value, $filterAction, $reason ?: null, $currentUser['id']]);
                logAudit($currentUser['id'], 'add_content_filter', 'filter', $db->lastInsertId(), "Added $type filter: $value");
                $message = 'Filter added successfully.';
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = 'This filter already exists.';
                } else {
                    $error = 'Failed to add filter.';
                }
            }
        }
    } elseif ($action === 'delete_filter') {
        $filterId = (int)($_POST['filter_id'] ?? 0);
        $db->prepare("DELETE FROM content_filters WHERE id = ?")->execute([$filterId]);
        logAudit($currentUser['id'], 'delete_content_filter', 'filter', $filterId);
        $message = 'Filter deleted.';
    } elseif ($action === 'toggle_filter') {
        $filterId = (int)($_POST['filter_id'] ?? 0);
        $db->prepare("UPDATE content_filters SET is_active = NOT is_active WHERE id = ?")->execute([$filterId]);
        $message = 'Filter toggled.';
    } elseif ($action === 'bulk_add') {
        $type = $_POST['bulk_type'] ?? 'word';
        $values = array_filter(array_map('trim', explode("\n", $_POST['bulk_values'] ?? '')));
        $filterAction = $_POST['bulk_action'] ?? 'block';
        $added = 0;
        
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if ($value) {
                try {
                    $db->prepare("INSERT IGNORE INTO content_filters (type, value, action, added_by) VALUES (?, ?, ?, ?)")
                       ->execute([$type, $value, $filterAction, $currentUser['id']]);
                    $added++;
                } catch (\Exception $e) {}
            }
        }
        
        logAudit($currentUser['id'], 'bulk_add_filters', 'filter', null, "Added $added $type filters");
        $message = "Added $added filters.";
    }
}

// Get filters with pagination
$filterType = $_GET['type'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = '1=1';
$params = [];
if ($filterType !== 'all') {
    $where .= " AND type = ?";
    $params[] = $filterType;
}
if ($search) {
    $where .= " AND value LIKE ?";
    $params[] = "%$search%";
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM content_filters WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, 25, $page);

$stmt = $db->prepare("
    SELECT cf.*, u.username as added_by_name 
    FROM content_filters cf 
    LEFT JOIN users u ON cf.added_by = u.id 
    WHERE $where 
    ORDER BY cf.match_count DESC, cf.created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$filters = $stmt->fetchAll();

// Get counts by type
$typeCounts = $db->query("SELECT type, COUNT(*) as count FROM content_filters GROUP BY type")->fetchAll(\PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-header">
            <h1><i class="fas fa-filter" style="color:var(--red)"></i> Content Filters</h1>
        </div>

        <?php if ($message): ?><div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <!-- Type Tabs -->
        <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
            <a href="?type=all" class="btn <?= $filterType === 'all' ? 'btn-accent' : 'btn-ghost' ?> btn-sm">
                All (<?= array_sum($typeCounts) ?>)
            </a>
            <a href="?type=word" class="btn <?= $filterType === 'word' ? 'btn-accent' : 'btn-ghost' ?> btn-sm">
                <i class="fas fa-font"></i> Words (<?= $typeCounts['word'] ?? 0 ?>)
            </a>
            <a href="?type=domain" class="btn <?= $filterType === 'domain' ? 'btn-accent' : 'btn-ghost' ?> btn-sm">
                <i class="fas fa-globe"></i> Domains (<?= $typeCounts['domain'] ?? 0 ?>)
            </a>
            <a href="?type=pattern" class="btn <?= $filterType === 'pattern' ? 'btn-accent' : 'btn-ghost' ?> btn-sm">
                <i class="fas fa-code"></i> Patterns (<?= $typeCounts['pattern'] ?? 0 ?>)
            </a>
        </div>

        <div style="display:grid;grid-template-columns:350px 1fr;gap:20px">
            <!-- Add Filter Form -->
            <div style="display:flex;flex-direction:column;gap:20px">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-plus"></i> Add Filter</h3>
                    </div>
                    <form method="POST" style="padding:16px">
                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="action" value="add_filter">
                        
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="word">Banned Word</option>
                                <option value="domain">Blocked Domain</option>
                                <option value="pattern">Regex Pattern</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Value</label>
                            <input type="text" name="value" class="form-input" placeholder="Enter word, domain, or pattern..." required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Action</label>
                            <select name="filter_action" class="form-select">
                                <option value="block">Block (prevent posting)</option>
                                <option value="flag">Flag for review</option>
                                <option value="warn">Warn user</option>
                                <option value="shadow">Shadow hide</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Reason (optional)</label>
                            <input type="text" name="reason" class="form-input" placeholder="Why is this blocked?">
                        </div>
                        
                        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center">
                            <i class="fas fa-plus"></i> Add Filter
                        </button>
                    </form>
                </div>

                <!-- Bulk Add -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Bulk Add</h3>
                    </div>
                    <form method="POST" style="padding:16px">
                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="action" value="bulk_add">
                        
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="bulk_type" class="form-select">
                                <option value="word">Banned Words</option>
                                <option value="domain">Blocked Domains</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Values (one per line)</label>
                            <textarea name="bulk_values" class="form-input" rows="6" placeholder="word1&#10;word2&#10;word3"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Action</label>
                            <select name="bulk_action" class="form-select">
                                <option value="block">Block</option>
                                <option value="flag">Flag</option>
                                <option value="warn">Warn</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-ghost" style="width:100%;justify-content:center">
                            <i class="fas fa-upload"></i> Bulk Add
                        </button>
                    </form>
                </div>
            </div>

            <!-- Filters List -->
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <h3><i class="fas fa-list"></i> Filters (<?= $total ?>)</h3>
                    <form method="GET" style="display:flex;gap:8px">
                        <input type="hidden" name="type" value="<?= e($filterType) ?>">
                        <input type="text" name="search" class="form-input" placeholder="Search..." value="<?= e($search) ?>" style="width:180px;padding:6px 10px">
                        <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
                    </form>
                </div>
                <div style="padding:16px">
                    <?php if ($filters): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Value</th>
                                <th>Action</th>
                                <th>Matches</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filters as $filter): ?>
                            <tr style="<?= !$filter['is_active'] ? 'opacity:0.5' : '' ?>">
                                <td>
                                    <span class="badge"><?= e($filter['type']) ?></span>
                                </td>
                                <td>
                                    <code style="font-size:0.85rem;word-break:break-all"><?= e($filter['value']) ?></code>
                                    <?php if ($filter['reason']): ?>
                                    <div style="font-size:0.72rem;color:var(--t2)"><?= e($filter['reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:0.8rem;padding:2px 8px;border-radius:4px;background:<?= 
                                        $filter['action'] === 'block' ? 'rgba(231,76,60,0.2)' : 
                                        ($filter['action'] === 'warn' ? 'rgba(243,156,18,0.2)' : 
                                        ($filter['action'] === 'flag' ? 'rgba(155,89,182,0.2)' : 'rgba(127,140,141,0.2)')) ?>;color:<?= 
                                        $filter['action'] === 'block' ? 'var(--red)' : 
                                        ($filter['action'] === 'warn' ? '#f39c12' : 
                                        ($filter['action'] === 'flag' ? '#9b59b6' : 'var(--t2)')) ?>">
                                        <?= e($filter['action']) ?>
                                    </span>
                                </td>
                                <td style="font-weight:700;color:<?= $filter['match_count'] > 0 ? 'var(--red)' : 'var(--t2)' ?>">
                                    <?= number_format($filter['match_count']) ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                        <input type="hidden" name="action" value="toggle_filter">
                                        <input type="hidden" name="filter_id" value="<?= $filter['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px">
                                            <i class="fas fa-<?= $filter['is_active'] ? 'toggle-on text-green' : 'toggle-off' ?>" 
                                               style="color:<?= $filter['is_active'] ? 'var(--green)' : 'var(--t2)' ?>"></i>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                                        <input type="hidden" name="action" value="delete_filter">
                                        <input type="hidden" name="filter_id" value="<?= $filter['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this filter?">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?= renderPagination($pag, SITE_URL . '/admin/content-filters.php?type=' . urlencode($filterType) . '&search=' . urlencode($search)) ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px;color:var(--t2)">
                        <i class="fas fa-filter" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.5"></i>
                        No filters found
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
