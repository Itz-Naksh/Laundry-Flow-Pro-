/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — login.js
   Handles: Form validation, show/hide password, remember me,
            social login stubs, forgot password modal,
            real API auth, CSRF token storage, role-based redirect.
═══════════════════════════════════════════════════════════════ */

'use strict';

/* ─── TOAST ──────────────────────────────────────────────────── */
function showToast(msg, type = 'info', duration = 4000) {
  const wrap  = document.getElementById('toast-wrap');
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type]} toast-icon" style="color:${type==='success'?'#10B981':type==='error'?'#EF4444':type==='warning'?'#F59E0B':'#60A5FA'}"></i><span>${escHtml(msg)}</span><button class="toast-close"><i class="fas fa-times"></i></button>`;
  t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
  wrap?.appendChild(t);
  const timer = setTimeout(() => dismiss(t), duration);
  function dismiss(el) { clearTimeout(timer); el.classList.add('hide'); el.addEventListener('animationend', () => el.remove(), { once:true }); }
}

/* ─── FIELD HELPERS ──────────────────────────────────────────── */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function setField(groupId, state, message = '') {
  const fg   = document.getElementById(groupId);
  const wrap = fg?.querySelector('.input-wrap');
  const msg  = fg?.querySelector('.field-msg');
  const stat = wrap?.querySelector('.input-status');

  wrap?.classList.remove('valid', 'invalid');
  if (state) wrap?.classList.add(state);

  if (msg) { msg.textContent = message; msg.className = `field-msg ${state}`; }
  if (stat) {
    stat.innerHTML = state === 'valid'
      ? '<i class="fas fa-check-circle"></i>'
      : state === 'invalid' ? '<i class="fas fa-times-circle"></i>' : '';
  }
}

function clearField(groupId) { setField(groupId, '', ''); }

/* ─── VALIDATE ───────────────────────────────────────────────── */
function validateEmail() {
  const v = document.getElementById('email').value.trim();
  if (!v)                { setField('fg-email','invalid','Email is required'); return false; }
  if (!EMAIL_RE.test(v)) { setField('fg-email','invalid','Enter a valid email address'); return false; }
  setField('fg-email','valid','');
  return true;
}

function validatePassword() {
  const v = document.getElementById('password').value;
  if (!v)         { setField('fg-password','invalid','Password is required'); return false; }
  if (v.length<6) { setField('fg-password','invalid','Password must be at least 6 characters'); return false; }
  setField('fg-password','valid','');
  return true;
}

/* ─── ROLE-BASED REDIRECT ────────────────────────────────────── */
/*
 * role_id 1 = Admin, 2 = Staff  → Admin panel
 * role_id 3 = Customer, 4 = Driver → customer landing
 */
function redirectAfterLogin(user) {
  const roleId = Number(user.role_id ?? 3);
  window.location.href = (roleId === 1 || roleId === 2)
    ? '../Admin/dashboard.html'
    : 'index.html';
}

/* ─── MAIN INIT ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

  /* Already logged in? (fast path — checks local cache only)
   * If the PHP session has since expired, the next API call will return 401
   * and the app will clear the stale session and redirect back here. */
  const storedUser = getStoredUser();
  if (storedUser && sessionStorage.getItem('csrf_token')) {
    showToast('You are already signed in. Redirecting…', 'info', 2500);
    setTimeout(() => redirectAfterLogin(storedUser), 2600);
    return;
  }

  /* ── Success banner after signup ──
   * signup.js stores the registered email under 'lfp_registered_email'.
   * Read and consume it once so it doesn't persist across page reloads. */
  const registeredEmail = sessionStorage.getItem('lfp_registered_email');
  if (registeredEmail) {
    document.getElementById('success-banner')?.classList.remove('hidden');
    const emailInput = document.getElementById('email');
    if (emailInput) emailInput.value = registeredEmail;
    sessionStorage.removeItem('lfp_registered_email');
  }

  /* ── Pre-fill email from "remember me" ── */
  const rememberedEmail = localStorage.getItem('lfp_remember_email');
  if (rememberedEmail && !registeredEmail) {
    const emailInput = document.getElementById('email');
    if (emailInput) emailInput.value = rememberedEmail;
    const rememberBox = document.getElementById('remember');
    if (rememberBox) rememberBox.checked = true;
  }

  /* ── Show / hide password ── */
  document.getElementById('pw-toggle')?.addEventListener('click', () => {
    const input = document.getElementById('password');
    const icon  = document.querySelector('#pw-toggle i');
    const show  = input.type === 'password';
    input.type  = show ? 'text' : 'password';
    icon.className = `fas ${show ? 'fa-eye-slash' : 'fa-eye'}`;
  });

  /* ── Live validation ── */
  document.getElementById('email')?.addEventListener('blur',  validateEmail);
  document.getElementById('password')?.addEventListener('blur', validatePassword);
  document.getElementById('email')?.addEventListener('input',  () => clearField('fg-email'));
  document.getElementById('password')?.addEventListener('input', () => clearField('fg-password'));

  /* ══════════════════════════════════════════════════════════════
     LOGIN FORM — real fetch to POST /api/auth/login
  ══════════════════════════════════════════════════════════════ */
  document.getElementById('login-form')?.addEventListener('submit', async e => {
    e.preventDefault();

    const emailOk = validateEmail();
    const passOk  = validatePassword();
    if (!emailOk || !passOk) {
      document.getElementById('login-card')?.classList.add('shake');
      setTimeout(() => document.getElementById('login-card')?.classList.remove('shake'), 500);
      showToast('Please fix the errors above', 'error');
      return;
    }

    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const remember = document.getElementById('remember').checked;

    const btn     = document.getElementById('login-btn');
    const btnText = document.getElementById('btn-text');
    const spinner = document.getElementById('login-spinner');
    btn.disabled  = true;
    btnText.textContent = 'Signing in…';
    spinner?.classList.remove('hidden');

    try {
      const res  = await fetch(API_BASE + '/api/auth/login', {
        method:      'POST',
        credentials: 'include',               // send + receive the PHP session cookie
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify({ email, password }),
      });

      const json = await res.json();

      if (res.ok) {
        /* ── Login confirmed by server ── */
        const user      = json.data?.user       ?? {};
        const csrfToken = json.data?.csrf_token ?? '';

        saveSession(user, csrfToken, remember);

        /* Flag a one-time welcome popup for the destination page.
         * main.js consumes (and clears) this flag on the very next load,
         * so the popup shows only once — right after login. */
        sessionStorage.setItem('lfp_show_welcome', '1');

        btnText.textContent = 'Redirecting…';
        showToast(`Welcome back, ${user.full_name || 'there'}!`, 'success', 3000);
        setTimeout(() => redirectAfterLogin(user), 1500);

      } else {
        /* 401 — wrong credentials or suspended account */
        const errMsg = json.message || 'Invalid email or password.';
        setField('fg-email',    'invalid', '');
        setField('fg-password', 'invalid', errMsg);
        document.getElementById('login-card')?.classList.add('shake');
        setTimeout(() => document.getElementById('login-card')?.classList.remove('shake'), 500);
        showToast(errMsg, 'error', 5000);
      }

    } catch (err) {
      console.error('[login] fetch error:', err);
      showToast('Could not reach the server. Check your connection and try again.', 'error');

    } finally {
      /* Always re-enable — even on success the redirect takes ~1.5s */
      if (btnText.textContent === 'Signing in…') {
        btn.disabled = false;
        btnText.textContent = 'Sign In';
      }
      spinner?.classList.add('hidden');
    }
  });

  /* ══════════════════════════════════════════════════════════════
     FORGOT PASSWORD MODAL — real fetch to POST /api/auth/forgot-password
  ══════════════════════════════════════════════════════════════ */
  document.getElementById('forgot-link')?.addEventListener('click', e => {
    e.preventDefault();
    document.getElementById('forgot-modal')?.classList.remove('hidden');
    document.getElementById('reset-email').focus();
  });

  document.getElementById('modal-close')?.addEventListener('click', closeModal);

  document.getElementById('forgot-modal')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
  });

  function closeModal() {
    document.getElementById('forgot-modal')?.classList.add('hidden');
    document.getElementById('reset-success')?.classList.add('hidden');
    document.getElementById('reset-email').value = '';

    /* Re-show the send button so the modal works on second open */
    const resetBtn = document.getElementById('reset-btn');
    if (resetBtn) {
      resetBtn.classList.remove('hidden');
      resetBtn.disabled = false;
      resetBtn.querySelector('span').textContent = 'Send Reset Link';
    }

    const fg = document.getElementById('fg-reset-email');
    fg?.querySelector('.input-wrap')?.classList.remove('valid', 'invalid');
    const msg = fg?.querySelector('.field-msg');
    if (msg) msg.textContent = '';
  }

  document.getElementById('reset-btn')?.addEventListener('click', async () => {
    const email    = document.getElementById('reset-email').value.trim();
    const fg       = document.getElementById('fg-reset-email');
    const wrap     = fg?.querySelector('.input-wrap');
    const msg      = fg?.querySelector('.field-msg');
    const resetBtn = document.getElementById('reset-btn');
    const spinner  = document.getElementById('reset-spinner');

    wrap?.classList.remove('valid', 'invalid');

    if (!email || !EMAIL_RE.test(email)) {
      wrap?.classList.add('invalid');
      if (msg) { msg.textContent = 'Enter a valid email address'; msg.className = 'field-msg error'; }
      return;
    }

    wrap?.classList.add('valid');
    if (msg) msg.textContent = '';

    resetBtn.disabled = true;
    resetBtn.querySelector('span').textContent = 'Sending…';
    spinner?.classList.remove('hidden');

    try {
      /* The backend always returns HTTP 200 with the same vague message
       * regardless of whether the email exists — security by design.
       * So we show success unconditionally after a successful HTTP response. */
      await fetch(API_BASE + '/api/auth/forgot-password', {
        method:      'POST',
        credentials: 'include',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify({ email }),
      });

      document.getElementById('reset-success')?.classList.remove('hidden');
      resetBtn.classList.add('hidden');
      showToast('Reset link sent! Check your inbox.', 'success');

    } catch (err) {
      console.error('[forgot-password] fetch error:', err);
      showToast('Could not reach the server. Please try again.', 'error');
      resetBtn.disabled = false;
      resetBtn.querySelector('span').textContent = 'Send Reset Link';

    } finally {
      spinner?.classList.add('hidden');
    }
  });

  /* ── SOCIAL LOGIN STUBS ── */
  ['google-btn', 'apple-btn', 'facebook-btn'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', () => {
      showToast('Social Sign-In coming soon!', 'info');
    });
  });

});
