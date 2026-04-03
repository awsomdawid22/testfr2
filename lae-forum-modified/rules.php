<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Server Rules · LAE Forums';
$db = getDB();
$rules = $db->query("SELECT * FROM rules ORDER BY sort_order ASC")->fetchAll();
$rulesByCategory = [];
foreach ($rules as $rule) {
    $rulesByCategory[$rule['category']][] = $rule;
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:900px">
    <div class="breadcrumb"><a href="<?= SITE_URL ?>/">Home</a> <i class="fas fa-chevron-right"></i> <span>Server Rules</span></div>

    <div style="text-align:center;margin-bottom:40px">
        <h1 style="font-family:var(--font-head);font-size:clamp(2.5rem,6vw,5rem);letter-spacing:4px">SERVER <span style="color:var(--red)">RULES</span></h1>
        <p style="color:var(--t2);max-width:600px;margin:0 auto">All players must follow these rules. Violations will result in warnings, kicks, or bans. Ignorance is not an excuse.</p>
    </div>

    <?php foreach ($rulesByCategory as $cat => $catRules): ?>
    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h2><i class="fas fa-list-check"></i> <?= e($cat) ?></h2>
        </div>
        <?php foreach ($catRules as $rule): ?>
        <div style="padding:18px 20px;border-bottom:1px solid var(--b0);display:flex;gap:16px">
            <div style="font-family:var(--font-head);font-size:1.5rem;color:var(--red);letter-spacing:1px;min-width:50px;line-height:1"><?= e($rule['rule_number']) ?></div>
            <div>
                <div style="font-weight:700;font-size:1rem;margin-bottom:4px"><?= e($rule['title']) ?></div>
                <div style="color:var(--t1);font-size:0.9rem;line-height:1.7"><?= nl2br(e($rule['content'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation"></i>
        <div>The management team reserves the right to change rules at any time. By playing on our server, you agree to follow all rules. For ban appeals, visit the <a href="<?= SITE_URL ?>/forum.php?cat=appeals" style="color:var(--gold)">Ban Appeals</a> section.</div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
