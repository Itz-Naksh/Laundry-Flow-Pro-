/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — signup.js
   Multi-step form with real-time validation, password strength,
   show/hide toggle, and simulated submission.
═══════════════════════════════════════════════════════════════ */

'use strict';

/* ─── TOAST ──────────────────────────────────────────────────── */
function showToast(msg, type = 'info', duration = 4000) {
  const wrap  = document.getElementById('toast-wrap');
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type]} toast-icon"></i><span>${msg}</span><button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>`;
  t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
  wrap.appendChild(t);
  const timer = setTimeout(() => dismiss(t), duration);
  function dismiss(el) { clearTimeout(timer); el.classList.add('hide'); el.addEventListener('animationend', () => el.remove(), { once: true }); }
}

/* ─── HELPERS ────────────────────────────────────────────────── */
function setFieldState(fieldId, state, message = '') {
  const fg   = document.getElementById(fieldId);
  const msg  = fg?.querySelector('.field-msg');
  const wrap = fg?.querySelector('.input-wrap');
  const stat = wrap?.querySelector('.input-status');

  wrap?.classList.remove('valid', 'invalid');
  if (state) wrap?.classList.add(state);

  if (msg) {
    msg.textContent = message;
    msg.className   = `field-msg ${state || ''}`;
  }

  if (stat) {
    stat.innerHTML = state === 'valid'
      ? '<i class="fas fa-check-circle"></i>'
      : state === 'invalid' ? '<i class="fas fa-times-circle"></i>' : '';
  }
}

function val(id)    { return document.getElementById(id)?.value.trim() ?? ''; }
function isEmpty(v) { return v.length === 0; }

/* ─── VALIDATORS ─────────────────────────────────────────────── */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const PHONE_RE = /^\+?[\d\s\-().]{7,20}$/;
const ZIP_RE   = /^\d{4,10}$/;

function validateStep1() {
  let ok = true;

  const fn = val('firstName');
  if (isEmpty(fn))         { setFieldState('fg-firstName','invalid','First name is required'); ok=false; }
  else if (fn.length < 2)  { setFieldState('fg-firstName','invalid','Name must be at least 2 characters'); ok=false; }
  else                      { setFieldState('fg-firstName','valid','Looks good!'); }

  const ln = val('lastName');
  if (isEmpty(ln))          { setFieldState('fg-lastName','invalid','Last name is required'); ok=false; }
  else if (ln.length < 2)   { setFieldState('fg-lastName','invalid','Name must be at least 2 characters'); ok=false; }
  else                       { setFieldState('fg-lastName','valid','Looks good!'); }

  const em = val('email');
  if (isEmpty(em))          { setFieldState('fg-email','invalid','Email is required'); ok=false; }
  else if (!EMAIL_RE.test(em)) { setFieldState('fg-email','invalid','Enter a valid email address'); ok=false; }
  else                         { setFieldState('fg-email','valid','Valid email ✓'); }

  const ph = val('phone');
  if (isEmpty(ph))          { setFieldState('fg-phone','invalid','Phone number is required'); ok=false; }
  else if (!PHONE_RE.test(ph)) { setFieldState('fg-phone','invalid','Enter a valid phone number'); ok=false; }
  else                         { setFieldState('fg-phone','valid','Valid number ✓'); }

  return ok;
}

function validateStep2() {
  let ok = true;

  const addr = val('address');
  if (isEmpty(addr) || addr.length < 5) { setFieldState('fg-address','invalid','Please enter your full street address'); ok=false; }
  else { setFieldState('fg-address','valid',''); }

  const city = val('city');
  if (isEmpty(city)) { setFieldState('fg-city','invalid','City is required'); ok=false; }
  else { setFieldState('fg-city','valid',''); }

  const zip  = val('zip');
  if (!ZIP_RE.test(zip)) { setFieldState('fg-zip','invalid','Enter a valid ZIP / postcode'); ok=false; }
  else { setFieldState('fg-zip','valid',''); }

  return ok;
}

function validateStep3() {
  let ok = true;

  const pw = document.getElementById('password').value;
  if (!pw || getStrengthScore(pw) < 2) { setFieldState('fg-password','invalid','Password is too weak'); ok=false; }
  else { setFieldState('fg-password','valid',''); }

  const cp = document.getElementById('confirm').value;
  if (cp !== pw) { setFieldState('fg-confirm','invalid','Passwords do not match'); ok=false; }
  else { setFieldState('fg-confirm','valid','Passwords match ✓'); }

  const termsChecked = document.getElementById('terms').checked;
  const termsMsg = document.getElementById('terms-msg');
  if (!termsChecked) {
    termsMsg.textContent = 'You must accept the terms to continue';
    termsMsg.className   = 'field-msg error';
    ok = false;
  } else {
    termsMsg.textContent = '';
  }

  return ok;
}

/* ─── PASSWORD STRENGTH ──────────────────────────────────────── */
function getStrengthScore(pw) {
  let score = 0;
  if (pw.length >= 8)              score++;
  if (/[A-Z]/.test(pw))           score++;
  if (/[0-9]/.test(pw))           score++;
  if (/[^A-Za-z0-9]/.test(pw))    score++;
  return score;
}

const strengthConfig = [
  { label: 'Too weak',     color: '#EF4444', pct: 20 },
  { label: 'Weak',         color: '#F97316', pct: 40 },
  { label: 'Fair',         color: '#F59E0B', pct: 60 },
  { label: 'Strong',       color: '#10B981', pct: 80 },
  { label: 'Very strong',  color: '#059669', pct: 100 },
];

function updateStrength(pw) {
  const score  = getStrengthScore(pw);
  const fill   = document.getElementById('strength-fill');
  const label  = document.getElementById('strength-label');
  const cfg    = pw.length ? strengthConfig[score] : { label:'Enter a password', color:'#E2E8F0', pct:0 };

  fill.style.width      = cfg.pct + '%';
  fill.style.background = cfg.color;
  label.textContent     = cfg.label;
  label.style.color     = cfg.color;

  toggleHint('hint-len',   pw.length >= 8);
  toggleHint('hint-upper', /[A-Z]/.test(pw));
  toggleHint('hint-num',   /[0-9]/.test(pw));
  toggleHint('hint-spec',  /[^A-Za-z0-9]/.test(pw));
}

function toggleHint(id, pass) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('pass', pass);
  el.querySelector('i').className = `fas ${pass ? 'fa-check' : 'fa-times'}`;
}

/* ─── STEP MANAGEMENT ────────────────────────────────────────── */
let currentStep = 1;

function goToStep(n) {
  document.getElementById(`step-${currentStep}`)?.classList.add('hidden');
  document.getElementById(`step-${n}`)?.classList.remove('hidden');

  /* Update progress */
  for (let i = 1; i <= 3; i++) {
    const ps  = document.getElementById(`ps-${i}`);
    ps?.classList.remove('active', 'done');
    if (i < n)  ps?.classList.add('done');
    if (i === n) ps?.classList.add('active');
  }

  /* Progress lines */
  document.querySelectorAll('.prog-line').forEach((line, i) => {
    line.classList.toggle('done', i + 1 < n);
  });

  currentStep = n;
}

/* ─── INIT ───────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

  /* Step 1 → 2 */
  document.getElementById('step1-next')?.addEventListener('click', () => {
    if (validateStep1()) goToStep(2);
    else showToast('Please fix the errors above', 'error');
  });

  /* Step 2 → 3 */
  document.getElementById('step2-next')?.addEventListener('click', () => {
    if (validateStep2()) goToStep(3);
    else showToast('Please complete all fields', 'error');
  });

  /* Back buttons */
  document.getElementById('step2-back')?.addEventListener('click', () => goToStep(1));
  document.getElementById('step3-back')?.addEventListener('click', () => goToStep(2));

  /* Password strength live */
  document.getElementById('password')?.addEventListener('input', e => updateStrength(e.target.value));

  /* Show / hide password */
  document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.querySelector('i').className = `fas ${show ? 'fa-eye-slash' : 'fa-eye'}`;
    });
  });

  /* Real-time email check */
  document.getElementById('email')?.addEventListener('input', e => {
    const v = e.target.value.trim();
    if (!v) return setFieldState('fg-email', '', '');
    if (EMAIL_RE.test(v)) setFieldState('fg-email', 'valid', 'Valid email ✓');
    else setFieldState('fg-email', 'invalid', 'Check your email format');
  });

  /* Form submit */
  document.getElementById('signup-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    if (!validateStep3()) { showToast('Please fix the errors to continue', 'error'); return; }

    const submitBtn = document.getElementById('submit-btn');
    const spinner   = document.getElementById('submit-spinner');
    submitBtn.disabled = true;
    submitBtn.querySelector('span').textContent = 'Creating account…';
    spinner?.classList.remove('hidden');

    /* Build payload that matches the backend's expected field names:
       - full_name          (AuthController validates 'full_name')
       - email, phone       (direct match)
       - address            (combined: street + city + zip)
       - password           (raw — hashed by User::setPassword() server-side)
       - password_confirmation (Validator::ruleConfirmed() looks for field + '_confirmation') */
    const payload = {
      full_name:             `${val('firstName')} ${val('lastName')}`,
      email:                 val('email'),
      phone:                 val('phone'),
      address:               `${val('address')}, ${val('city')} ${val('zip')}`,
      password:              document.getElementById('password').value,
      password_confirmation: document.getElementById('confirm').value,
    };

    try {
      const res = await fetch(API_BASE + '/api/auth/register', {
        method:      'POST',
        credentials: 'include',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify(payload),
      });

      const json = await res.json();

      if (res.ok) {
        /* Registration confirmed by the server — now show success */
        sessionStorage.setItem('lfp_registered_email', payload.email);

        document.getElementById('signup-form').classList.add('hidden');
        document.getElementById('success-state')?.classList.remove('hidden');
        document.querySelector('.auth-divider')?.remove();
        document.querySelector('.btn-outline')?.remove();
        showToast('Account created successfully!', 'success', 5000);

        setTimeout(() => { window.location.href = 'login.html'; }, 3400);

      } else if (res.status === 409) {
        /* Duplicate email */
        setFieldState('fg-email', 'invalid', json.message || 'An account with this email already exists.');
        goToStep(1);
        showToast(json.message || 'Email already registered.', 'error');

      } else if (res.status === 422 && json.errors) {
        /* Validation errors — map backend field names back to form field IDs */
        const fieldMap = {
          full_name: 'fg-firstName',
          email:     'fg-email',
          phone:     'fg-phone',
          address:   'fg-address',
          password:  'fg-password',
        };
        let firstErrorStep = null;
        for (const [field, message] of Object.entries(json.errors)) {
          const fgId = fieldMap[field];
          if (fgId) {
            setFieldState(fgId, 'invalid', message);
            if (!firstErrorStep) {
              firstErrorStep = ['fg-firstName','fg-email','fg-phone'].includes(fgId) ? 1
                : ['fg-address'].includes(fgId) ? 2 : 3;
            }
          }
        }
        if (firstErrorStep) goToStep(firstErrorStep);
        showToast('Please fix the errors highlighted above.', 'error');

      } else {
        showToast(json.message || 'Registration failed. Please try again.', 'error');
      }

    } catch (err) {
      /* Network failure or JSON parse error */
      console.error('[signup] fetch error:', err);
      showToast('Could not reach the server. Check your connection and try again.', 'error');

    } finally {
      submitBtn.disabled = false;
      submitBtn.querySelector('span').textContent = 'Create Account';
      spinner?.classList.add('hidden');
    }
  });

});
