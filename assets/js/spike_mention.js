/* SPDX-License-Identifier: GPL-3.0-only */
/**
 * SPIKE MENTION AUTOCOMPLETE
 *
 * Usage:
 *   const mentioner = new SpikeMentioner(quillInstance, {
 *       myId:        <?= $myId ?>,
 *       searchUrl:   'index.php?p=spike_mention_search',
 *       csrfToken:   '<?= $csrf_token ?>',
 *   });
 *
 * The module:
 * - Watches for @ in the Quill editor
 * - Displays a dropdown with user suggestions
 * - Inserts <span class="spk-mention" data-uid="X">@Username</span>
 * - Applies .spk-mention--me to the current user
 */
class SpikeMentioner {
    constructor(quill, options = {}) {
        this.quill      = quill;
        this.myId       = options.myId       || 0;
        this.searchUrl  = options.searchUrl  || 'index.php?p=spike_mention_search';
        this.csrfToken  = options.csrfToken  || '';
        this.minChars   = options.minChars   || 1;

        this._dropdown  = null;
        this._query     = '';
        this._atIndex   = -1;
        this._active    = -1;
        this._results   = [];
        this._timer     = null;
        this._open      = false;

        this._buildDropdown();
        this._bindQuill();
        this._bindGlobal();
    }

    // ── Build dropdown DOM ──────────────────────────────────
    _buildDropdown() {
        this._dropdown = document.createElement('div');
        this._dropdown.className = 'spk-mention-dropdown hidden';
        this._dropdown.setAttribute('role', 'listbox');
        document.body.appendChild(this._dropdown);
    }

    // ── Quill Events ──────────────────────────────────────────
    _bindQuill() {
        this.quill.on('text-change', () => this._onTextChange());
        this.quill.root.addEventListener('keydown', (e) => this._onKeyDown(e));
    }

    _bindGlobal() {
        document.addEventListener('click', (e) => {
            if (!this._dropdown.contains(e.target)) this._hide();
        });
        window.addEventListener('scroll', () => this._hide(), { passive: true });
        window.addEventListener('resize', () => this._hide(), { passive: true });
    }

    // ── Process text changes ─────────────────────────────
    _onTextChange() {
        const sel = this.quill.getSelection();
        if (!sel) return;

        const text  = this.quill.getText(0, sel.index);
        const match = text.match(/@([\w\u00C0-\u017E]*)$/);

        if (!match) { this._hide(); return; }

        this._query   = match[1];
        this._atIndex = sel.index - match[0].length;

        if (this._query.length < this.minChars) {
            this._showLoading();
            return;
        }

        clearTimeout(this._timer);
        this._timer = setTimeout(() => this._fetch(this._query), 220);
    }

    // ── Keyboard Navigation ───────────────────────────────────
    _onKeyDown(e) {
        if (!this._open) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this._active = Math.min(this._active + 1, this._results.length - 1);
                this._renderItems();
                break;
            case 'ArrowUp':
                e.preventDefault();
                this._active = Math.max(this._active - 1, 0);
                this._renderItems();
                break;
            case 'Enter':
            case 'Tab':
                if (this._results[this._active]) {
                    e.preventDefault();
                    this._insert(this._results[this._active]);
                }
                break;
            case 'Escape':
                e.preventDefault();
                this._hide();
                break;
        }
    }

    // ── User search ────────────────────────────────────────────
    async _fetch(q) {
        this._showLoading();
        try {
            const url = `${this.searchUrl}&q=${encodeURIComponent(q)}&exclude=${this.myId}`;
            const res = await fetch(url);
            const data = await res.json();

            if (!data.ok || !data.users?.length) {
                this._showEmpty();
                return;
            }
            this._results = data.users;
            this._active  = 0;
            this._renderItems();
        } catch (err) {
            this._hide();
        }
    }

    // ── Show or hide the dropdown ────────────────────────
    _showLoading() {
        this._dropdown.innerHTML = '<div class="spk-mention-loading">Searching…</div>';
        this._open = true;
        this._dropdown.classList.remove('hidden');
        this._position();
    }

    _showEmpty() {
        this._dropdown.innerHTML = '<div class="spk-mention-empty">No users found</div>';
        this._open = true;
        this._dropdown.classList.remove('hidden');
        this._position();
    }

    _hide() {
        this._open    = false;
        this._results = [];
        this._active  = -1;
        this._dropdown.classList.add('hidden');
    }

    // ── Render items ─────────────────────────────────────────
    _renderItems() {
        const q = this._query.toLowerCase();
        this._dropdown.innerHTML = this._results.map((u, i) => {
            const name    = this._escHtml(u.username);
            const nameHL  = name.replace(
                new RegExp('^(' + this._escRegex(q) + ')', 'i'),
                '<mark>$1</mark>'
            );
            const avatar  = u.avatar_url
                ? `<img class="spk-mention-avatar" src="${this._escHtml(u.avatar_url)}" alt="" loading="lazy" onerror="this.style.display='none'">`
                : `<div class="spk-mention-avatar-placeholder">${name.charAt(0).toUpperCase()}</div>`;
            const posts   = u.post_count > 0 ? `<span class="spk-mention-posts">${u.post_count}</span>` : '';
            const active  = i === this._active ? ' active' : '';

            return `<div class="spk-mention-item${active}" role="option" data-idx="${i}">
                ${avatar}
                <span class="spk-mention-name">${nameHL}</span>
                ${posts}
            </div>`;
        }).join('');

        // Click handler
        this._dropdown.querySelectorAll('.spk-mention-item').forEach(el => {
            el.addEventListener('mouseenter', () => {
                this._active = parseInt(el.dataset.idx);
                this._renderItems();
            });
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this._insert(this._results[parseInt(el.dataset.idx)]);
            });
        });

        this._open = true;
        this._dropdown.classList.remove('hidden');
        this._position();
    }

    // ── Position the dropdown ────────────────────────────────
    _position() {
        const sel = this.quill.getSelection();
        if (!sel) return;

        try {
            const bounds = this.quill.getBounds(this._atIndex);
            const editorRect = this.quill.root.getBoundingClientRect();

            const top  = editorRect.top  + bounds.bottom + window.scrollY + 4;
            const left = editorRect.left + bounds.left   + window.scrollX;

            // Keep the dropdown inside the viewport
            const dropW = 260;
            const maxLeft = window.innerWidth - dropW - 10;

            this._dropdown.style.top  = `${top}px`;
            this._dropdown.style.left = `${Math.min(left, maxLeft)}px`;
        } catch (e) {}
    }

    // ── Insert mention ──────────────────────────────────────
    _insert(user) {
        if (!user) return;

        const sel = this.quill.getSelection();
        if (sel === null) return;

        // Remove the query including the @ character
        const deleteLen = this._query.length + 1; // Include the @ character
        const insertAt  = this._atIndex;

        this.quill.deleteText(insertAt, deleteLen);

        // Insert the mention as an embed blot
        // Quill has no native mention blot, so use a custom embed.
        // Fall back to inserting text and HTML through the Quill clipboard.
        const mentionHtml = `<span class="spk-mention${user.id == this.myId ? ' spk-mention--me' : ''}" data-uid="${user.id}" contenteditable="false">@${user.username}</span>&nbsp;`;

        // Insert the HTML at the correct position through the Quill clipboard.
        this.quill.clipboard.dangerouslyPasteHTML(insertAt, mentionHtml);

        // Move the cursor to the end of the inserted mention.
        setTimeout(() => {
            const newPos = insertAt + user.username.length + 2; // @name plus trailing space
            this.quill.setSelection(newPos, 0);
        }, 10);

        this._hide();
    }

    // ── Utilities ─────────────────────────────────────────────
    _escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    _escRegex(s) {
        return String(s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}

// ── Highlight mentions in post content ─────────────────────
/**
 * Process every .spk-mention span on the page:
 * - highlight mentions for the current user with the --me class
 * - link mentions to user profiles
 */
function spkInitMentionDisplay(myId) {
    document.querySelectorAll('.spk-mention[data-uid]').forEach(el => {
        const uid = parseInt(el.dataset.uid || 0);
        if (uid === myId && myId > 0) {
            el.classList.add('spk-mention--me');
        }
        // Profile link
        el.style.cursor = 'pointer';
        el.addEventListener('click', function(e) {
            e.stopPropagation();
            window.location.href = `index.php?p=profile&id=${uid}`;
        });
    });
}

// ── Notification Bell ─────────────────────────────────────────
class SpkNotifBell {
    constructor(options = {}) {
        this.userId      = options.userId      || 0;
        this.fetchUrl    = options.fetchUrl    || 'index.php?p=spike_notifications';
        this.markReadUrl = options.markReadUrl || 'index.php?p=spike_notifications&action=mark_read';
        this.csrfToken   = options.csrfToken   || '';
        this.pollInterval = options.pollInterval || 60000; // One minute

        this._bell      = document.getElementById('spk-notif-bell');
        this._count     = document.getElementById('spk-notif-count');
        this._dropdown  = document.getElementById('spk-notif-dropdown');

        if (!this._bell || !this.userId) return;

        this._bell.addEventListener('click', (e) => {
            e.stopPropagation();
            this._toggle();
        });

        document.addEventListener('click', () => this._close());

        this._poll();
        setInterval(() => this._poll(), this.pollInterval);
    }

    async _poll() {
        if (!this.userId) return;
        try {
            const res  = await fetch(`${this.fetchUrl}&json=1&uid=${this.userId}`);
            const data = await res.json();
            if (data.unread !== undefined) this._updateCount(data.unread);
        } catch (e) {}
    }

    _updateCount(n) {
        if (!this._count) return;
        this._count.textContent = n > 0 ? (n > 99 ? '99+' : n) : '';
        this._count.dataset.count = n;
        this._bell?.classList.toggle('has-unread', n > 0);
    }

    async _toggle() {
        if (!this._dropdown) return;
        const isOpen = this._dropdown.classList.contains('open');
        if (isOpen) { this._close(); return; }

        this._dropdown.innerHTML = '<div class="spk-notif-empty">Loading…</div>';
        this._dropdown.classList.add('open');

        try {
            const res  = await fetch(`${this.fetchUrl}&json=1&full=1&uid=${this.userId}`);
            const data = await res.json();
            this._render(data.notifications || []);
            if (data.unread > 0) {
                // Mark all notifications as read when the dropdown opens.
                this._markAllRead();
            }
        } catch (e) {
            this._dropdown.innerHTML = '<div class="spk-notif-empty">Error loading</div>';
        }
    }

    _close() {
        this._dropdown?.classList.remove('open');
    }

    _render(notifications) {
        if (!this._dropdown) return;
        if (!notifications.length) {
            this._dropdown.innerHTML = `
                <div class="spk-notif-header"><span>Notifications</span></div>
                <div class="spk-notif-empty">No notifications yet</div>`;
            return;
        }

        const items = notifications.map(n => {
            const icon   = this._icon(n.type);
            const text   = this._text(n);
            const thread = n.thread_title ? `<div class="spk-notif-thread">${this._esc(n.thread_title)}</div>` : '';
            const time   = this._relTime(n.created_at);
            const url    = n.thread_slug
                ? `index.php?p=viewthread&slug=${encodeURIComponent(n.thread_slug)}${n.post_id ? '#post-'+n.post_id : ''}`
                : '#';
            const unread = n.is_read == 0 ? ' unread' : '';
            return `<a href="${url}" class="spk-notif-item${unread}">
                <div class="spk-notif-icon spk-notif-icon--${this._esc(n.type)}">${icon}</div>
                <div class="spk-notif-body">
                    <div class="spk-notif-text">${text}</div>
                    ${thread}
                </div>
                <span class="spk-notif-time">${time}</span>
            </a>`;
        }).join('');

        this._dropdown.innerHTML = `
            <div class="spk-notif-header">
                <span>Notifications</span>
                <button class="spk-notif-mark-all" onclick="window.spkBell._markAllRead()">Mark all read</button>
            </div>
            ${items}`;
    }

    _icon(type) {
        const icons = { mention: '<i class="fas fa-at"></i>', reply: '<i class="fas fa-reply"></i>', system: '<i class="fas fa-info"></i>' };
        return icons[type] || icons.system;
    }

    _text(n) {
        const actor = `<strong>${this._esc(n.actor_name || 'Someone')}</strong>`;
        const types = {
            mention: `${actor} mentioned you`,
            reply:   `${actor} replied to your post`,
            system:  this._esc(n.message || 'System notification'),
        };
        return types[n.type] || types.system;
    }

    _relTime(dateStr) {
        if (!dateStr) return '';
        const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
        if (diff < 60)   return 'now';
        if (diff < 3600) return Math.floor(diff/60)+'m';
        if (diff < 86400) return Math.floor(diff/3600)+'h';
        return Math.floor(diff/86400)+'d';
    }

    async _markAllRead() {
        this._updateCount(0);
        this._dropdown?.querySelectorAll('.spk-notif-item.unread').forEach(el => el.classList.remove('unread'));
        try {
            const fd = new FormData();
            fd.append('csrf_token', this.csrfToken);
            fd.append('action', 'mark_all_read');
            await fetch(this.markReadUrl, { method: 'POST', body: fd });
        } catch (e) {}
    }

    _esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
}

// Initialize automatically when the DOM is ready.
document.addEventListener('DOMContentLoaded', function() {
    // Highlight mentions on the page.
    if (typeof SPK_MY_ID !== 'undefined') {
        spkInitMentionDisplay(SPK_MY_ID);
    }
    // Notification Bell
    if (typeof SPK_NOTIF_OPTIONS !== 'undefined') {
        window.spkBell = new SpkNotifBell(SPK_NOTIF_OPTIONS);
    }
});
