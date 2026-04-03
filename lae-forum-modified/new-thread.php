<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$currentUser = getCurrentUser();
$db = getDB();

if (!$currentUser['can_create_threads']) {
    header('Location: ' . SITE_URL . '/forum.php?error=nopermission');
    exit;
}

$pageTitle = 'New Thread · LAE Forums';
$error = '';

// Get categories — only show ones this user can create threads in
$allCategories = $db->query("SELECT * FROM categories WHERE is_visible = 1 ORDER BY sort_order")->fetchAll();
$categories = array_filter($allCategories, fn($cat) => canUserInCategory((int)$cat['id'], $currentUser, 'can_create_threads'));

// Pre-select category
$catId = (int)($_GET['cat'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $catId   = (int)($_POST['category_id'] ?? 0);

        if (strlen($title) < 5 || strlen($title) > 255) {
            $error = 'Title must be 5–255 characters.';
        } elseif (strlen($content) < 10) {
            $error = 'Post content must be at least 10 characters.';
        } elseif (!$catId) {
            $error = 'Please select a category.';
        } else {
            // Verify category exists
            $cat = $db->prepare("SELECT id FROM categories WHERE id = ? AND is_visible = 1");
            $cat->execute([$catId]);
            if (!$cat->fetch()) { $error = 'Invalid category.'; }
            elseif (!canUserInCategory($catId, $currentUser, 'can_create_threads')) { $error = 'You do not have permission to create threads in this category.'; }
            else {
                $slugBase = slug($title);
                // Make unique slug
                $slugCheck = $db->prepare("SELECT id FROM threads WHERE slug = ?");
                $uniqueSlug = $slugBase;
                $i = 1;
                while (true) {
                    $slugCheck->execute([$uniqueSlug]);
                    if (!$slugCheck->fetch()) break;
                    $uniqueSlug = $slugBase . '-' . $i++;
                }

                // Insert thread
                $db->prepare("INSERT INTO threads (title, slug, category_id, user_id) VALUES (?,?,?,?)")
                   ->execute([$title, $uniqueSlug, $catId, $currentUser['id']]);
                $threadId = $db->lastInsertId();

                // Insert first post
                $db->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?,?,?)")
                   ->execute([$threadId, $currentUser['id'], $content]);

                // Update counters
                $db->prepare("UPDATE categories SET thread_count = thread_count + 1, post_count = post_count + 1 WHERE id = ?")
                   ->execute([$catId]);
                $db->prepare("UPDATE users SET post_count = post_count + 1 WHERE id = ?")
                   ->execute([$currentUser['id']]);
                $db->prepare("UPDATE threads SET last_reply_user_id = ?, last_reply_at = NOW() WHERE id = ?")
                   ->execute([$currentUser['id'], $threadId]);

                logAudit($currentUser['id'], 'create_thread', 'thread', $threadId, $title);

                header('Location: ' . SITE_URL . '/thread.php?id=' . $threadId);
                exit;
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:900px">
    <div class="breadcrumb">
        <a href="<?= SITE_URL ?>/">Home</a>
        <i class="fas fa-chevron-right"></i>
        <a href="<?= SITE_URL ?>/forum.php">Forums</a>
        <i class="fas fa-chevron-right"></i>
        <span>New Thread</span>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-plus-circle"></i> Create New Thread</h2>
        </div>
        <div style="padding:24px">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">— Select a Category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Thread Title <span style="color:var(--t2);font-size:0.75rem">(<span id="title-counter">255</span> chars left)</span></label>
                    <input type="text" name="title" class="form-input" placeholder="Give your thread a clear, descriptive title"
                           value="<?= e($_POST['title'] ?? '') ?>" minlength="5" maxlength="255" required
                           data-maxlength="255" data-counter="title-counter">
                </div>

                <div class="form-group">
                    <label class="form-label">Content</label>
                    <div class="editor-toolbar">
                        <button type="button" class="tb-btn" data-tag="b" data-sample="bold text" title="Bold"><b>B</b></button>
                        <button type="button" class="tb-btn" data-tag="i" data-sample="italic text" title="Italic"><i>I</i></button>
                        <button type="button" class="tb-btn" data-tag="u" data-sample="underlined" title="Underline"><u>U</u></button>
                        <button type="button" class="tb-btn" data-tag="s" data-sample="strikethrough" title="Strikethrough"><s>S</s></button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="color" data-end="color" data-sample="red text" data-attr="=#ff6b6b" title="Colour">🎨</button>
                        <button type="button" class="tb-btn" data-tag="size" data-end="size" data-sample="big text" data-attr="=3" title="Size">A↕</button>
                        <button type="button" class="tb-btn" data-tag="highlight" data-sample="highlighted text" title="Highlight">H</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="center" data-sample="centred text" title="Centre">≡</button>
                        <button type="button" class="tb-btn" data-tag="right" data-sample="right aligned" title="Right align">⇥</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="quote" data-sample="quoted text" title="Quote">❝</button>
                        <button type="button" class="tb-btn" data-tag="code" data-sample="your code here" title="Code block">&lt;/&gt;</button>
                        <button type="button" class="tb-btn" data-tag="icode" data-sample="inline code" title="Inline code">`</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="url" data-end="url" data-sample="https://example.com" data-attr="=https://example.com" title="Link">🔗</button>
                        <button type="button" class="tb-btn" data-tag="img" data-sample="https://example.com/img.jpg" title="Image">🖼</button>
                        <button type="button" class="tb-btn" data-tag="youtube" data-sample="https://youtu.be/dQw4w9WgXcQ" title="YouTube">▶</button>
                        <span style="width:1px;background:var(--b0);margin:2px 3px;align-self:stretch"></span>
                        <button type="button" class="tb-btn" data-tag="list" data-sample="[*]Item 1[*]Item 2" title="List">☰</button>
                        <button type="button" class="tb-btn" data-tag="spoiler" data-sample="secret content" title="Spoiler">👁</button>
                        <button type="button" class="tb-btn" data-tag="notice" data-sample="important notice here" title="Notice">ℹ</button>
                        <button type="button" class="tb-btn" data-insert="[hr]" title="Divider">—</button>
                    </div>
                    <textarea name="content" class="form-textarea" style="min-height:300px;border-radius:0 0 var(--r) var(--r)"
                              placeholder="Write your post here... Support for BBCode: [b]bold[/b] [i]italic[/i] [quote]quoted[/quote] [code]code[/code]"
                              required><?= e($_POST['content'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <a href="<?= SITE_URL ?>/forum.php" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-accent btn-lg"><i class="fas fa-paper-plane"></i> Post Thread</button>
                </div>
            </form>
        </div>
    </div>

    <!-- BBCode reference card -->
    <div class="card" style="margin-top:16px">
        <div class="card-header"><h3><i class="fas fa-code"></i> BBCode Reference</h3></div>
        <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:0.82rem;font-family:var(--font-mono)">
            <div><code>[b]bold[/b]</code></div>
            <div><code>[i]italic[/i]</code></div>
            <div><code>[u]underline[/u]</code></div>
            <div><code>[s]strikethrough[/s]</code></div>
            <div><code>[color=#ff0000]text[/color]</code></div>
            <div><code>[size=2]large text[/size]</code></div>
            <div><code>[highlight]text[/highlight]</code></div>
            <div><code>[center]centred[/center]</code></div>
            <div><code>[quote=Name]...[/quote]</code></div>
            <div><code>[code=php]...[/code]</code></div>
            <div><code>[icode]inline[/icode]</code></div>
            <div><code>[url=https://...]link[/url]</code></div>
            <div><code>[img]url[/img]</code></div>
            <div><code>[youtube]video_id[/youtube]</code></div>
            <div><code>[spoiler]secret[/spoiler]</code></div>
            <div><code>[notice]info[/notice]</code></div>
            <div><code>[warning]warn[/warning]</code></div>
            <div><code>[list][*]Item[/list]</code></div>
            <div><code>[hr]</code> (divider)</div>
            <div><code>[br]</code> (line break)</div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
