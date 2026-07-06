/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — notif-bell.js
   Self-contained customer notification bell for the navbar.

   Injects a bell + badge + dropdown into the existing #nav-auth
   container on any customer page. No markup or CSS changes needed
   on the host page — just include this script after config.js:

     <script src="js/config.js"></script>
     <script src="js/notif-bell.js"></script>

   Uses the existing customer notification API:
     GET  /api/customer/notifications
     GET  /api/customer/notifications/unread-count
     PUT  /api/customer/notifications/{id}/read
     PUT  /api/customer/notifications/read-all
═══════════════════════════════════════════════════════════════ */
'use strict';

(function initNotifBell() {
  // Only for logged-in customers, and only where the auth navbar exists
  if (typeof getStoredUser !== 'function' || !getStoredUser()) return;

  document.addEventListener('DOMContentLoaded', () => {
    const host = document.getElementById('nav-auth');
    if (!host || document.getElementById('lfp-bell')) return;

    injectStyles();

    // ── Build bell ────────────────────────────────────────────────
    const wrap = document.createElement('div');
    wrap.className = 'lfp-bell-wrap';
    wrap.innerHTML = `
      <button class="lfp-bell" id="lfp-bell" aria-label="Notifications" aria-expanded="false">
        <i class="fas fa-bell"></i>
        <span class="lfp-bell-badge" id="lfp-bell-badge" hidden>0</span>
      </button>
      <div class="lfp-bell-dd" id="lfp-bell-dd" hidden>
        <div class="lfp-bell-head">
          <span>Notifications</span>
          <button class="lfp-bell-readall" id="lfp-bell-readall">Mark all read</button>
        </div>
        <div class="lfp-bell-list" id="lfp-bell-list">
          <div class="lfp-bell-empty">Loading…</div>
        </div>
        <a class="lfp-bell-foot" href="notifications.html">View all notifications</a>
      </div>`;
    // Place the bell first inside the auth cluster
    host.insertBefore(wrap, host.firstChild);

    const bell   = document.getElementById('lfp-bell');
    const dd     = document.getElementById('lfp-bell-dd');

    bell.addEventListener('click', e => {
      e.stopPropagation();
      const open = dd.hasAttribute('hidden');
      if (open) openDropdown(); else closeDropdown();
    });

    document.addEventListener('click', e => {
      if (!wrap.contains(e.target)) closeDropdown();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDropdown(); });

    document.getElementById('lfp-bell-readall').addEventListener('click', e => {
      e.stopPropagation();
      markAllRead();
    });

    // Initial badge + periodic refresh (every 30s)
    refreshCount();
    setInterval(refreshCount, 30000);

    function openDropdown() {
      dd.removeAttribute('hidden');
      bell.setAttribute('aria-expanded', 'true');
      loadList();
    }
    function closeDropdown() {
      dd.setAttribute('hidden', '');
      bell.setAttribute('aria-expanded', 'false');
    }
  });

  // ── API helpers ────────────────────────────────────────────────
  async function refreshCount() {
    try {
      const res  = await fetch(`${API_BASE}/api/customer/notifications/unread-count`, { credentials: 'include' });
      const data = await res.json();
      if (data.success) setBadge(data.data.unread_count);
    } catch { /* ignore */ }
  }

  async function loadList() {
    const list = document.getElementById('lfp-bell-list');
    try {
      const res  = await fetch(`${API_BASE}/api/customer/notifications`, { credentials: 'include' });
      const data = await res.json();
      if (!data.success) throw new Error();

      const items = data.data.notifications || [];
      setBadge(data.data.unread_count);

      if (!items.length) {
        list.innerHTML = `<div class="lfp-bell-empty">You're all caught up 🎉</div>`;
        return;
      }

      list.innerHTML = items.slice(0, 15).map(n => `
        <button class="lfp-bell-item ${n.read ? '' : 'unread'}" data-id="${n.id}">
          <span class="lfp-bell-dot"></span>
          <span class="lfp-bell-body">
            <span class="lfp-bell-title">${escHtml(n.subject || 'Notification')}</span>
            <span class="lfp-bell-msg">${escHtml(n.message || '')}</span>
            <span class="lfp-bell-time">${timeAgo(n.created_at)}</span>
          </span>
        </button>`).join('');

      list.querySelectorAll('.lfp-bell-item').forEach(el => {
        el.addEventListener('click', () => {
          const id = parseInt(el.dataset.id, 10);
          if (el.classList.contains('unread')) markRead(id, el);
        });
      });
    } catch {
      list.innerHTML = `<div class="lfp-bell-empty">Couldn't load notifications.</div>`;
    }
  }

  async function markRead(id, el) {
    try {
      await fetch(`${API_BASE}/api/customer/notifications/${id}/read`, {
        method: 'PUT', credentials: 'include',
        headers: { 'X-CSRF-Token': getCsrfToken() },
      });
      el?.classList.remove('unread');
      refreshCount();
    } catch { /* ignore */ }
  }

  async function markAllRead() {
    try {
      await fetch(`${API_BASE}/api/customer/notifications/read-all`, {
        method: 'PUT', credentials: 'include',
        headers: { 'X-CSRF-Token': getCsrfToken() },
      });
      document.querySelectorAll('.lfp-bell-item.unread').forEach(el => el.classList.remove('unread'));
      setBadge(0);
    } catch { /* ignore */ }
  }

  // ── UI helpers ─────────────────────────────────────────────────
  function setBadge(count) {
    const badge = document.getElementById('lfp-bell-badge');
    if (!badge) return;
    const n = Number(count) || 0;
    if (n > 0) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(String(dateStr).replace(' ', 'T'));
    const s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (isNaN(s)) return '';
    if (s < 60)    return 'just now';
    if (s < 3600)  return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    if (s < 604800) return `${Math.floor(s / 86400)}d ago`;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  }

  // ── Scoped styles (injected once) ──────────────────────────────
  function injectStyles() {
    if (document.getElementById('lfp-bell-styles')) return;
    const css = `
    .lfp-bell-wrap{position:relative;display:flex;align-items:center;}
    .lfp-bell{position:relative;background:transparent;border:none;cursor:pointer;
      width:42px;height:42px;border-radius:50%;color:#334155;font-size:1.15rem;
      display:flex;align-items:center;justify-content:center;transition:background .15s;}
    .lfp-bell:hover{background:rgba(37,99,235,.10);color:#2563eb;}
    .lfp-bell-badge{position:absolute;top:4px;right:4px;min-width:18px;height:18px;
      padding:0 5px;border-radius:9px;background:#ef4444;color:#fff;font-size:11px;
      font-weight:700;line-height:18px;text-align:center;box-shadow:0 0 0 2px #fff;}
    .lfp-bell-dd{position:absolute;top:52px;right:0;width:360px;max-width:92vw;background:#fff;
      border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 18px 50px rgba(2,6,23,.18);
      z-index:1000;overflow:hidden;animation:lfpDd .14s ease;}
    @keyframes lfpDd{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
    .lfp-bell-head{display:flex;align-items:center;justify-content:space-between;
      padding:14px 16px;border-bottom:1px solid #f1f5f9;font-weight:700;color:#0f172a;}
    .lfp-bell-readall{background:none;border:none;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer;}
    .lfp-bell-readall:hover{text-decoration:underline;}
    .lfp-bell-list{max-height:380px;overflow-y:auto;}
    .lfp-bell-empty{padding:34px 16px;text-align:center;color:#94a3b8;font-size:14px;}
    .lfp-bell-item{display:flex;gap:10px;width:100%;text-align:left;background:none;border:none;
      cursor:pointer;padding:13px 16px;border-bottom:1px solid #f5f7fa;transition:background .12s;}
    .lfp-bell-item:hover{background:#f8fafc;}
    .lfp-bell-item.unread{background:#eff6ff;}
    .lfp-bell-dot{flex:0 0 auto;width:8px;height:8px;margin-top:6px;border-radius:50%;background:transparent;}
    .lfp-bell-item.unread .lfp-bell-dot{background:#2563eb;}
    .lfp-bell-body{display:flex;flex-direction:column;gap:2px;min-width:0;}
    .lfp-bell-title{font-weight:600;font-size:13.5px;color:#0f172a;}
    .lfp-bell-msg{font-size:12.5px;color:#64748b;line-height:1.4;}
    .lfp-bell-time{font-size:11px;color:#94a3b8;margin-top:2px;}
    .lfp-bell-foot{display:block;text-align:center;padding:12px;font-size:13px;font-weight:600;
      color:#2563eb;text-decoration:none;border-top:1px solid #f1f5f9;}
    .lfp-bell-foot:hover{background:#f8fafc;}`;
    const style = document.createElement('style');
    style.id = 'lfp-bell-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }
})();
