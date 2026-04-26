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

    // Copy post link buttons
    document.querySelectorAll('.copy-link-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.postUrl;
            if (!url) return;
            
            try {
                await navigator.clipboard.writeText(url);
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.style.color = 'var(--green)';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.color = '';
                }, 2000);
            } catch (e) {
                // Fallback for older browsers
                const input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.style.color = 'var(--green)';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.color = '';
                }, 2000);
            }
        });
    });

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
// LIVE UPDATES SYSTEM
// ================================================================
(function() {
    const SITE_URL    = document.querySelector('meta[name="site-url"]')?.content || '';
    const API_URL     = SITE_URL + '/api.php';
    const csrfToken   = () => document.querySelector('meta[name="csrf-token"]')?.content;
    const notifBadge  = document.querySelector('.notif-badge:not(.msg-badge)');
    const msgBadge    = document.querySelector('.msg-badge');

    // Track shown notifications to avoid duplicates
    let shownNotificationIds = new Set();
    let lastNotifCount = parseInt(notifBadge?.textContent) || 0;
    let lastMsgCount   = parseInt(msgBadge?.textContent)   || 0;

    // ════════════════════════════════════════════════════════════════
    // TOAST NOTIFICATION SYSTEM
    // ════════════════════════════════════════════════════════════════
    function createToastContainer() {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }
        return container;
    }

    function showToast(title, content, type = 'info', link = null, notifId = null) {
        const container = createToastContainer();
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            'thread_reply': 'fa-reply',
            'like': 'fa-heart',
            'mention': 'fa-at',
            'message': 'fa-envelope',
            'info': 'fa-info-circle',
            'warning': 'fa-exclamation-triangle',
            'error': 'fa-times-circle',
            'success': 'fa-check-circle'
        };
        const icon = icons[type] || icons['info'];
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas ${icon}"></i></div>
            <div class="toast-content">
                <div class="toast-title">${escapeHtml(title)}</div>
                ${content ? `<div class="toast-body">${escapeHtml(content).substring(0, 100)}${content.length > 100 ? '...' : ''}</div>` : ''}
            </div>
            <button class="toast-close"><i class="fas fa-times"></i></button>
        `;
        
        // Click to go to link
        if (link) {
            toast.style.cursor = 'pointer';
            toast.addEventListener('click', (e) => {
                if (!e.target.closest('.toast-close')) {
                    // Mark as read if we have notifId
                    if (notifId) {
                        fetch(API_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'mark_notification_read', notification_id: notifId, csrf: csrfToken() })
                        });
                    }
                    window.location.href = link;
                }
            });
        }
        
        // Close button
        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.classList.add('toast-hiding');
            setTimeout(() => toast.remove(), 300);
        });
        
        container.appendChild(toast);
        
        // Animate in
        requestAnimationFrame(() => toast.classList.add('toast-visible'));
        
        // Auto dismiss after 6 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.add('toast-hiding');
                setTimeout(() => toast.remove(), 300);
            }
        }, 6000);
    }

    // ════════════════════════════════════════════════════════════════
    // BAN/DELETION DETECTION MODAL
    // ════════════════════════════════════════════════════════════════
    function showBanModal(data) {
        // Remove any existing modal
        const existing = document.getElementById('ban-modal');
        if (existing) existing.remove();
        
        const modal = document.createElement('div');
        modal.id = 'ban-modal';
        modal.className = 'ban-modal-overlay';
        
        let content = '';
        if (data.status === 'deleted') {
            content = `
                <div class="ban-modal-icon deleted"><i class="fas fa-user-slash"></i></div>
                <h2>Account Deleted</h2>
                <p>Your account has been deleted from LAE Forums.</p>
                <p class="ban-modal-sub">If you believe this was a mistake, please contact the administration.</p>
            `;
        } else if (data.status === 'banned') {
            content = `
                <div class="ban-modal-icon banned"><i class="fas fa-ban"></i></div>
                <h2>Account Banned</h2>
                <p>Your account has been banned from LAE Forums.</p>
                ${data.reason ? `<div class="ban-modal-reason"><strong>Reason:</strong> ${escapeHtml(data.reason)}</div>` : ''}
                ${data.is_permanent 
                    ? '<div class="ban-modal-expiry permanent"><i class="fas fa-infinity"></i> Permanent Ban</div>'
                    : `<div class="ban-modal-expiry"><i class="fas fa-clock"></i> Expires: ${escapeHtml(data.expires_formatted)}</div>`
                }
                <p class="ban-modal-sub">You may submit a ban appeal after logging out.</p>
            `;
        }
        
        modal.innerHTML = `
            <div class="ban-modal-box">
                ${content}
                <button class="btn btn-accent ban-modal-btn" onclick="window.location.href='${SITE_URL}/logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Log Out
                </button>
            </div>
        `;
        
        document.body.appendChild(modal);
        requestAnimationFrame(() => modal.classList.add('visible'));
    }

    // ════════════════════════════════════════════════════════════════
    // POLL COUNTS + SESSION CHECK
    // ════════════════════════════════════════════════════════════════
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
            
            // Show toast for new notifications
            if (d.latest_notifications && d.latest_notifications.length > 0) {
                d.latest_notifications.forEach(notif => {
                    if (!shownNotificationIds.has(notif.id)) {
                        shownNotificationIds.add(notif.id);
                        showToast(notif.title, notif.content, notif.type, notif.link, notif.id);
                    }
                });
            }
        } catch(e) { /* ignore */ }
    }

    async function checkSession() {
        try {
            const r = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'check_session' })
            });
            const d = await r.json();
            
            if (d.success) {
                if (d.status === 'banned' || d.status === 'deleted') {
                    showBanModal(d);
                } else if (d.status === 'logged_out' && (notifBadge || msgBadge)) {
                    // Was logged in but session expired
                    window.location.href = SITE_URL + '/login.php?expired=1';
                }
            }
        } catch(e) { /* ignore */ }
    }

    // Only run if user appears to be logged in
    if (notifBadge || msgBadge) {
        // Poll counts every 30 seconds
        setInterval(pollCounts, 30000);
        
        // Check session every 30 seconds
        setInterval(checkSession, 30000);
        
        // Initial session check after 5 seconds (give page time to load)
        setTimeout(checkSession, 5000);
    }

    // ════════════════════════════════════════════════════════════════
    // LIVE THREAD UPDATES (for thread.php pages)
    // ════════════════════════════════════════════════════════════════
    const threadMeta = document.querySelector('meta[name="thread-id"]');
    if (threadMeta) {
        const threadId = parseInt(threadMeta.content);
        let lastPostId = 0;
        let newPostCount = 0;
        
        // Find the last post ID on the page
        const postElements = document.querySelectorAll('.post-wrapper[id^="post-"]');
        if (postElements.length > 0) {
            const lastPost = postElements[postElements.length - 1];
            lastPostId = parseInt(lastPost.id.replace('post-', ''));
        }
        
        // Create the "new replies" banner
        const newRepliesBanner = document.createElement('div');
        newRepliesBanner.id = 'new-replies-banner';
        newRepliesBanner.className = 'new-replies-banner';
        newRepliesBanner.innerHTML = `
            <span class="nrb-text"><i class="fas fa-arrow-down"></i> <span class="nrb-count">0</span> new replies</span>
            <button class="nrb-btn">Load New Replies</button>
        `;
        newRepliesBanner.style.display = 'none';
        
        // Insert banner after thread header
        const threadHeader = document.querySelector('.thread-header-bar');
        if (threadHeader) {
            threadHeader.parentNode.insertBefore(newRepliesBanner, threadHeader.nextSibling);
        }
        
        // Load new replies button handler
        newRepliesBanner.querySelector('.nrb-btn').addEventListener('click', async () => {
            try {
                const r = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_new_posts', thread_id: threadId, last_post_id: lastPostId, csrf: csrfToken() })
                });
                const d = await r.json();
                
                if (d.success && d.posts && d.posts.length > 0) {
                    // For simplicity, reload the page to show new posts
                    // In a more sophisticated implementation, we could inject posts dynamically
                    window.location.reload();
                }
            } catch(e) {
                showFlash('Failed to load new replies', 'error');
            }
        });
        
        async function pollThread() {
            try {
                const r = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'poll_thread', thread_id: threadId, last_post_id: lastPostId, csrf: csrfToken() })
                });
                const d = await r.json();
                
                if (d.success) {
                    // Update reply count display
                    const replyCountEl = document.getElementById('live-reply-count');
                    if (replyCountEl) {
                        replyCountEl.textContent = d.reply_count.toLocaleString();
                    }
                    
                    // Show new replies banner if there are new posts
                    if (d.new_post_count > 0) {
                        newPostCount = d.new_post_count;
                        newRepliesBanner.querySelector('.nrb-count').textContent = newPostCount;
                        newRepliesBanner.querySelector('.nrb-text').innerHTML = 
                            `<i class="fas fa-arrow-down"></i> ${newPostCount} new ${newPostCount === 1 ? 'reply' : 'replies'}`;
                        newRepliesBanner.style.display = 'flex';
                        newRepliesBanner.classList.add('nrb-pulse');
                        setTimeout(() => newRepliesBanner.classList.remove('nrb-pulse'), 500);
                    }
                    
                    // Show lock status change
                    const lockTag = document.querySelector('.thread-tag.tag-locked');
                    if (d.is_locked && !lockTag) {
                        showToast('Thread Locked', 'This thread has been locked by a moderator.', 'warning');
                    }
                }
            } catch(e) { /* ignore */ }
        }
        
        // Poll thread every 15 seconds
        setInterval(pollThread, 15000);
    }

    // ════════════════════════════════════════════════════════════════
    // KEYBOARD SHORTCUTS
    // ════════════════════════════════════════════════════════════════
    document.addEventListener('keydown', (e) => {
        // Ctrl+Enter to submit forms
        if (e.ctrlKey && e.key === 'Enter') {
            const activeEl = document.activeElement;
            if (activeEl && activeEl.tagName === 'TEXTAREA') {
                const form = activeEl.closest('form');
                if (form) {
                    e.preventDefault();
                    form.submit();
                }
            }
        }
    });

})();
