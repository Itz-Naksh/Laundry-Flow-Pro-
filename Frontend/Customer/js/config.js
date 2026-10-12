'use strict';

/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — config.js
   Shared constants and session helpers.
   Must be loaded BEFORE any other JS file on every page.
═══════════════════════════════════════════════════════════════ */

/* ─── API Base URL ────────────────────────────────────────────────
   Auto-detects the backend URL from the current page location:

   WAMP (no VHost):
     Page  → http://localhost/Laundry Flow Pro/Frontend/Customer/login.html
     API   → http://localhost/Laundry Flow Pro/Backend/public

   Virtual host pointing to Frontend/Customer/:
     Page  → http://laundry-flow-pro/login.html
     API   → http://laundry-flow-pro  (root already IS the backend in this scenario
              — update the origin+'/Backend/public' line below to '' if needed)
─────────────────────────────────────────────────────────────────── */
const API_BASE = (function () {
  const { origin, pathname } = window.location;
  const idx = pathname.indexOf('/Frontend/');
  return idx !== -1
    ? origin + pathname.substring(0, idx) + '/Backend/public'
    : origin; // VHost: root already resolves to backend
})();

/* ─── Session helpers ─────────────────────────────────────────────
   Auth state is stored in two layers:
     1. sessionStorage  — always (per-tab, survives page navigation)
     2. localStorage    — only when "remember me" is checked (survives tab close)
   The PHP session cookie (lfp_session) is the real authority; these
   stores are display/UX caches only.
─────────────────────────────────────────────────────────────────── */

/* ─── Escape a value for safe use inside HTML text or attributes ── */
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function getStoredUser() {
  try {
    const raw = sessionStorage.getItem('lfp_user') ?? localStorage.getItem('lfp_user');
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function saveSession(user, csrfToken, remember) {
  sessionStorage.setItem('csrf_token', csrfToken);
  sessionStorage.setItem('lfp_user',   JSON.stringify(user));
  if (remember) {
    localStorage.setItem('lfp_user',           JSON.stringify(user));
    localStorage.setItem('lfp_remember_email', user.email ?? '');
  }
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
