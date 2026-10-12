'use strict';

/* ── API base URL ────────────────────────────────────────────────── */
const API_BASE = (function () {
  const { origin, pathname } = window.location;
  const idx = pathname.indexOf('/Frontend/');
  return idx !== -1
    ? origin + pathname.substring(0, idx) + '/Backend/public'
    : origin;
})();

/* ── Session helpers ─────────────────────────────────────────────── */
function saveSession(user, csrfToken, remember) {
  sessionStorage.setItem('csrf_token', csrfToken);
  sessionStorage.setItem('lfp_user',   JSON.stringify(user));
  if (remember) {
    localStorage.setItem('lfp_user',           JSON.stringify(user));
    localStorage.setItem('lfp_remember_email', user.email ?? '');
  }
}
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

/* ── Escape a value for safe use inside HTML ───────────────────────── */
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

/* ── Toast ───────────────────────────────────────────────────────── */
function showToast(msg, type = 'info', duration = 4000) {
  const wrap  = document.getElementById('toast-wrap');
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${escHtml(msg)}</span><button class="toast-close"><i class="fas fa-times"></i></button>`;
  t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
  wrap?.appendChild(t);
  const timer = setTimeout(() => dismiss(t), duration);
  function dismiss(el) {
    clearTimeout(timer);
    el.classList.add('hide');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }
}

/* ── Field helpers ───────────────────────────────────────────────── */
function setInvalid(iwId, msgId, text) {
  document.getElementById(iwId)?.classList.add('invalid');
  document.getElementById(iwId)?.classList.remove('valid');
  const m = document.getElementById(msgId);
  if (m) m.textContent = text;
}
function setValid(iwId, msgId) {
  document.getElementById(iwId)?.classList.remove('invalid');
  document.getElementById(iwId)?.classList.add('valid');
  const m = document.getElementById(msgId);
  if (m) m.textContent = '';
}
function clearField(iwId, msgId) {
  document.getElementById(iwId)?.classList.remove('invalid', 'valid');
  const m = document.getElementById(msgId);
  if (m) m.textContent = '';
}
function showError(text) {
  const b = document.getElementById('error-banner');
  const t = document.getElementById('error-text');
  if (t) t.textContent = text;
  b?.classList.remove('hidden');
}
function hideError() {
  document.getElementById('error-banner')?.classList.add('hidden');
}

/* ── Main init ───────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

  /* Already logged in as admin? */
  const stored = getStoredUser();
  if (stored && sessionStorage.getItem('csrf_token')) {
    if (stored.role === 'admin') {
      window.location.replace('dashboard.html');
      return;
    }
    /* Was logged in as customer — clear and let them log in as admin */
    clearSession();
  }

  /* Pre-fill remembered email */
  const remembered = localStorage.getItem('lfp_remember_email');
  if (remembered) {
    const el = document.getElementById('email');
    if (el) { el.value = remembered; document.getElementById('remember').checked = true; }
  }

  /* Show / hide password */
  document.getElementById('pw-toggle')?.addEventListener('click', () => {
    const inp  = document.getElementById('password');
    const icon = document.querySelector('#pw-toggle i');
    const show = inp.type === 'password';
    inp.type   = show ? 'text' : 'password';
    icon.className = `fas ${show ? 'fa-eye-slash' : 'fa-eye'}`;
  });

  /* Clear field state on input */
  document.getElementById('email')?.addEventListener('input',    () => { clearField('iw-email', 'msg-email'); hideError(); });
  document.getElementById('password')?.addEventListener('input', () => { clearField('iw-password', 'msg-password'); hideError(); });

  /* ── LOGIN FORM SUBMIT ───────────────────────────────────────── */
  document.getElementById('login-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    hideError();

    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const remember = document.getElementById('remember').checked;

    /* Client-side validation */
    let valid = true;
    if (!email) {
      setInvalid('iw-email', 'msg-email', 'Email is required');
      valid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      setInvalid('iw-email', 'msg-email', 'Enter a valid email address');
      valid = false;
    } else {
      setValid('iw-email', 'msg-email');
    }
    if (!password) {
      setInvalid('iw-password', 'msg-password', 'Password is required');
      valid = false;
    } else {
      setValid('iw-password', 'msg-password');
    }

    if (!valid) {
      document.getElementById('login-card')?.classList.add('shake');
      setTimeout(() => document.getElementById('login-card')?.classList.remove('shake'), 500);
      return;
    }

    /* Loading state */
    const btn     = document.getElementById('login-btn');
    const btnText = document.getElementById('btn-text');
    const spinner = document.getElementById('spinner');
    btn.disabled  = true;
    btnText.innerHTML = 'Signing in…';
    spinner.classList.add('show');

    try {
      const res  = await fetch(API_BASE + '/api/auth/login', {
        method:      'POST',
        credentials: 'include',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify({ email, password }),
      });
      const json = await res.json();

      if (!res.ok) {
        const msg = json.message || 'Invalid email or password.';
        showError(msg);
        setInvalid('iw-email',    'msg-email',    '');
        setInvalid('iw-password', 'msg-password', '');
        document.getElementById('login-card')?.classList.add('shake');
        setTimeout(() => document.getElementById('login-card')?.classList.remove('shake'), 500);
        return;
      }

      const user      = json.data?.user       ?? {};
      const csrfToken = json.data?.csrf_token ?? '';

      /* Role check — only admin (role_id 1) and staff (role_id 2) allowed */
      if (user.role !== 'admin') {
        showError('This portal is for administrators only. Use the customer login instead.');
        clearSession();
        return;
      }

      saveSession(user, csrfToken, remember);
      showToast(`Welcome, ${user.full_name || 'Admin'}!`, 'success', 2500);
      btnText.innerHTML = '<i class="fas fa-circle-check"></i> Redirecting…';

      setTimeout(() => window.location.replace('dashboard.html'), 1400);

    } catch (err) {
      console.error('[admin-login] fetch error:', err);
      showError('Could not reach the server. Please check your connection.');
    } finally {
      spinner.classList.remove('show');
      if (btn.disabled && btnText.textContent.includes('Signing')) {
        btn.disabled      = false;
        btnText.innerHTML = '<i class="fas fa-right-to-bracket"></i> Sign In';
      }
    }
  });

});
