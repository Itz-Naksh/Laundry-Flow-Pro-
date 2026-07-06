'use strict';
/* ── Customer Notification Center ── */

(function () {
  // Guard: must be logged in as a customer
  const user = getStoredUser();
  if (!user) { window.location.replace('login.html'); return; }

  let CACHE  = [];
  let filter = 'all';

  const TYPE_META = {
    welcome:  { icon: '👋', cls: 'welcome'  },
    'order.placed':         { icon: '🧺', cls: 'order'  },
    'order.schedule':       { icon: '📅', cls: 'order'  },
    'order.confirm':        { icon: '✅', cls: 'order'  },
    'order.collected':      { icon: '🚗', cls: 'order'  },
    'order.processing':     { icon: '⚙️', cls: 'order'  },
    'order.ready':          { icon: '✨', cls: 'order'  },
    'order.out_for_delivery':{ icon:'🚚', cls: 'order'  },
    'order.delivered':      { icon: '🎉', cls: 'order'  },
    'order.canceled':       { icon: '❌', cls: 'order'  },
    promo:                  { icon: '🎟️', cls: 'promo'  },
    'review.request':       { icon: '⭐', cls: 'review' },
    system:                 { icon: '⚙️', cls: 'system' },
  };

  function typeMeta(type) {
    // Match on full type first, then prefix
    if (TYPE_META[type]) return TYPE_META[type];
    const prefix = Object.keys(TYPE_META).find(k => type.startsWith(k));
    return prefix ? TYPE_META[prefix] : { icon: '🔔', cls: 'system' };
  }

  // ── API helper ──────────────────────────────────────────────────────────────
  async function api(path, opts = {}) {
    const res = await fetch(API_BASE + path, {
      credentials: 'include',
      headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
      ...opts,
    });
    if (res.status === 401) { clearSession(); window.location.replace('login.html'); return null; }
    return res.json();
  }

  // ── Load & render ────────────────────────────────────────────────────────────
  async function load() {
    const list = document.getElementById('notifList');
    list.innerHTML = '<div class="loading-notif"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';

    const j = await api(`/api/customer/notifications?filter=${filter}`);
    if (!j || !j.success) {
      list.innerHTML = '<div class="loading-notif" style="color:#ef4444;">Failed to load notifications.</div>';
      return;
    }

    CACHE = j.data.notifications || [];
    updateBadge(j.data.unread_count || 0);
    render(CACHE);
  }

  function render(items) {
    const list = document.getElementById('notifList');
    if (!items.length) {
      list.innerHTML = `
        <div class="empty-notif">
          <i class="fas fa-bell-slash"></i>
          <p>No notifications yet.</p>
        </div>`;
      return;
    }

    list.innerHTML = items.map(n => {
      const m    = typeMeta(n.type);
      const read = n.read;
      return `
        <div class="notif-card${read ? '' : ' unread'}" id="nc-${n.id}" onclick="markRead(${n.id})">
          <div class="notif-icon ${m.cls}">${m.icon}</div>
          <div class="notif-body">
            <div class="notif-subject">${esc(n.subject)}</div>
            <div class="notif-msg">${esc(n.message)}</div>
            <div class="notif-meta">
              ${!read ? '<span class="unread-dot"></span>' : ''}
              <span class="notif-time">${timeAgo(n.created_at)}</span>
            </div>
          </div>
          <div class="notif-actions">
            <button class="del-btn" onclick="event.stopPropagation();deleteNotif(${n.id})" title="Delete">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>
        </div>`;
    }).join('');
  }

  function updateBadge(count) {
    const badge = document.getElementById('unread-badge');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.style.display = 'inline-flex';
    } else {
      badge.style.display = 'none';
    }
  }

  // ── Actions ─────────────────────────────────────────────────────────────────
  window.markRead = async function (id) {
    const n = CACHE.find(x => x.id === id);
    if (n && n.read) return;
    await api(`/api/customer/notifications/${id}/read`, { method: 'PUT' });
    load();
  };

  window.markAllRead = async function () {
    const j = await api('/api/customer/notifications/read-all', { method: 'PUT' });
    if (j && j.success) { toast('All notifications marked as read', 'success'); load(); }
    else toast('Failed to mark all read', 'error');
  };

  window.deleteNotif = async function (id) {
    const j = await api(`/api/customer/notifications/${id}`, { method: 'DELETE' });
    if (j && j.success) { toast('Notification deleted', 'success'); load(); }
    else toast('Failed to delete', 'error');
  };

  // ── Filter chips ─────────────────────────────────────────────────────────────
  document.querySelectorAll('#filterRow .f-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#filterRow .f-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      filter = chip.dataset.filter;
      load();
    });
  });

  // ── Logout ────────────────────────────────────────────────────────────────────
  const logoutBtn = document.getElementById('nav-logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      await api('/api/auth/logout', { method: 'POST' });
      clearSession();
      window.location.replace('login.html');
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)   return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString();
  }

  function toast(msg, type = 'success') {
    const box = document.getElementById('toast-box');
    const el  = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  load();

  // Poll unread count every 60 s
  setInterval(async () => {
    const j = await api('/api/customer/notifications/unread-count');
    if (j && j.success) updateBadge(j.data.unread_count);
  }, 60000);
})();
