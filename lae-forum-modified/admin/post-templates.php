<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();

// Only owner/head_admin can access templates
if (empty($currentUser['can_admin']) && $currentUser['role_name'] !== 'owner') {
    header('Location: ' . SITE_URL . '/');
    exit;
}

$pageTitle = 'Post Templates · LAE';

$SEAL_URL = SITE_URL . '/public/images/president-seal.png';
$PRESIDENT_NAME = $currentUser['username'];

// All templates as BBCode strings
$templates = [

    'official_announcement' => [
        'label' => 'Official Announcement',
        'icon'  => 'fa-bullhorn',
        'color' => '#c9a227',
        'desc'  => 'General community-wide announcement with presidential seal',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][size=2][b]OFFICIAL ANNOUNCEMENT[/b][/size]
[color=#c9a227]━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━[/color][/center]

[center][size=2][b]SUBJECT: [YOUR ANNOUNCEMENT TITLE HERE][/b][/size][/center]

[b]To:[/b] All Members of Los Angeles Experience
[b]From:[/b] The Office of the Community President
[b]Date:[/b] [INSERT DATE]

[hr]

[YOUR ANNOUNCEMENT CONTENT HERE]

[hr]

[center][color=#c9a227][b]BY ORDER OF THE COMMUNITY PRESIDENT[/b][/color]
[b]{$PRESIDENT_NAME}[/b]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'server_update' => [
        'label' => 'Server Update Log',
        'icon'  => 'fa-code-branch',
        'color' => '#37b679',
        'desc'  => 'Patch notes / update release with version tracking',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#37b679][size=2][b]SERVER UPDATE[/b][/size][/color]
[b]Version [X.X.X] — [DATE][/b][/center]

[hr]

[notice][b]Update Name:[/b] [NAME OF UPDATE][/notice]

[b]New Additions:[/b]
[list]
[*][YOUR ADDITION]
[*][YOUR ADDITION]
[/list]

[b]Bug Fixes:[/b]
[list]
[*][YOUR FIX]
[*][YOUR FIX]
[/list]

[b]Changes:[/b]
[list]
[*][YOUR CHANGE]
[/list]

[hr]

[b]Notes:[/b]
[YOUR ADDITIONAL NOTES]

[center][color=#37b679]— {$PRESIDENT_NAME}, Community President[/color][/center]
BBQ,
    ],

    'rule_change' => [
        'label' => 'Rule Change Notice',
        'icon'  => 'fa-scale-balanced',
        'color' => '#e63946',
        'desc'  => 'Formal notice of rule additions, removals, or amendments',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#e63946][size=2][b]COMMUNITY RULE AMENDMENT[/b][/size][/color]
[b]Effective: [DATE][/b][/center]

[hr]

[warning]This notice constitutes an official amendment to the Los Angeles Experience community ruleset. All members are expected to comply immediately upon publication.[/warning]

[b]Amendment Reference:[/b] [RULE NUMBER / SECTION]
[b]Type:[/b] [Addition / Modification / Removal]
[b]Previous:[/b] [OLD RULE TEXT — or "N/A" for new rules]

[hr]

[b]Amendment:[/b]
[YOUR NEW RULE TEXT]

[b]Reason for Amendment:[/b]
[EXPLAIN WHY THIS RULE IS BEING CHANGED]

[hr]

[center][b]This amendment is binding effective immediately.[/b]

[color=#e63946]— {$PRESIDENT_NAME}[/color]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'disciplinary_action' => [
        'label' => 'Disciplinary Statement',
        'icon'  => 'fa-gavel',
        'color' => '#8b6fd4',
        'desc'  => 'Public disciplinary notice for community actions',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#8b6fd4][size=2][b]OFFICIAL DISCIPLINARY STATEMENT[/b][/size][/color][/center]

[hr]

[notice]This statement is issued in the interest of transparency. All disciplinary actions are reviewed and approved by community leadership.[/notice]

[b]Subject:[/b] [MEMBER NAME]
[b]Action Taken:[/b] [Warning / Temporary Ban / Permanent Ban / Demotion]
[b]Date:[/b] [DATE]
[b]Reviewed By:[/b] {$PRESIDENT_NAME}, Community President

[b]Reason:[/b]
[DETAILED REASON FOR ACTION]

[b]Evidence Reviewed:[/b]
[WHAT EVIDENCE WAS CONSIDERED]

[b]Right to Appeal:[/b]
This member [has / does not have] the right to appeal this decision via the official Ban Appeals process.

[hr]

[center][i]All disciplinary matters are handled in accordance with LAE Community Standards.[/i]

[color=#8b6fd4]— {$PRESIDENT_NAME}[/color]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'community_address' => [
        'label' => 'State of the Community Address',
        'icon'  => 'fa-flag',
        'color' => '#3d7ebf',
        'desc'  => 'Periodic community state-of-the-union style address',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#3d7ebf][size=2][b]STATE OF THE COMMUNITY ADDRESS[/b][/size][/color]
[b][MONTH / QUARTER / YEAR][/b][/center]

[hr]

[b]Fellow Members of Los Angeles Experience,[/b]

[YOUR OPENING PARAGRAPH — greet the community]

[hr]

[color=#3d7ebf][b]COMMUNITY GROWTH[/b][/color]
[YOUR GROWTH STATS AND HIGHLIGHTS]

[hr]

[color=#3d7ebf][b]WHAT WE ACCOMPLISHED[/b][/color]
[list]
[*][ACCOMPLISHMENT]
[*][ACCOMPLISHMENT]
[/list]

[hr]

[color=#3d7ebf][b]LOOKING AHEAD[/b][/color]
[YOUR UPCOMING PLANS AND GOALS]

[hr]

[color=#3d7ebf][b]CLOSING REMARKS[/b][/color]
[YOUR CLOSING MESSAGE]

[center][color=#3d7ebf][b]Thank you for making LAE what it is.[/b][/color]

[b]{$PRESIDENT_NAME}[/b]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'event_announcement' => [
        'label' => 'Community Event',
        'icon'  => 'fa-calendar-star',
        'color' => '#f4a261',
        'desc'  => 'Announce server events, meetups, competitions',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#f4a261][size=2][b]COMMUNITY EVENT ANNOUNCEMENT[/b][/size][/color][/center]

[hr]

[center][size=2][b][EVENT NAME][/b][/size]
[color=#f4a261][b][DATE] · [TIME] · [LOCATION IN-GAME][/b][/color][/center]

[hr]

[b]What is it?[/b]
[DESCRIBE THE EVENT]

[b]How to participate:[/b]
[list]
[*][STEP 1]
[*][STEP 2]
[/list]

[b]Prizes / Rewards:[/b]
[PRIZES OR "This is a community event — come for the fun!"]

[b]Rules for the event:[/b]
[list]
[*][RULE]
[*][RULE]
[/list]

[hr]

[success]All members are welcome to attend! We hope to see you there.[/success]

[center][color=#f4a261]— {$PRESIDENT_NAME}[/color]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'staff_promotion' => [
        'label' => 'Staff Promotion Notice',
        'icon'  => 'fa-shield-halved',
        'color' => '#c9a227',
        'desc'  => 'Announce staff promotions or new appointments',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#c9a227][size=2][b]STAFF APPOINTMENT NOTICE[/b][/size][/color][/center]

[hr]

[center]It is with great pleasure that I announce the following staff appointment:[/center]

[center][size=2][b][MEMBER NAME][/b][/size]
has been appointed to the position of

[color=#c9a227][size=2][b][NEW ROLE][/b][/size][/color]

Effective [DATE][/center]

[hr]

[b]About this appointment:[/b]
[EXPLAIN WHY THIS PERSON WAS CHOSEN / WHAT THEY BRING]

[b]Their responsibilities:[/b]
[list]
[*][RESPONSIBILITY]
[*][RESPONSIBILITY]
[/list]

[center][i]Please join me in congratulating [MEMBER NAME] on this well-deserved appointment.[/i]

[color=#c9a227]— {$PRESIDENT_NAME}[/color]
Community President — Los Angeles Experience[/center]
BBQ,
    ],

    'apology_statement' => [
        'label' => 'Apology / Correction Statement',
        'icon'  => 'fa-hand-holding-heart',
        'color' => '#37b679',
        'desc'  => 'Public apology or correction of a previous statement',
        'code'  => <<<BBQ
[center][img=80x80]{$SEAL_URL}[/img][/center]
[center][color=#37b679][size=2][b]STATEMENT OF CORRECTION[/b][/size][/color][/center]

[hr]

[b]Dear Members of Los Angeles Experience,[/b]

[YOUR OPENING — acknowledge the issue]

[b]What happened:[/b]
[DESCRIBE WHAT WENT WRONG]

[b]What we should have done:[/b]
[DESCRIBE THE CORRECT COURSE OF ACTION]

[b]What we are doing to fix it:[/b]
[CORRECTIVE ACTIONS BEING TAKEN]

[hr]

[success]We are committed to doing better and maintaining the trust of our community.[/success]

[center]Sincerely,

[b]{$PRESIDENT_NAME}[/b]
Community President — Los Angeles Experience[/center]
BBQ,
    ],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:980px">
    <div style="margin-bottom:28px">
        <h1 style="font-family:var(--font-head);font-size:2rem;font-weight:900;letter-spacing:2px;text-transform:uppercase">
            <i class="fas fa-scroll" style="color:var(--red)"></i> Post Templates
        </h1>
        <p style="color:var(--t2);margin-top:4px">Presidential announcement templates with seal. Click any template to copy the BBCode.</p>
    </div>

    <!-- Seal preview -->
    <div class="card" style="margin-bottom:24px;padding:20px;display:flex;align-items:center;gap:20px">
        <img src="<?= SITE_URL ?>/public/images/president-seal.png" style="width:72px;height:72px;object-fit:contain;flex-shrink:0">
        <div>
            <div style="font-weight:700;font-size:0.95rem;margin-bottom:4px">Community President Seal</div>
            <div style="font-size:0.82rem;color:var(--t2)">Your seal is embedded in all templates. It automatically links to the hosted image on your server.</div>
            <code style="font-size:0.75rem;color:var(--t1);background:var(--bg3);padding:2px 8px;border-radius:3px;display:inline-block;margin-top:6px"><?= SITE_URL ?>/public/images/president-seal.png</code>
        </div>
    </div>

    <!-- Template grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:32px">
        <?php foreach ($templates as $key => $tpl): ?>
        <div class="template-card" onclick="showTemplate('<?= $key ?>')" data-key="<?= $key ?>">
            <div class="tc-icon-label">
                <div style="width:36px;height:36px;border-radius:var(--radius);background:<?= $tpl['color'] ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fas <?= $tpl['icon'] ?>" style="color:<?= $tpl['color'] ?>;font-size:0.95rem"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.88rem;color:var(--t0)"><?= e($tpl['label']) ?></div>
                    <div style="font-size:0.75rem;color:var(--t2);margin-top:1px"><?= e($tpl['desc']) ?></div>
                </div>
            </div>
            <div style="margin-top:10px;display:flex;gap:6px">
                <button onclick="event.stopPropagation();copyTemplate('<?= $key ?>')" class="btn btn-accent btn-sm" style="flex:1;justify-content:center">
                    <i class="fas fa-copy"></i> Copy BBCode
                </button>
                <button onclick="event.stopPropagation();showTemplate('<?= $key ?>')" class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Template preview modal -->
    <div id="templateModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,0.8);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:20px">
        <div style="background:var(--bg2);border:1px solid var(--b1);border-radius:var(--radius);width:100%;max-width:720px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--b0)">
                <h3 id="modalTitle" style="font-family:var(--font-head);font-size:1rem;letter-spacing:1px;text-transform:uppercase"></h3>
                <button onclick="closeModal()" style="background:none;border:none;color:var(--t2);cursor:pointer;font-size:1.1rem;padding:4px">✕</button>
            </div>
            <div style="padding:20px;overflow-y:auto;flex:1">
                <pre id="modalCode" style="font-family:var(--font-mono);font-size:0.8rem;color:var(--t1);background:var(--bg1);border:1px solid var(--b0);border-radius:var(--radius);padding:16px;white-space:pre-wrap;word-break:break-word;line-height:1.6"></pre>
            </div>
            <div style="padding:14px 20px;border-top:1px solid var(--b0);display:flex;gap:8px;justify-content:flex-end">
                <button onclick="copyCurrentTemplate()" class="btn btn-accent"><i class="fas fa-copy"></i> Copy to Clipboard</button>
                <button onclick="closeModal()" class="btn btn-ghost">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.template-card {
    background: var(--bg2);
    border: 1px solid var(--b0);
    border-radius: var(--radius);
    padding: 16px;
    cursor: pointer;
    transition: background var(--dur), border-color var(--dur), transform var(--dur);
}
.template-card:hover {
    background: var(--bg3);
    border-color: var(--b1);
    transform: translateY(-2px);
}
.tc-icon-label {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
</style>

<script>
const templates = <?= json_encode(array_map(fn($t) => ['label'=>$t['label'],'code'=>$t['code']], $templates)) ?>;
let currentKey = null;

function showTemplate(key) {
    currentKey = key;
    const tpl = templates[key];
    document.getElementById('modalTitle').textContent = tpl.label;
    document.getElementById('modalCode').textContent = tpl.code;
    document.getElementById('templateModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('templateModal').style.display = 'none';
    currentKey = null;
}

async function copyTemplate(key) {
    const code = templates[key].code;
    try {
        await navigator.clipboard.writeText(code);
        showFlash('BBCode copied to clipboard! Paste it in your new thread.', 'success');
    } catch(e) {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = code;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showFlash('Copied!', 'success');
    }
}

async function copyCurrentTemplate() {
    if (currentKey) await copyTemplate(currentKey);
}

// Close modal on backdrop click
document.getElementById('templateModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
