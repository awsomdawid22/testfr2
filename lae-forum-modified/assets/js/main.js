// ============================================
// Los Angeles Experience Forum - Main JS
// ============================================

document.addEventListener('DOMContentLoaded', () => {

    // User dropdown toggle
    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
        const trigger = userMenu.querySelector('.user-trigger');
        trigger?.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenu.classList.toggle('open');
        });
        document.addEventListener('click', () => userMenu.classList.remove('open'));
    }

    // Mobile menu toggle
    const mobileToggle = document.getElementById('mobileToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    if (mobileToggle && mobileMenu) {
        mobileToggle.addEventListener('click', () => {
            mobileMenu.classList.toggle('open');
        });
    }

    // ── BBCode editor toolbar ────────────────────────────────────────────────
    // Supports data-target="textarea-id" (for edit boxes) or falls back to
    // nextElementSibling (for standard new-post / reply boxes).
    function initToolbar(toolbar) {
        if (toolbar.dataset.toolbarInit) return; // already wired up
        toolbar.dataset.toolbarInit = '1';

        function getTextarea() {
            const tid = toolbar.dataset.target;
            return tid ? document.getElementById(tid) : toolbar.nextElementSibling;
        }

        toolbar.querySelectorAll('.tb-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const textarea = getTextarea();
                if (!textarea) return;

                // data-insert: bare insertion (e.g. [hr])
                if (btn.dataset.insert) {
                    const pos    = textarea.selectionStart;
                    const before = textarea.value.substring(0, pos);
                    const after  = textarea.value.substring(pos);
                    textarea.value = before + btn.dataset.insert + after;
                    textarea.focus();
                    textarea.selectionStart = textarea.selectionEnd = pos + btn.dataset.insert.length;
                    return;
                }

                const tag    = btn.dataset.tag;
                if (!tag) return;
                const end    = btn.dataset.end  || tag;
                const attr   = btn.dataset.attr || '';
                const sample = btn.dataset.sample || 'text';
                const start  = textarea.selectionStart;
                const finish = textarea.selectionEnd;
                const selected  = textarea.value.substring(start, finish) || sample;
                const before    = textarea.value.substring(0, start);
                const after     = textarea.value.substring(finish);
                const openTag   = `[${tag}${attr}]`;
                const closeTag  = `[/${end}]`;
                textarea.value  = before + openTag + selected + closeTag + after;
                textarea.focus();
                const cs = start + openTag.length;
                textarea.selectionStart = cs;
                textarea.selectionEnd   = cs + selected.length;
            });
        });
    }

    // Init all visible toolbars on load
    document.querySelectorAll('.editor-toolbar').forEach(initToolbar);

    // ── Edit button: show edit box and init its toolbar lazily ───────────
    document.querySelectorAll('.edit-post-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const postId  = btn.dataset.postId;
            const postEl  = document.getElementById('post-body-' + postId);
            const editBox = document.getElementById('edit-box-'  + postId);
            if (!postEl || !editBox) return;
            postEl.style.display  = 'none';
            editBox.style.display = 'block';
            // Init toolbar now that the box is visible
            const tb = editBox.querySelector('.editor-toolbar');
            if (tb) initToolbar(tb);
            // Focus the textarea
            const ta = document.getElementById('edit-ta-' + postId);
            if (ta) { ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length; }
        });
    });

    // ── Cancel edit ──────────────────────────────────────────────────────
    document.querySelectorAll('.edit-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const postId  = btn.dataset.postId;
            const postEl  = document.getElementById('post-body-' + postId);
            const editBox = document.getElementById('edit-box-'  + postId);
            if (!postEl || !editBox) return;
            postEl.style.display  = '';
            editBox.style.display = 'none';
        });
    });

    // ── Save edit ────────────────────────────────────────────────────────
    document.querySelectorAll('.edit-save-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const postId    = btn.dataset.postId;
            // Read from the named textarea (edit-ta-{id}), not a generic querySelector
            const textarea  = document.getElementById('edit-ta-' + postId);
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!textarea) { showFlash('Edit box not found.', 'error'); return; }

            const newContent = textarea.value.trim();
            if (newContent.length < 5) { showFlash('Post must be at least 5 characters.', 'error'); return; }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'edit_post', post_id: postId, content: newContent, csrf: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    showFlash(data.error || 'Failed to save edit.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save';
                }
            } catch (err) {
                console.error('Edit error:', err);
                showFlash('Could not save — please try again.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
        });
    });

    // Get site URL and CSRF token from meta tags
    const SITE_URL = document.querySelector('meta[name="site-url"]')?.content || '';
    const API_URL  = SITE_URL + '/api.php';

    // Like buttons
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const postId   = btn.dataset.postId;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!postId) return;
            btn.disabled = true;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'like', post_id: postId, csrf: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    btn.classList.toggle('liked', data.liked);
                    const countEl = btn.querySelector('.like-count');
                    if (countEl) countEl.textContent = data.count;
                } else if (data.error) {
                    showFlash(data.error, 'error');
                }
            } catch (e) {
                console.error('Like error:', e);
                showFlash('Could not like post — please try again.', 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Edit, cancel, save handlers moved into initToolbar block above

    // Auto-dismiss alerts
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        }, 4000);
    });

    // Confirm dialogs
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    // Character counters
    document.querySelectorAll('[data-maxlength]').forEach(el => {
        const max = parseInt(el.dataset.maxlength);
        const counterId = el.dataset.counter;
        const counter = counterId ? document.getElementById(counterId) : null;
        if (counter) {
            el.addEventListener('input', () => {
                const left = max - el.value.length;
                counter.textContent = left;
                counter.style.color = left < 20 ? 'var(--red)' : 'var(--text-muted)';
            });
        }
    });

    // Tab system
    document.querySelectorAll('.tab-nav').forEach(nav => {
        const tabs = nav.querySelectorAll('.tab-btn');
        const panels = document.querySelectorAll('.tab-panel');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                const target = document.getElementById(tab.dataset.tab);
                if (target) target.classList.add('active');
            });
        });
    });

    // Animate stat numbers
    document.querySelectorAll('.stat-number[data-value]').forEach(el => {
        const target = parseInt(el.dataset.value);
        let current = 0;
        const step = Math.max(1, Math.floor(target / 60));
        const timer = setInterval(() => {
            current = Math.min(current + step, target);
            el.textContent = current.toLocaleString();
            if (current >= target) clearInterval(timer);
        }, 16);
    });

    // Highlight active nav link
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });

});

// Flash message helper
function showFlash(message, type = 'info') {
    const flash = document.createElement('div');
    flash.className = `alert alert-${type}`;
    flash.setAttribute('data-auto-dismiss', '');
    flash.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>${message}`;
    const container = document.querySelector('.container') || document.querySelector('.admin-content');
    if (container) container.prepend(flash);
    setTimeout(() => { flash.style.opacity = '0'; setTimeout(() => flash.remove(), 400); }, 4000);
}

// ============================================
// FiveM Server Status Fetcher
// ============================================

async function fetchServerStatus() {
    const heroBadge = document.getElementById('serverStatus');
    const sidebarWidget = document.getElementById('sidebarServerStatus');
    
    try {
        // Get base URL from current location
        const baseUrl = window.location.pathname.replace(/\/[^\/]*$/, '');
        const response = await fetch(`${baseUrl}/api/server-status.php`);
        const data = await response.json();
        
        // Update hero badge
        if (heroBadge) {
            const statusIndicator = heroBadge.querySelector('.status-indicator');
            const statusText = heroBadge.querySelector('.status-text');
            const playerCount = heroBadge.querySelector('.player-count');
            
            if (data.online) {
                heroBadge.classList.add('online');
                heroBadge.classList.remove('offline');
                statusText.textContent = 'Server Online';
                playerCount.textContent = `${data.players}/${data.maxPlayers} Players`;
            } else {
                heroBadge.classList.add('offline');
                heroBadge.classList.remove('online');
                statusText.textContent = 'Server Offline';
                playerCount.textContent = '';
            }
        }
        
        // Update sidebar widget
        if (sidebarWidget) {
            const statusEl = sidebarWidget.querySelector('.sidebar-server-status');
            const countEl = sidebarWidget.querySelector('.sidebar-player-count');
            const playerListEl = sidebarWidget.querySelector('.player-list-preview');
            
            if (statusEl) {
                if (data.online) {
                    statusEl.innerHTML = '<span style="color:var(--green)"><i class="fas fa-circle" style="font-size:0.5rem"></i> Online</span>';
                } else {
                    statusEl.innerHTML = '<span style="color:var(--red)"><i class="fas fa-circle" style="font-size:0.5rem"></i> Offline</span>';
                }
            }
            
            if (countEl) {
                countEl.textContent = data.online ? `${data.players}/${data.maxPlayers}` : '--/--';
                countEl.style.color = data.online ? 'var(--green)' : 'var(--text-muted)';
            }
            
            // Show player list preview if online and has players
            if (playerListEl && data.online && data.playerList && data.playerList.length > 0) {
                playerListEl.style.display = 'block';
                playerListEl.innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">Online Players:</div>' +
                    data.playerList.slice(0, 10).map(p => 
                        `<div style="font-size:0.8rem;padding:3px 0;color:var(--text-secondary)">${escapeHtml(p.name)}</div>`
                    ).join('') +
                    (data.playerList.length > 10 ? `<div style="font-size:0.75rem;color:var(--text-muted);padding-top:4px">+ ${data.playerList.length - 10} more...</div>` : '');
            } else if (playerListEl) {
                playerListEl.style.display = 'none';
            }
        }
        
        return data;
    } catch (error) {
        console.error('Failed to fetch server status:', error);
        
        // Show offline state on error
        if (heroBadge) {
            heroBadge.classList.add('offline');
            heroBadge.classList.remove('online');
            const statusText = heroBadge.querySelector('.status-text');
            const playerCount = heroBadge.querySelector('.player-count');
            if (statusText) statusText.textContent = 'Status Unknown';
            if (playerCount) playerCount.textContent = '';
        }
        
        return null;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fetch server status on page load and refresh every 30 seconds
document.addEventListener('DOMContentLoaded', () => {
    // Initial fetch
    if (document.getElementById('serverStatus') || document.getElementById('sidebarServerStatus')) {
        fetchServerStatus();
        
        // Refresh every 30 seconds
        setInterval(fetchServerStatus, 30000);
    }
});

// ================================================================
// LIVE NOTIFICATION + UNREAD COUNT POLLING
// ================================================================
(function() {
    const SITE_URL    = document.querySelector('meta[name="site-url"]')?.content || '';
    const API_URL     = SITE_URL + '/api.php';
    const csrfToken   = () => document.querySelector('meta[name="csrf-token"]')?.content;
    const notifBadge  = document.querySelector('.notif-badge:not(.msg-badge)');
    const msgBadge    = document.querySelector('.msg-badge');

    if (!notifBadge && !msgBadge) return; // not logged in

    let lastNotifCount = parseInt(notifBadge?.textContent) || 0;
    let lastMsgCount   = parseInt(msgBadge?.textContent)   || 0;

    async function pollCounts() {
        try {
            const r = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'poll_counts', csrf: csrfToken() })
            });
            const d = await r.json();
            if (!d.success) return;

            // Update notification badge
            if (notifBadge !== null) {
                const n = d.notifications;
                if (n > lastNotifCount) {
                    notifBadge.classList.add('bump');
                    setTimeout(() => notifBadge.classList.remove('bump'), 400);
                }
                lastNotifCount = n;
                notifBadge.textContent = n > 99 ? '99+' : n;
                notifBadge.style.display = n > 0 ? '' : 'none';
            }

            // Update messages badge
            if (msgBadge !== null) {
                const m = d.messages;
                if (m > lastMsgCount) {
                    msgBadge.classList.add('bump');
                    setTimeout(() => msgBadge.classList.remove('bump'), 400);
                }
                lastMsgCount = m;
                msgBadge.textContent = m > 99 ? '99+' : m;
                msgBadge.style.display = m > 0 ? '' : 'none';
            }
        } catch(e) { /* ignore */ }
    }

    // Poll every 30 seconds
    setInterval(pollCounts, 30000);
})();
