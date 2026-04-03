<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDB();
$pageTitle = 'Staff Application · LAE Forums';
$error = '';
$success = '';

// Check for existing pending application
$existing = $db->prepare("SELECT id, status FROM applications WHERE user_id = ? AND status IN ('pending','reviewing')");
$existing->execute([$currentUser['id']]);
$existing = $existing->fetch();

// Position-specific questions
$positionQuestions = [
    'Moderator' => [
        'scenario' => "A player submits a report claiming someone is using racial slurs in voice chat. You have no recording. How do you handle this?",
        'extra_q'  => "Describe a conflict you've had to mediate (in real life or online). How did you resolve it?",
        'extra_label' => "Conflict Resolution Example",
    ],
    'Senior Moderator' => [
        'scenario' => "A junior moderator issued a permanent ban that you believe was too harsh. The banned player has appealed. Walk through your process.",
        'extra_q'  => "What systems or processes would you put in place to improve staff consistency and accountability?",
        'extra_label' => "Process Improvement",
    ],
    'Support Staff' => [
        'scenario' => "A player DMs the Discord saying they lost $500,000 in-game due to a script bug and is threatening to charge-back their donation. How do you respond?",
        'extra_q'  => "What experience do you have with customer service or community support? This can be gaming-related or IRL.",
        'extra_label' => "Support Experience",
    ],
    'Developer' => [
        'scenario' => "You discover a dupe exploit in a script you wrote that has already been abused by 3 players. What steps do you take immediately and long-term?",
        'extra_q'  => "Link to or describe a project you've built (FiveM resource, website, tool, etc.). What languages/frameworks did you use?",
        'extra_label' => "Portfolio / Project",
    ],
    'Police Cadet' => [
        'scenario' => "You pull someone over for speeding. During the stop you notice a weapon on the passenger seat. Walk through the traffic stop procedure.",
        'extra_q'  => "Do you have any prior LEO roleplay experience? List servers and your highest rank achieved.",
        'extra_label' => "LEO Experience",
    ],
    'EMS Trainee' => [
        'scenario' => "You receive two EMS calls simultaneously — a player who has been shot (critical) and a multi-car pile-up with 4 injured. You're the only EMS on. How do you prioritise?",
        'extra_q'  => "Have you played EMS on any other FiveM servers? What protocols or systems were you trained in?",
        'extra_label' => "EMS Experience",
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } elseif ($existing) {
        $error = 'You already have a pending application.';
    } else {
        $position = trim($_POST['position'] ?? '');
        $fields = [
            'position'            => $position,
            'age'                 => (int)($_POST['age'] ?? 0),
            'timezone'            => trim($_POST['timezone'] ?? ''),
            'hours_available'     => (int)($_POST['hours_available'] ?? 0),
            'previous_experience' => trim($_POST['previous_experience'] ?? ''),
            'why_apply'           => trim($_POST['why_apply'] ?? ''),
            'scenario_answer'     => trim($_POST['scenario_answer'] ?? ''),
            'additional_info'     => trim($_POST['additional_info'] ?? ''),
        ];
        $extraAnswer = trim($_POST['extra_answer'] ?? '');

        if (!$position || !isset($positionQuestions[$position])) $error = 'Please select a valid position.';
        elseif ($fields['age'] < 15 || $fields['age'] > 80) $error = 'Please enter a valid age (15–80).';
        elseif (!$fields['timezone']) $error = 'Please enter your timezone.';
        elseif ($fields['hours_available'] < 1) $error = 'Please enter your available hours per week.';
        elseif (strlen($fields['why_apply']) < 50) $error = 'Please provide a more detailed answer for why you want to apply (min 50 chars).';
        elseif (strlen($fields['scenario_answer']) < 50) $error = 'Please provide a more detailed scenario answer (min 50 chars).';
        elseif (strlen($extraAnswer) < 20) $error = 'Please answer the additional question.';
        else {
            $fields['additional_info'] = ($extraAnswer ? "[" . $positionQuestions[$position]['extra_label'] . "]\n" . $extraAnswer . "\n\n" : '') . $fields['additional_info'];

            $db->prepare("INSERT INTO applications (user_id, position, age, timezone, hours_available, previous_experience, why_apply, scenario_answer, additional_info) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$currentUser['id'], $fields['position'], $fields['age'], $fields['timezone'], $fields['hours_available'], $fields['previous_experience'], $fields['why_apply'], $fields['scenario_answer'], $fields['additional_info']]);
            $appId = $db->lastInsertId();

            // Create linked thread
            $appsCat = $db->query("SELECT id FROM categories WHERE slug = 'applications' LIMIT 1")->fetch();
            if ($appsCat) {
                $threadTitle = $fields['position'] . ' Application — ' . $currentUser['username'];
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $threadTitle)) . '-' . $appId;
                $content  = "[b]Position:[/b] " . $fields['position'] . "\n";
                $content .= "[b]Age:[/b] " . $fields['age'] . "  |  [b]Timezone:[/b] " . $fields['timezone'] . "  |  [b]Hours/Week:[/b] " . $fields['hours_available'] . "\n\n";
                $content .= "[b]Previous Experience:[/b]\n" . $fields['previous_experience'] . "\n\n";
                $content .= "[b]Why I Want to Apply:[/b]\n" . $fields['why_apply'] . "\n\n";
                $content .= "[b]Scenario:[/b] " . $positionQuestions[$position]['scenario'] . "\n" . $fields['scenario_answer'];
                if ($extraAnswer) $content .= "\n\n[b]" . $positionQuestions[$position]['extra_label'] . ":[/b]\n" . $extraAnswer;
                if ($fields['additional_info']) $content .= "\n\n[b]Additional Info:[/b]\n" . $fields['additional_info'];

                $db->prepare("INSERT INTO threads (title, slug, category_id, user_id, thread_type, application_id, is_locked) VALUES (?,?,?,?,?,?,1)")
                   ->execute([$threadTitle, $slug, $appsCat['id'], $currentUser['id'], 'application', $appId]);
                $threadId = $db->lastInsertId();
                $db->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?,?,?)")
                   ->execute([$threadId, $currentUser['id'], $content]);
                $db->prepare("UPDATE categories SET thread_count = thread_count + 1 WHERE id = ?")
                   ->execute([$appsCat['id']]);
            }

            $success = 'Your application has been submitted! We will review it and get back to you.';
            $existing = ['status' => 'pending'];
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:820px">
    <nav class="breadcrumb" style="margin-bottom:20px">
        <a href="<?= SITE_URL ?>/">Home</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php?cat=applications">Applications</a>
        <i class="fas fa-chevron-right"></i>
        <span>Apply for Staff</span>
    </nav>

    <div style="margin-bottom:28px">
        <h1 style="font-family:var(--font-head);font-size:2.4rem;font-weight:900;letter-spacing:2px;text-transform:uppercase;line-height:1">
            Staff <span style="color:var(--red)">Application</span>
        </h1>
        <p style="color:var(--t2);margin-top:6px">Join the Los Angeles Experience team. Read the requirements carefully before applying.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($existing && !$success): ?>
        <div class="alert alert-warning"><i class="fas fa-clock"></i> You have a pending application with status: <strong><?= ucfirst($existing['status']) ?></strong>. You cannot submit another until it is reviewed.</div>
    <?php endif; ?>

    <?php if (!$existing || $success): ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-file-alt"></i> Application Form</h2></div>
        <div style="padding:24px">
            <form method="POST" id="appForm">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                <!-- Position selector -->
                <div class="form-group" style="margin-bottom:24px">
                    <label class="form-label">Position Applying For <span style="color:var(--red)">*</span></label>
                    <select name="position" id="positionSelect" class="form-select" required>
                        <option value="">— Select a position —</option>
                        <option value="Moderator"       <?= ($_POST['position']??'')==='Moderator'?'selected':'' ?>>Moderator</option>
                        <option value="Senior Moderator"<?= ($_POST['position']??'')==='Senior Moderator'?'selected':'' ?>>Senior Moderator</option>
                        <option value="Support Staff"   <?= ($_POST['position']??'')==='Support Staff'?'selected':'' ?>>Support Staff (Website &amp; Discord)</option>
                        <option value="Developer"       <?= ($_POST['position']??'')==='Developer'?'selected':'' ?>>Developer (FiveM/Web)</option>
                        <option value="Police Cadet"    <?= ($_POST['position']??'')==='Police Cadet'?'selected':'' ?>>Police Cadet (LSPD)</option>
                        <option value="EMS Trainee"     <?= ($_POST['position']??'')==='EMS Trainee'?'selected':'' ?>>EMS Trainee</option>
                    </select>
                    <div class="form-hint">Questions will update based on the position you select.</div>
                </div>

                <!-- Position description cards -->
                <div id="posDesc" style="margin-bottom:20px;display:none">
                    <?php foreach ([
                        'Moderator'       => ['icon'=>'fa-shield','color'=>'#3d7ebf','desc'=>'Moderators enforce server rules, handle reports, issue warnings and bans, and help maintain a positive community environment.','req'=>'Must be 16+, active daily, speak fluent English, no active punishments.'],
                        'Senior Moderator'=> ['icon'=>'fa-shield-halved','color'=>'#c9a227','desc'=>'Senior Mods supervise moderators, handle escalated cases, review bans, and help shape staff policies.','req'=>'Must have prior mod experience (here or elsewhere), 18+, highly trusted.'],
                        'Support Staff'   => ['icon'=>'fa-headset','color'=>'#37b679','desc'=>'Support Staff assists players and forum members with questions, bugs, and issues via Discord and the website. First line of contact.','req'=>'Patient, friendly, responsive. Discord active daily. No experience needed.'],
                        'Developer'       => ['icon'=>'fa-code','color'=>'#8b6fd4','desc'=>'Developers build and maintain FiveM scripts, the forum, and server infrastructure. Must be able to work independently.','req'=>'Strong Lua or PHP/JS skills. Portfolio required. Must not leak server code.'],
                        'Police Cadet'    => ['icon'=>'fa-star','color'=>'#3d7ebf','desc'=>'Police Cadets are the first stage of the LSPD. You will be trained in LEO procedures and FRP protocols by senior officers.','req'=>'Active in-game player. Good knowledge of server rules. Not currently banned.'],
                        'EMS Trainee'     => ['icon'=>'fa-star-of-life','color'=>'#e63946','desc'=>'EMS Trainees assist senior paramedics and learn emergency medical roleplay procedures, triage, and dispatch coordination.','req'=>'Active in-game. Calm under pressure. Willing to commit to shifts.'],
                    ] as $pos => $info): ?>
                    <div class="pos-desc-card" data-pos="<?= $pos ?>" style="display:none;background:var(--bg3);border:1px solid var(--b1);border-radius:var(--radius);padding:16px;border-left:3px solid <?= $info['color'] ?>">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                            <i class="fas <?= $info['icon'] ?>" style="color:<?= $info['color'] ?>;font-size:1.1rem"></i>
                            <strong style="font-family:var(--font-head);letter-spacing:1px;text-transform:uppercase;font-size:1rem"><?= $pos ?></strong>
                        </div>
                        <p style="font-size:0.85rem;color:var(--t1);margin-bottom:8px"><?= $info['desc'] ?></p>
                        <div style="font-size:0.78rem;color:var(--t2)"><i class="fas fa-circle-check" style="color:var(--green);margin-right:4px"></i><?= $info['req'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Basic info -->
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:4px">
                    <div class="form-group">
                        <label class="form-label">Age <span style="color:var(--red)">*</span></label>
                        <input type="number" name="age" class="form-input" placeholder="e.g. 19" min="15" max="80" required value="<?= e($_POST['age'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Timezone <span style="color:var(--red)">*</span></label>
                        <input type="text" name="timezone" class="form-input" placeholder="e.g. GMT+1, EST" value="<?= e($_POST['timezone'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Hours / Week <span style="color:var(--red)">*</span></label>
                        <input type="number" name="hours_available" class="form-input" placeholder="e.g. 15" min="1" max="168" required value="<?= e($_POST['hours_available'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Previous Staff / Leadership Experience</label>
                    <textarea name="previous_experience" class="form-textarea" style="min-height:90px" placeholder="List relevant experience. Include server names, roles held, and duration. Write 'None' if you have no prior experience."><?= e($_POST['previous_experience'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Why Do You Want to Join the Team? <span style="color:var(--red)">*</span></label>
                    <div class="form-hint" style="margin-bottom:8px">Minimum 50 characters. Be genuine — we can tell when it's copy-pasted.</div>
                    <textarea name="why_apply" class="form-textarea" placeholder="Tell us why you're applying, what motivates you, and what you bring to the team..." required><?= e($_POST['why_apply'] ?? '') ?></textarea>
                    <div class="form-hint" id="whyCount" style="text-align:right">0 characters</div>
                </div>

                <!-- Dynamic scenario question (changes per position) -->
                <div class="form-group" id="scenarioGroup" style="display:none">
                    <label class="form-label" id="scenarioLabel">Scenario Question <span style="color:var(--red)">*</span></label>
                    <div class="form-hint" id="scenarioText" style="margin-bottom:8px;color:var(--t1);font-style:italic"></div>
                    <div class="form-hint" style="margin-bottom:8px">Walk through your thinking step by step. Minimum 50 characters.</div>
                    <textarea name="scenario_answer" id="scenarioAnswer" class="form-textarea" placeholder="Walk through how you would handle this..." required><?= e($_POST['scenario_answer'] ?? '') ?></textarea>
                </div>

                <!-- Dynamic extra question (changes per position) -->
                <div class="form-group" id="extraGroup" style="display:none">
                    <label class="form-label" id="extraLabel">Additional Question <span style="color:var(--red)">*</span></label>
                    <textarea name="extra_answer" id="extraAnswer" class="form-textarea" style="min-height:100px" placeholder="Your answer..."><?= e($_POST['extra_answer'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Anything Else? <span style="color:var(--t2)">(Optional)</span></label>
                    <textarea name="additional_info" class="form-textarea" style="min-height:70px" placeholder="Links, references, or anything else you'd like us to know."><?= e($_POST['additional_info'] ?? '') ?></textarea>
                </div>

                <div class="alert alert-info" style="margin-bottom:20px">
                    <i class="fas fa-info-circle"></i>
                    <div>By submitting you confirm all information is truthful. Providing false information will result in immediate denial and may lead to a permanent ban.</div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <a href="<?= SITE_URL ?>/forum.php" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-accent btn-lg"><i class="fas fa-paper-plane"></i> Submit Application</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const questions = <?= json_encode($positionQuestions) ?>;

const posSelect   = document.getElementById('positionSelect');
const posDesc     = document.getElementById('posDesc');
const scenarioGrp = document.getElementById('scenarioGroup');
const scenarioTxt = document.getElementById('scenarioText');
const extraGrp    = document.getElementById('extraGroup');
const extraLbl    = document.getElementById('extraLabel');
const whyArea     = document.querySelector('[name="why_apply"]');
const whyCount    = document.getElementById('whyCount');

function updateForm() {
    const pos = posSelect.value;
    // Show/hide position description
    document.querySelectorAll('.pos-desc-card').forEach(c => c.style.display = 'none');
    if (pos) {
        posDesc.style.display = 'block';
        const card = document.querySelector(`.pos-desc-card[data-pos="${pos}"]`);
        if (card) card.style.display = 'block';
    } else {
        posDesc.style.display = 'none';
    }
    // Show/hide scenario & extra
    if (pos && questions[pos]) {
        scenarioGrp.style.display = 'block';
        scenarioTxt.textContent = questions[pos].scenario;
        extraGrp.style.display = 'block';
        extraLbl.innerHTML = questions[pos].extra_label + ' <span style="color:var(--red)">*</span>';
        document.getElementById('extraAnswer').placeholder = questions[pos].extra_q;
    } else {
        scenarioGrp.style.display = 'none';
        extraGrp.style.display = 'none';
    }
}

posSelect.addEventListener('change', updateForm);
if (whyArea) {
    whyArea.addEventListener('input', () => {
        const n = whyArea.value.length;
        whyCount.textContent = n + ' characters' + (n < 50 ? ` (need ${50-n} more)` : ' ✓');
        whyCount.style.color = n >= 50 ? 'var(--green)' : 'var(--t2)';
    });
}
// Run on load (in case browser restores form values)
updateForm();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
