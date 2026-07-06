'use strict';
/* ═══════════════════════════════════════════════════════════════
   Admin Profile Page — admin-profile.js
   Depends on admin.js (API_BASE, getCsrfToken, showToast, getStoredUser)
═══════════════════════════════════════════════════════════════ */

let _originalData = null;    // snapshot for Reset button

/* ── DOM refs ─────────────────────────────────────────────────── */
const $ = id => document.getElementById(id);

/* ── Load profile ─────────────────────────────────────────────── */
async function loadProfile() {
  try {
    const res  = await fetch(API_BASE + '/api/profile', {
      credentials: 'include',
      headers: { 'X-CSRF-Token': getCsrfToken() },
    });
    if (res.status === 401) {
      clearSession?.();
      window.location.replace('login.html');
      return;
    }
    const json = await res.json();
    if (!json.success) { showToast(json.message || 'Failed to load profile.', 'error'); return; }

    const { user, address } = json.data;
    renderHero(user);
    fillForm(user, address);
    _originalData = { user, address };

  } catch (err) {
    console.error('[admin-profile] load error:', err);
    showToast('Could not load profile.', 'error');
  }
}

function renderHero(user) {
  const initials = (user.full_name || 'SA')
    .split(' ').map(w => w[0] || '').join('').toUpperCase().slice(0, 2);

  $('hero-initials').textContent = initials;
  $('hero-name').textContent     = user.full_name || '—';
  $('hero-email').textContent    = user.email     || '—';
  $('hero-role').textContent     = user.role === 'admin' ? 'Administrator' : 'Staff';

  /* Avatar image */
  if (user.avatar_url) {
    const img = $('hero-img');
    img.src   = user.avatar_url;
    img.onload = () => { img.classList.add('loaded'); $('hero-initials').style.display = 'none'; };
  }

  /* Sidebar footer (admin.js may already have set it, but re-sync) */
  const sbName = $('sb-name');
  const sbAv   = $('sb-avatar');
  if (sbName) sbName.textContent = user.full_name || 'Admin';
  if (sbAv) {
    if (user.avatar_url) {
      sbAv.innerHTML = `<img src="${user.avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
    } else {
      sbAv.textContent = initials;
    }
  }

  /* Stats */
  $('hero-since').textContent  = user.created_at
    ? new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
    : '—';
  $('stat-id').textContent      = '#' + String(user.id || 0).padStart(4, '0');
  $('stat-role-id').textContent = { 1: 'Admin (L1)', 2: 'Staff (L2)', 3: 'Customer', 4: 'Driver' }[user.role_id] || '—';
  $('stat-status').textContent  = (user.status || 'active').charAt(0).toUpperCase() + (user.status || 'active').slice(1);
}

function fillForm(user, address) {
  const nameParts = (user.full_name || '').split(' ');
  $('firstName').value = nameParts.slice(0, -1).join(' ') || nameParts[0] || '';
  $('lastName').value  = nameParts.length > 1 ? nameParts[nameParts.length - 1] : '';
  $('email').value     = user.email  || '';
  $('phone').value     = user.phone  || '';

  if (address) {
    $('street').value = address.street      || '';
    $('city').value   = address.city        || '';
    $('postal').value = address.postal_code || '';
  } else if (user.address) {
    /* Fall back to combined address string */
    $('street').value = user.address;
  }
}

/* ── Save personal info ───────────────────────────────────────── */
$('profile-form')?.addEventListener('submit', async e => {
  e.preventDefault();

  const firstName = $('firstName').value.trim();
  const lastName  = $('lastName').value.trim();
  const phone     = $('phone').value.trim();
  const street    = $('street').value.trim();
  const city      = $('city').value.trim();
  const postal    = $('postal').value.trim();

  /* Simple client validation */
  let valid = true;
  if (!firstName) {
    showFieldError('err-firstName', 'First name is required');
    valid = false;
  } else { hideFieldError('err-firstName'); }

  if (!lastName) {
    showFieldError('err-lastName', 'Last name is required');
    valid = false;
  } else { hideFieldError('err-lastName'); }

  if (!valid) return;

  setLoading('save-btn', 'save-text', 'save-spin', true);

  try {
    const res = await fetch(API_BASE + '/api/profile', {
      method:      'PUT',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      body: JSON.stringify({ first_name: firstName, last_name: lastName, phone, street, city, postal_code: postal }),
    });
    const json = await res.json();

    if (json.success) {
      showToast('Profile updated successfully!', 'success');
      /* Update session storage with new name */
      const stored = getStoredUser();
      if (stored) {
        stored.full_name = firstName + ' ' + lastName;
        sessionStorage.setItem('lfp_user', JSON.stringify(stored));
        localStorage.setItem('lfp_user', JSON.stringify(stored));
      }
      $('hero-name').textContent = firstName + ' ' + lastName;
      const sbName = $('sb-name');
      if (sbName) sbName.textContent = firstName + ' ' + lastName;
    } else {
      const errors = json.errors || {};
      Object.entries(errors).forEach(([k, v]) => showFieldError('err-' + k, v));
      showToast(json.message || 'Update failed.', 'error');
    }
  } catch (err) {
    console.error('[admin-profile] save error:', err);
    showToast('Could not save changes.', 'error');
  } finally {
    setLoading('save-btn', 'save-text', 'save-spin', false, 'Save Changes');
  }
});

/* ── Reset form ───────────────────────────────────────────────── */
$('reset-btn')?.addEventListener('click', () => {
  if (_originalData) {
    fillForm(_originalData.user, _originalData.address);
    clearFieldErrors();
    showToast('Form reset to saved values.', 'default');
  }
});

/* ── Avatar upload ────────────────────────────────────────────── */
$('avatar-input')?.addEventListener('change', async e => {
  const file = e.target.files?.[0];
  if (!file) return;

  if (file.size > 5 * 1024 * 1024) {
    showToast('File must be under 5 MB.', 'error'); return;
  }

  const progress = $('upload-progress');
  progress.classList.add('show');

  const formData = new FormData();
  formData.append('avatar', file);

  try {
    const res  = await fetch(API_BASE + '/api/profile/avatar', {
      method:      'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': getCsrfToken() },
      body:    formData,
    });
    const json = await res.json();

    if (json.success) {
      const url = json.data.avatar_url;
      /* Update hero */
      const img = $('hero-img');
      img.src   = url;
      img.onload = () => { img.classList.add('loaded'); $('hero-initials').style.display = 'none'; };
      /* Update sidebar */
      const sbAv = $('sb-avatar');
      if (sbAv) sbAv.innerHTML = `<img src="${url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
      /* Update session */
      const stored = getStoredUser();
      if (stored) {
        stored.avatar_url = url;
        sessionStorage.setItem('lfp_user', JSON.stringify(stored));
        localStorage.setItem('lfp_user', JSON.stringify(stored));
      }
      showToast('Profile photo updated!', 'success');
    } else {
      showToast(json.message || 'Upload failed.', 'error');
    }
  } catch (err) {
    console.error('[admin-profile] avatar error:', err);
    showToast('Upload failed.', 'error');
  } finally {
    progress.classList.remove('show');
    e.target.value = '';
  }
});

/* ── Password strength meter ──────────────────────────────────── */
$('newPw')?.addEventListener('input', () => {
  const pw  = $('newPw').value;
  const bar = $('pw-fill');
  const txt = $('pw-strength-text');
  if (!pw) { bar.className = 'pw-strength-fill'; txt.textContent = ''; return; }

  let score = 0;
  if (pw.length >= 8)             score++;
  if (/[A-Z]/.test(pw))          score++;
  if (/[0-9]/.test(pw))          score++;
  if (/[^A-Za-z0-9]/.test(pw))  score++;

  const levels  = ['', 'Weak', 'Fair', 'Good', 'Strong'];
  const classes = ['', 's1',   's2',   's3',   's4'];
  bar.className = 'pw-strength-fill ' + (classes[score] || '');
  txt.textContent = pw.length >= 8 ? levels[score] || '' : 'Too short';
  hideFieldError('err-newPw');
});

/* ── Change password ──────────────────────────────────────────── */
$('password-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  clearFieldErrors();

  const currentPw = $('currentPw').value;
  const newPw     = $('newPw').value;
  const confirmPw = $('confirmPw').value;

  let valid = true;
  if (!currentPw) { showFieldError('err-currentPw', 'Current password is required'); valid = false; }
  if (!newPw)     { showFieldError('err-newPw', 'New password is required'); valid = false; }
  else if (newPw.length < 8) { showFieldError('err-newPw', 'Password must be at least 8 characters'); valid = false; }
  if (newPw && confirmPw && newPw !== confirmPw) {
    showFieldError('err-confirmPw', 'Passwords do not match'); valid = false;
  }
  if (!valid) return;

  setLoading('pw-btn', 'pw-text', 'pw-spin', true);

  try {
    const res = await fetch(API_BASE + '/api/profile/password', {
      method:      'PUT',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      body: JSON.stringify({
        current_password:         currentPw,
        new_password:             newPw,
        new_password_confirmation: confirmPw,
      }),
    });
    const json = await res.json();

    if (json.success) {
      showToast('Password changed successfully!', 'success');
      $('password-form').reset();
      $('pw-fill').className = 'pw-strength-fill';
      $('pw-strength-text').textContent = '';
    } else {
      const errors = json.errors || {};
      Object.entries(errors).forEach(([k, v]) => {
        const idMap = { current_password: 'err-currentPw', new_password: 'err-newPw' };
        showFieldError(idMap[k] || 'err-newPw', v);
      });
      showToast(json.message || 'Password change failed.', 'error');
    }
  } catch (err) {
    console.error('[admin-profile] password error:', err);
    showToast('Could not update password.', 'error');
  } finally {
    setLoading('pw-btn', 'pw-text', 'pw-spin', false, '<i class="fas fa-key"></i> Update Password');
  }
});

/* ── Password visibility toggles ─────────────────────────────── */
document.querySelectorAll('.pw-eye').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp  = $(btn.dataset.target);
    const icon = btn.querySelector('i');
    const show = inp.type === 'password';
    inp.type   = show ? 'text' : 'password';
    icon.className = `fas ${show ? 'fa-eye-slash' : 'fa-eye'}`;
  });
});

/* ── Helpers ──────────────────────────────────────────────────── */
function showFieldError(id, text) {
  const el = $(id);
  if (!el) return;
  el.textContent = text;
  el.classList.add('show');
}
function hideFieldError(id) {
  const el = $(id);
  if (el) { el.textContent = ''; el.classList.remove('show'); }
}
function clearFieldErrors() {
  document.querySelectorAll('.field-error').forEach(el => {
    el.textContent = ''; el.classList.remove('show');
  });
}
function setLoading(btnId, textId, spinId, loading, resetText = '') {
  const btn = $(btnId);
  const txt = $(textId);
  const sp  = $(spinId);
  if (!btn) return;
  btn.disabled = loading;
  if (sp)  sp.classList.toggle('show', loading);
  if (txt && !loading && resetText) txt.innerHTML = resetText;
}

/* ── Init ─────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', loadProfile);
