<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/moderation.php';

requireModerator();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Moderation · Admin';

$success = ''; $error = '';

// ── POST: create infraction ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $type       = $_POST['type'] ?? '';
        $playerName = trim($_POST['player_name'] ?? '');
        $identifier = trim($_POST['player_identifier'] ?? '');
        $discordId  = trim($_POST['player_discord_id'] ?? '');
        $reason     = trim($_POST['reason'] ?? '');
        $duration   = trim($_POST['duration'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        $validTypes = ['ban','kick','warn','note'];
        if (!in_array($type, $validTypes))       $error = 'Invalid infraction type.';
        elseif (strlen($playerName) < 2)          $error = 'Player name required.';
        elseif (strlen($reason) < 3)              $error = 'Reason required.';
        // Perm ban only for admins
        elseif ($type === 'ban' && (!$duration || strtolower($duration) === 'perm') && empty($currentUser['can_admin']))
            $error = 'Only admins can issue permanent bans.';
        else {
            $infr = createInfraction(
                $type, $playerName, $reason,
                $identifier ?: null,
                $discordId  ?: null,
                ($type === 'ban' ? ($duration ?: null) : null),
                $currentUser['id'], $currentUser['username'],
                'website', $notes ?: null
            );
            // Post to Discord mod-log
            $msgId = postModLogEmbed($infr);
            if ($msgId) setInfractionDiscordMsg($infr['id'], $msgId);
            logAudit($currentUser['id'], 'infraction_' . $type, 'infraction', $infr['id'],
                     "{$infr['infraction_id']} against {$playerName}");
            $success = "✓ " . infractionTypeLabel($type) . " issued — ID: <strong>{$infr['infraction_id']}</strong>";
        }
    }

    if ($action === 'remove') {
        $code   = strtoupper(trim($_POST['infraction_id'] ?? ''));
        $reason = trim($_POST['remove_reason'] ?? '');
        if (!$code) { $error = 'Infraction ID required.'; }
        else {
            $ok = removeInfraction($code, $currentUser['username'], $reason);
            if ($ok) {
                // Post removal to Discord too
                $infr = getInfractionByCode($code);
                if ($infr) {
                    $db->prepare("UPDATE infractions SET notes = CONCAT(IFNULL(notes,''), '\n[REMOVED by {$currentUser['username']}]') WHERE infraction_id = ?")
                       ->execute([$code]);
                    $fakeRemoval = array_merge($infr, [
                        'type'          => 'note',
                        'reason'        => "⬆ Removed infraction {$code}: " . ($reason ?: 'No reason given'),
                        'issued_by_name'=> $currentUser['username'],
                        'infraction_id' => 'RM-' . substr($code, 4),
                    ]);
                    postModLogEmbed($fakeRemoval);
                }
                $success = "✓ Infraction <strong>{$code}</strong> removed.";
                logAudit($currentUser['id'], 'remove_infraction', 'infraction', 0, $code);
            } else {
                $error = "Infraction {$code} not found or already removed.";
            }
        }
    }
}

// ── Search ──────────────────────────────────────────────────────────────────
$searchQuery  = trim($_GET['search'] ?? '');
$typeFilter   = $_GET['type'] ?? '';
$activeFilter = isset($_GET['active']) ? (bool)$_GET['active'] : null;
$searchResults = [];
if ($searchQuery) {
    $searchResults = getPlayerInfractions($searchQuery, $activeFilter === true);
}

// ── Recent infractions ──────────────────────────────────────────────────────
$recentStmt = $db->query("SELECT * FROM infractions ORDER BY created_at DESC LIMIT 30");
$recentInfr = $recentStmt->fetchAll();

// ── Stats ───────────────────────────────────────────────────────────────────
$stats = [];
foreach (['ban','kick','warn','note'] as $t) {
    $stats[$t] = (int)$db->query("SELECT COUNT(*) FROM infractions WHERE type='$t' AND is_active=1")->fetchColumn();
}
$stats['total'] = (int)$db->query("SELECT COUNT(*) FROM infractions")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">

        <div class="admin-header">
            <div>
                <h1><i class="fas fa-shield-halved" style="color:var(--red)"></i> Moderation Panel</h1>
                <p style="color:var(--t2);font-size:0.85rem;margin-top:4px">Issue infractions, search player history, manage cases</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <!-- Stats row -->
        <div class="stats-grid" style="margin-bottom:24px">
            <?php foreach ([
                ['ban',  'Bans Active',    'fa-ban',                         '#e63946'],
                ['kick', 'Kicks Total',    'fa-person-walking-arrow-right',  '#c9a227'],
                ['warn', 'Warnings Active','fa-triangle-exclamation',         '#f4a261'],
                ['note', 'Notes Active',   'fa-note-sticky',                  '#3d7ebf'],
                ['total','Total Cases',    'fa-folder-open',                  '#888'],
            ] as [$key,$label,$icon,$col]): ?>
            <div class="stat-card">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <span class="stat-label"><?= $label ?></span>
                    <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:1rem;opacity:0.7"></i>
                </div>
                <div class="stat-number" style="color:<?= $col ?>"><?= $stats[$key] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

            <!-- LEFT: Issue Infraction -->
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Issue Infraction</h3></div>
                <div style="padding:20px">
                    <form method="POST" id="infrForm">
                        <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="action" value="create">

                        <!-- Type selector -->
                        <div class="form-group">
                            <label class="form-label">Infraction Type</label>
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px">
                                <?php foreach (['ban','kick','warn','note'] as $t): ?>
                                <label class="infr-type-btn" data-type="<?= $t ?>" style="border-color:<?= infractionTypeColor($t) ?>22">
                                    <input type="radio" name="type" value="<?= $t ?>" style="display:none" <?= $t==='warn'?'checked':'' ?> required>
                                    <i class="fas <?= infractionTypeIcon($t) ?>" style="color:<?= infractionTypeColor($t) ?>"></i>
                                    <span><?= ucfirst($t) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label">Player Name <span style="color:var(--red)">*</span></label>
                                <input type="text" name="player_name" class="form-input" placeholder="In-game name" required>
                            </div>
                            <div class="form-group" id="durationGroup">
                                <label class="form-label">Duration <span style="color:var(--t2)">(bans only)</span></label>
                                <input type="text" name="duration" class="form-input" placeholder="e.g. 7d, 24h, perm">
                                <div class="form-hint">d=days h=hours w=weeks. Leave blank = perm</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Reason <span style="color:var(--red)">*</span></label>
                            <input type="text" name="reason" class="form-input" placeholder="Rule violation / reason for action" required>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label">Steam Hex / Identifier</label>
                                <input type="text" name="player_identifier" class="form-input" placeholder="steam:110000...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Discord ID</label>
                                <input type="text" name="player_discord_id" class="form-input" placeholder="1234567890">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Internal Staff Note <span style="color:var(--t2)">(optional)</span></label>
                            <textarea name="notes" class="form-textarea" style="min-height:60px" placeholder="Context for other staff, not shown publicly..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-accent" style="width:100%;justify-content:center">
                            <i class="fas fa-paper-plane"></i> Issue Infraction
                        </button>
                    </form>
                </div>
            </div>

            <!-- RIGHT: Player Lookup + Remove -->
            <div style="display:flex;flex-direction:column;gap:16px">

                <!-- Search -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-magnifying-glass"></i> Player Lookup</h3></div>
                    <div style="padding:16px">
                        <form method="GET">
                            <div style="display:flex;gap:8px">
                                <input type="text" name="search" class="form-input"
                                       value="<?= e($searchQuery) ?>"
                                       placeholder="Name, Steam hex, or Discord ID">
                                <button type="submit" class="btn btn-accent" style="flex-shrink:0">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>

                        <?php if ($searchQuery): ?>
                        <div style="margin-top:14px">
                            <?php if (empty($searchResults)): ?>
                                <div style="color:var(--t2);font-size:0.85rem;text-align:center;padding:16px">
                                    <i class="fas fa-circle-check" style="color:var(--green);display:block;font-size:1.5rem;margin-bottom:6px"></i>
                                    No infractions found for <strong><?= e($searchQuery) ?></strong>
                                </div>
                            <?php else: ?>
                                <?php
                                $active  = array_filter($searchResults, fn($r) => $r['is_active']);
                                $removed = array_filter($searchResults, fn($r) => !$r['is_active']);
                                ?>
                                <div style="font-size:0.78rem;color:var(--t2);margin-bottom:10px">
                                    Found <strong style="color:var(--t0)"><?= count($searchResults) ?></strong> infraction(s) —
                                    <span style="color:var(--red)"><?= count($active) ?> active</span>
                                </div>
                                <?php foreach ($searchResults as $r): ?>
                                <div class="infr-row <?= $r['is_active'] ? 'infr-active' : 'infr-removed' ?>"
                                     style="border-color:<?= $r['is_active'] ? infractionTypeColor($r['type']) . '44' : 'var(--b0)' ?>">
                                    <div class="infr-row-header">
                                        <span class="infr-badge" style="background:<?= infractionTypeColor($r['type']) ?>18;color:<?= infractionTypeColor($r['type']) ?>">
                                            <i class="fas <?= infractionTypeIcon($r['type']) ?>"></i>
                                            <?= infractionTypeLabel($r['type']) ?>
                                        </span>
                                        <code class="infr-id"><?= e($r['infraction_id']) ?></code>
                                        <?php if (!$r['is_active']): ?>
                                            <span style="font-size:0.68rem;color:var(--t2);background:var(--bg3);padding:1px 6px;border-radius:2px">REMOVED</span>
                                        <?php endif; ?>
                                        <span style="font-size:0.72rem;color:var(--t2);margin-left:auto"><?= timeAgo($r['created_at']) ?></span>
                                    </div>
                                    <div style="font-size:0.84rem;color:var(--t1);margin:5px 0"><?= e($r['reason']) ?></div>
                                    <div style="font-size:0.73rem;color:var(--t2)">
                                        By <strong><?= e($r['issued_by_name']) ?></strong>
                                        via <?= e($r['issued_via']) ?>
                                        <?php if ($r['type']==='ban' && $r['is_active']): ?>
                                        · <?= $r['expires_at'] ? 'Expires ' . date('M j Y', strtotime($r['expires_at'])) : 'Permanent' ?>
                                        <?php endif; ?>
                                        <?php if (!$r['is_active'] && $r['removed_by']): ?>
                                        · Removed by <?= e($r['removed_by']) ?> <?= timeAgo($r['removed_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($r['notes']): ?>
                                    <div style="font-size:0.75rem;color:var(--t2);font-style:italic;margin-top:4px;padding-top:4px;border-top:1px solid var(--b0)">📝 <?= e($r['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Remove Infraction -->
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-trash-undo"></i> Remove Infraction</h3></div>
                    <div style="padding:16px">
                        <form method="POST">
                            <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">
                            <input type="hidden" name="action" value="remove">
                            <div class="form-group">
                                <label class="form-label">Infraction ID</label>
                                <input type="text" name="infraction_id" class="form-input" placeholder="e.g. LAE-0042" required style="text-transform:uppercase">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reason for Removal</label>
                                <input type="text" name="remove_reason" class="form-input" placeholder="Appeal accepted, issued in error, etc.">
                            </div>
                            <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center">
                                <i class="fas fa-xmark"></i> Remove Infraction
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Infractions Table -->
        <div class="card" style="margin-top:24px">
            <div class="card-header">
                <h3><i class="fas fa-clock-rotate-left"></i> Recent Infractions</h3>
                <a href="?export=csv" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Export CSV</a>
            </div>
            <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Player</th>
                        <th>Reason</th>
                        <th>Issued By</th>
                        <th>Via</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInfr as $r): ?>
                    <tr style="<?= !$r['is_active'] ? 'opacity:0.45' : '' ?>">
                        <td><code style="font-family:var(--font-mono);font-size:0.78rem"><?= e($r['infraction_id']) ?></code></td>
                        <td>
                            <span class="infr-badge" style="background:<?= infractionTypeColor($r['type']) ?>18;color:<?= infractionTypeColor($r['type']) ?>">
                                <i class="fas <?= infractionTypeIcon($r['type']) ?>"></i>
                                <?= infractionTypeLabel($r['type']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="?search=<?= urlencode($r['player_name']) ?>" style="color:var(--t0);text-decoration:none;font-weight:600"><?= e($r['player_name']) ?></a>
                            <?php if ($r['player_identifier']): ?>
                                <div style="font-size:0.7rem;color:var(--t2);font-family:var(--font-mono)"><?= e(substr($r['player_identifier'],0,20)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:240px;word-break:break-word;font-size:0.84rem"><?= e($r['reason']) ?></td>
                        <td style="font-size:0.84rem"><?= e($r['issued_by_name']) ?></td>
                        <td>
                            <span style="font-size:0.72rem;color:var(--t2)">
                                <i class="fas <?= $r['issued_via']==='discord' ? 'fa-discord fab' : 'fa-globe' ?>"></i>
                                <?= ucfirst($r['issued_via']) ?>
                            </span>
                        </td>
                        <td style="font-size:0.78rem;color:var(--t2);white-space:nowrap">
                            <?= date('M j, Y', strtotime($r['created_at'])) ?>
                        </td>
                        <td>
                            <?php if ($r['is_active']): ?>
                                <span style="color:var(--green);font-size:0.72rem;font-weight:700">● ACTIVE</span>
                            <?php else: ?>
                                <span style="color:var(--t2);font-size:0.72rem">✕ REMOVED</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?search=<?= urlencode($r['player_name']) ?>" class="btn btn-ghost btn-sm">
                                <i class="fas fa-search"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<style>
.infr-type-btn{
    display:flex;flex-direction:column;align-items:center;gap:5px;
    padding:10px 6px;border:2px solid var(--b0);border-radius:var(--radius);
    cursor:pointer;transition:all .15s;font-size:0.78rem;font-weight:700;
    text-transform:uppercase;letter-spacing:.8px;
    background:var(--bg3);
}
.infr-type-btn:has(input:checked){background:var(--bg4);border-width:2px}
.infr-type-btn i{font-size:1.1rem}
.infr-row{
    padding:10px 12px;border:1px solid var(--b0);border-radius:var(--radius);
    margin-bottom:8px;background:var(--bg3);
}
.infr-row-header{display:flex;align-items:center;gap:7px;margin-bottom:4px;flex-wrap:wrap}
.infr-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:2px 8px;border-radius:3px;font-size:0.7rem;font-weight:700;
    text-transform:uppercase;letter-spacing:.8px;
}
.infr-id{
    font-family:var(--font-mono);font-size:0.75rem;
    background:var(--bg5);padding:1px 7px;border-radius:3px;color:var(--t1);
}
.infr-removed{opacity:.55}
#durationGroup{transition:opacity .2s}
</style>

<script>
// Show/hide duration field based on type
document.querySelectorAll('input[name="type"]').forEach(r => {
    r.addEventListener('change', () => {
        const show = r.value === 'ban';
        document.getElementById('durationGroup').style.opacity = show ? '1' : '0.35';
        document.querySelector('[name="duration"]').disabled = !show;
    });
    // Visual active state
    r.addEventListener('change', () => {
        document.querySelectorAll('.infr-type-btn').forEach(b => {
            const checked = b.querySelector('input').checked;
            const color = b.dataset.type;
            b.style.background = checked ? 'var(--bg4)' : 'var(--bg3)';
        });
    });
});
// Uppercase infraction ID field
const idField = document.querySelector('[name="infraction_id"]');
if (idField) idField.addEventListener('input', () => { idField.value = idField.value.toUpperCase(); });

// Init state
document.querySelector('[name="duration"]').disabled = true;
document.getElementById('durationGroup').style.opacity = '0.35';
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
