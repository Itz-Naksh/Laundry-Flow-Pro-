/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — admin.js
   Shared utilities for ALL admin pages.
   Loaded first on every admin page (before page-specific scripts).
═══════════════════════════════════════════════════════════════ */

'use strict';

// ── API base URL (mirrors Customer/js/config.js logic) ─────────────────────
const API_BASE = (function () {
  const { origin, pathname } = window.location;
  const idx = pathname.indexOf('/Frontend/');
  return idx !== -1
    ? origin + pathname.substring(0, idx) + '/Backend/public'
    : origin;
})();

// ── Session helpers ─────────────────────────────────────────────────────────
function getStoredUser() {
  try {
    const raw = sessionStorage.getItem('lfp_user') ?? localStorage.getItem('lfp_user');
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

function clearSession() {
  sessionStorage.removeItem('csrf_token');
  sessionStorage.removeItem('lfp_user');
  localStorage.removeItem('lfp_user');
  localStorage.removeItem('lfp_remember_email');
}

function getCsrfToken() {
  return sessionStorage.getItem('csrf_token') || '';
}

// ── Admin auth guard ────────────────────────────────────────────────────────
// Runs immediately: redirect to login if no valid admin session is cached.
(function adminAuthGuard() {
  const user = getStoredUser();

  if (!user) {
    window.location.replace('login.html');
    return;
  }

  if (user.role !== 'admin') {
    // Logged-in customer accidentally landed on admin — send them home
    window.location.replace('../Customer/index.html');
    return;
  }

  // ── Update sidebar with real admin name ───────────────────────────────────
  const nameEl   = document.querySelector('.admin-profile .name');
  const roleEl   = document.querySelector('.admin-profile .role');
  const avatarEl = document.querySelector('.admin-profile .admin-avatar');
  if (nameEl)   nameEl.textContent   = user.full_name || 'Admin';
  if (roleEl)   roleEl.textContent   = 'Administrator';
  if (avatarEl) {
    if (user.avatar_url) {
      avatarEl.style.background = 'none';
      avatarEl.innerHTML = `<img src="${escHtml(user.avatar_url)}" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
    } else {
      avatarEl.textContent = (user.full_name || 'SA')
        .split(' ').map(w => w[0] || '').join('').toUpperCase().slice(0, 2);
    }
  }

  // ── Make footer profile area navigate to My Profile ──────────────────────
  const profileArea = document.querySelector('.admin-profile');
  if (profileArea) {
    profileArea.style.cursor = 'pointer';
    profileArea.addEventListener('click', e => {
      if (!e.target.closest('#adminLogoutBtn')) {
        window.location.href = 'profile.html';
      }
    });
  }

  // ── Load live pending count into sidebar badge ────────────────────────────
  fetch(API_BASE + '/api/admin/orders/stats', {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken() },
  }).then(r => {
    if (r.status === 401 || r.status === 403) { clearSession(); window.location.replace('login.html'); return null; }
    return r.json();
  }).then(j => {
    if (j && j.success) {
      const badge = document.getElementById('nav-pending-badge');
      if (badge && j.data.schedule > 0) badge.textContent = j.data.schedule;
    }
  }).catch(() => {});

  // ── Load unread notification count ────────────────────────────────────────
  fetch(API_BASE + '/api/admin/notifications?filter=unread', {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken() },
  }).then(r => {
    if (r.status === 401 || r.status === 403) { clearSession(); window.location.replace('login.html'); return null; }
    return r.json();
  }).then(j => {
    if (j && j.success) {
      const badge = document.getElementById('nav-notif-badge');
      if (badge && j.data.unread_count > 0) badge.textContent = j.data.unread_count;
    }
  }).catch(() => {});

  // ── Inject logout link into the sidebar footer (⋯ → Logout) ──────────────
  const profile = document.querySelector('.admin-profile');
  if (profile) {
    const logoutLink = document.createElement('a');
    logoutLink.id        = 'adminLogoutBtn';
    logoutLink.href      = '#';
    logoutLink.title     = 'Log out';
    logoutLink.style.cssText =
      'margin-left:auto;color:var(--gray-500);font-size:13px;text-decoration:none;' +
      'padding:4px 8px;border-radius:6px;transition:.2s;';
    logoutLink.textContent = '↩ Logout';
    logoutLink.addEventListener('mouseover', () => { logoutLink.style.background = 'var(--gray-100)'; });
    logoutLink.addEventListener('mouseout',  () => { logoutLink.style.background = ''; });

    // Replace the ⋯ span
    const dots = profile.querySelector('span[style]');
    if (dots) dots.replaceWith(logoutLink);
    else profile.appendChild(logoutLink);
  }

  // ── Logout handler ────────────────────────────────────────────────────────
  document.addEventListener('click', async e => {
    if (!e.target.closest('#adminLogoutBtn')) return;
    e.preventDefault();
    try {
      await fetch(API_BASE + '/api/auth/logout', {
        method:      'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrfToken(),
        },
      });
    } catch { /* ignore network errors — clear client side regardless */ }
    clearSession();
    window.location.replace('login.html');
  });

  // ── Background session validation ─────────────────────────────────────────
  fetch(API_BASE + '/api/auth/profile', {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken() },
  }).then(res => {
    if (res.status === 401) {
      clearSession();
      window.location.replace('login.html');
      return null;
    }
    return res.json();
  }).then(json => {
    if (json && json.data?.user?.role !== 'admin') {
      clearSession();
      window.location.replace('login.html');
    }
  }).catch(() => { /* offline — keep local state */ });
})();

// ══════════════════════════════════════════════════════════════════════════════
// SHARED UTILITIES
// ══════════════════════════════════════════════════════════════════════════════

// ── TOAST ───────────────────────────────────────────────────────────────────
function showToast(msg, type = 'default', duration = 3000) {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const icons = { success: '✅', error: '❌', warning: '⚠️', default: 'ℹ️' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<span>${icons[type] || icons.default}</span><span>${escHtml(msg)}</span>`;
  c.appendChild(t);
  setTimeout(() => t.remove(), duration);
}

// Sidebar toggle (mobile)
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');
if (sidebarToggle && sidebar) {
  sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  document.addEventListener('click', e => {
    if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
      sidebar.classList.remove('open');
    }
  });
}

// Dropdown toggle
document.addEventListener('click', e => {
  const trigger = e.target.closest('[data-dropdown]');
  document.querySelectorAll('.dropdown').forEach(d => {
    if (!d.contains(e.target)) d.classList.remove('open');
  });
  if (trigger) {
    const dd = trigger.closest('.dropdown');
    if (dd) dd.classList.toggle('open');
  }
});

// Modal helpers
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}
// Close on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

// Format helpers
function formatCurrency(n) {
  return 'LKR ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatDate(iso) {
  const d = new Date(iso);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function timeAgo(iso) {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1)  return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24)  return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}
function initials(name) {
  // Letters/digits only, so the result is always safe to put in HTML
  return String(name ?? '').split(' ').map(w => (w.match(/[\p{L}\p{N}]/u) || [''])[0]).join('').toUpperCase().slice(0, 2);
}

// Escape a value for safe use inside HTML text or attributes
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

// Encode a value as a JS string literal for an inline onclick="..." argument
function jsArg(s) {
  return escHtml(JSON.stringify(String(s ?? '')));
}

// Status badge HTML
function statusBadge(status) {
  const labels = {
    schedule: 'Schedule', confirm: 'Confirm', collected: 'Collected',
    processing: 'Processing', ready: 'Ready', out_for_delivery: 'Out for Delivery',
    delivered: 'Delivered', canceled: 'Canceled',
    active: 'Active', suspended: 'Suspended',
    admin: 'Admin', customer: 'Customer', driver: 'Driver',
  };
  const key = (status || '').toLowerCase().replace(/\s+/g, '_');
  return `<span class="badge badge-${escHtml(key)}">${escHtml(labels[key] || status)}</span>`;
}

// Animate counter
function animateCount(el, target, prefix = '', suffix = '', duration = 1200) {
  let start = 0;
  const step = Math.ceil(target / (duration / 16));
  const timer = setInterval(() => {
    start = Math.min(start + step, target);
    el.textContent = prefix + start.toLocaleString() + suffix;
    if (start >= target) clearInterval(timer);
  }, 16);
}

// ── DEMO DATA ─────────────────────────────────────────────────────────────────

const DEMO = {
  stats: {
    revenue: 18420,
    orders: 342,
    customers: 1284,
    pending: 12,
  },
  revenue7d: [1200, 980, 1540, 870, 2100, 1760, 1430],
  revenue7d_labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
  orderStatus: [
    { label: 'Delivered',         value: 198, color: '#059669' },
    { label: 'Processing',        value: 87,  color: '#2563EB' },
    { label: 'Pending',           value: 12,  color: '#D97706' },
    { label: 'Cancelled',         value: 45,  color: '#DC2626' },
  ],
  recentOrders: [
    { id: 'LFP-20260001', customer: 'Emily Dawson',   service: 'Dry Cleaning',   status: 'delivered',        total: 27.96 },
    { id: 'LFP-20260002', customer: 'James Okafor',   service: 'Wash & Fold',    status: 'processing',       total: 14.91 },
    { id: 'LFP-20260003', customer: 'Aisha Rahman',   service: 'Express Service',status: 'collected',        total: 22.43 },
    { id: 'LFP-20260004', customer: 'Lucas Silva',    service: 'Steam Ironing',  status: 'schedule',         total: 17.45 },
    { id: 'LFP-20260005', customer: 'Mia Thornton',   service: 'Stain Removal',  status: 'out_for_delivery', total: 35.94 },
    { id: 'LFP-20260006', customer: 'Noah Patel',     service: 'Bedding & Linen',status: 'confirm',          total: 19.92 },
  ],
  topServices: [
    { name: 'Wash & Fold',    count: 128, pct: 80, color: 'var(--primary)' },
    { name: 'Dry Cleaning',   count: 96,  pct: 60, color: 'var(--purple)' },
    { name: 'Express Service',count: 64,  pct: 40, color: 'var(--accent)' },
    { name: 'Steam Ironing',  count: 38,  pct: 24, color: 'var(--green)' },
    { name: 'Stain Removal',  count: 16,  pct: 10, color: 'var(--orange)' },
  ],
  newCustomers: [
    { name: 'Emily Dawson',  email: 'emily@example.com',   joined: '2026-05-30' },
    { name: 'James Okafor',  email: 'james@example.com',   joined: '2026-05-29' },
    { name: 'Aisha Rahman',  email: 'aisha@example.com',   joined: '2026-05-28' },
    { name: 'Lucas Silva',   email: 'lucas@example.com',   joined: '2026-05-27' },
    { name: 'Mia Thornton',  email: 'mia@example.com',     joined: '2026-05-26' },
  ],
  reviews: [
    { name: 'Emily Dawson',  rating: 5, text: 'Fantastic service! My clothes were perfectly cleaned.' },
    { name: 'James Okafor',  rating: 4, text: 'Very quick turnaround. Will definitely use again.' },
    { name: 'Aisha Rahman',  rating: 5, text: 'Eco-friendly and efficient. Highly recommend!' },
    { name: 'Lucas Silva',   rating: 3, text: 'Good but one item had a small crease remaining.' },
  ],
};
