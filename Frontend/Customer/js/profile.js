'use strict';

/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — profile.js
   Handles: Auth guard, sidebar tabs, profile load/save,
            avatar upload, change password, orders, order history,
            track order modal.
═══════════════════════════════════════════════════════════════ */

// ── Auth guard ────────────────────────────────────────────────────────────────
const currentUser = getStoredUser();
if (!currentUser) window.location.replace('login.html');

// ── State ─────────────────────────────────────────────────────────────────────
let ordersLoaded  = false;
let historyLoaded = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
function initials(name) {
  return (name || '?').split(' ').map(w => w[0] || '').join('').toUpperCase().slice(0, 2) || '?';
}

function showToast(msg, type = 'info', duration = 4000) {
  const wrap  = document.getElementById('toast-wrap');
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fas ${icons[type]} toast-icon"></i><span>${escHtml(msg)}</span>
    <button class="toast-close"><i class="fas fa-times"></i></button>`;
  t.querySelector('.toast-close').addEventListener('click', () => dismiss(t));
  wrap?.appendChild(t);
  const timer = setTimeout(() => dismiss(t), duration);
  function dismiss(el) {
    clearTimeout(timer);
    el.classList.add('hide');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }
}

function setField(groupId, state, message = '') {
  const fg   = document.getElementById(groupId);
  const wrap = fg?.querySelector('.input-wrap');
  const msg  = fg?.querySelector('.field-msg');
  const stat = wrap?.querySelector('.input-status');
  wrap?.classList.remove('valid', 'invalid');
  if (state) wrap?.classList.add(state);
  if (msg)   { msg.textContent = message; msg.className = `field-msg ${state}`; }
  if (stat)   stat.innerHTML = state === 'valid'
    ? '<i class="fas fa-check-circle"></i>'
    : state === 'invalid' ? '<i class="fas fa-times-circle"></i>' : '';
}

function fmtDate(str) {
  if (!str) return '—';
  const d = new Date(str + (str.length === 10 ? 'T00:00:00' : ''));
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function fmtDateTime(str) {
  if (!str) return '—';
  const d = new Date(str);
  return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
}

function statusLabel(s) {
  const map = {
    schedule: 'Scheduled', confirm: 'Confirmed', collected: 'Collected',
    processing: 'Processing', ready: 'Ready', out_for_delivery: 'Out for Delivery',
    delivered: 'Delivered', canceled: 'Canceled',
  };
  return map[s] || s;
}

// ── Sidebar Tab Switching ─────────────────────────────────────────────────────
function initTabs() {
  document.querySelectorAll('.sb-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      document.querySelectorAll('.sb-tab').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(`panel-${tab}`)?.classList.add('active');

      if (tab === 'orders'  && !ordersLoaded)  { loadActiveOrders();  ordersLoaded  = true; }
      if (tab === 'history' && !historyLoaded) { loadOrderHistory();  historyLoaded = true; }
    });
  });
}

// ── Populate sidebar + avatar card ────────────────────────────────────────────
function populateUI(user) {
  const greeting = initials(user.full_name);

  // Sidebar mini-card
  document.getElementById('sb-name').textContent  = user.full_name  || '—';
  document.getElementById('sb-email').textContent = user.email      || '—';
  document.getElementById('sb-initials').textContent = greeting;

  if (user.avatar_url) {
    const img = document.getElementById('sb-avatar-img');
    if (img) { img.src = user.avatar_url; img.classList.remove('hidden'); }
    document.getElementById('sb-initials')?.classList.add('hidden');
  }

  // Main avatar card
  document.getElementById('avatar-name').textContent     = user.full_name || '—';
  document.getElementById('avatar-email').textContent    = user.email     || '—';
  document.getElementById('avatar-badge').textContent    = user.role      || 'customer';
  document.getElementById('avatar-initials').textContent = greeting;

  if (user.avatar_url) {
    const img = document.getElementById('avatar-img');
    if (img) { img.src = user.avatar_url; img.classList.remove('hidden'); }
    document.getElementById('avatar-initials')?.classList.add('hidden');
  }

  // Navbar greeting handled by nav-actions in HTML (just has Home + Logout)
}

// ── Load Profile from API ─────────────────────────────────────────────────────
async function loadProfile() {
  try {
    const res  = await fetch(API_BASE + '/api/profile', { credentials: 'include', headers: { 'X-CSRF-Token': getCsrfToken() } });
    if (res.status === 401) { clearSession(); window.location.replace('login.html'); return; }

    const json = await res.json();
    const user = json.data?.user;
    const addr = json.data?.address;

    if (user) {
      const merged = { ...currentUser, ...user };
      saveSession(merged, getCsrfToken(), !!localStorage.getItem('lfp_user'));
      populateUI(merged);
    }

    if (addr) {
      document.getElementById('address').value = addr.street      || '';
      document.getElementById('city').value    = addr.city        || '';
      document.getElementById('postal').value  = addr.postal_code || '';
    }

    // Form fields
    if (user) {
      const parts = (user.full_name || '').split(' ');
      document.getElementById('firstName').value = parts[0] || '';
      document.getElementById('lastName').value  = parts.slice(1).join(' ') || '';
      document.getElementById('email').value     = user.email || '';
      document.getElementById('phone').value     = user.phone || '';
    }
  } catch {
    showToast('Could not load profile data.', 'error');
    if (currentUser) populateUI(currentUser);
  }
}

// ── Password toggles ──────────────────────────────────────────────────────────
function initPasswordToggles() {
  document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.querySelector('i').className = `fas ${show ? 'fa-eye-slash' : 'fa-eye'}`;
    });
  });
}

// ── Avatar Upload ─────────────────────────────────────────────────────────────
function initAvatarUpload() {
  document.getElementById('avatar-input')?.addEventListener('change', async e => {
    const file   = e.target.files?.[0];
    const status = document.getElementById('avatar-upload-status');
    if (!file) return;

    if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
      status.textContent = 'Only JPG, PNG, or WebP images are allowed.';
      status.className = 'avatar-upload-status error'; status.classList.remove('hidden'); return;
    }
    if (file.size > 5 * 1024 * 1024) {
      status.textContent = 'File must be under 5 MB.';
      status.className = 'avatar-upload-status error'; status.classList.remove('hidden'); return;
    }

    status.textContent = 'Uploading…'; status.className = 'avatar-upload-status'; status.classList.remove('hidden');

    const formData = new FormData();
    formData.append('avatar', file);

    try {
      const res  = await fetch(API_BASE + '/api/profile/avatar', { method: 'POST', credentials: 'include', headers: { 'X-CSRF-Token': getCsrfToken() }, body: formData });
      const json = await res.json();

      if (res.ok && json.data?.avatar_url) {
        const url = json.data.avatar_url;
        ['avatar-img', 'sb-avatar-img'].forEach(id => {
          const img = document.getElementById(id);
          if (img) { img.src = url; img.classList.remove('hidden'); }
        });
        ['avatar-initials', 'sb-initials'].forEach(id => document.getElementById(id)?.classList.add('hidden'));

        const updated = { ...getStoredUser(), avatar_url: url };
        saveSession(updated, getCsrfToken(), !!localStorage.getItem('lfp_user'));
        status.textContent = 'Photo updated!'; status.className = 'avatar-upload-status success';
        showToast('Profile photo updated!', 'success');
      } else {
        status.textContent = json.message || 'Upload failed.'; status.className = 'avatar-upload-status error';
      }
    } catch { status.textContent = 'Upload failed.'; status.className = 'avatar-upload-status error'; }

    e.target.value = '';
  });
}

// ── Save Personal Info ────────────────────────────────────────────────────────
function initProfileForm() {
  document.getElementById('profile-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const firstName = document.getElementById('firstName').value.trim();
    const lastName  = document.getElementById('lastName').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const street    = document.getElementById('address').value.trim();
    const city      = document.getElementById('city').value.trim();
    const postal    = document.getElementById('postal').value.trim();

    let valid = true;
    if (!firstName) { setField('fg-firstName', 'invalid', 'First name is required'); valid = false; }
    else              setField('fg-firstName', 'valid', '');
    if (!lastName)  { setField('fg-lastName',  'invalid', 'Last name is required');  valid = false; }
    else              setField('fg-lastName', 'valid', '');
    if (phone && !/^\+?[\d\s\-().]{7,20}$/.test(phone)) {
      setField('fg-phone', 'invalid', 'Enter a valid phone number'); valid = false;
    } else { setField('fg-phone', 'valid', ''); }
    if (!valid) { showToast('Please fix the errors above.', 'error'); return; }

    const btn     = document.getElementById('save-btn');
    const btnText = document.getElementById('save-btn-text');
    const spinner = document.getElementById('save-spinner');
    btn.disabled = true; btnText.textContent = 'Saving…'; spinner?.classList.remove('hidden');

    try {
      const res  = await fetch(API_BASE + '/api/profile', {
        method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
        body: JSON.stringify({ first_name: firstName, last_name: lastName, phone, street, city, postal_code: postal }),
      });
      const json = await res.json();
      if (res.ok) {
        const updated = { ...getStoredUser(), full_name: `${firstName} ${lastName}`, phone };
        saveSession(updated, getCsrfToken(), !!localStorage.getItem('lfp_user'));
        document.getElementById('avatar-name').textContent     = updated.full_name;
        document.getElementById('avatar-initials').textContent = initials(updated.full_name);
        document.getElementById('sb-name').textContent         = updated.full_name;
        document.getElementById('sb-initials').textContent     = initials(updated.full_name);
        showToast('Profile updated successfully!', 'success');
      } else {
        if (json.errors) {
          const m = { first_name:'fg-firstName', last_name:'fg-lastName', phone:'fg-phone', street:'fg-address', city:'fg-city', postal_code:'fg-postal' };
          Object.entries(json.errors).forEach(([f, msg]) => { if (m[f]) setField(m[f], 'invalid', msg); });
        }
        showToast(json.message || 'Update failed.', 'error');
      }
    } catch { showToast('Could not reach the server.', 'error'); }
    finally {
      btn.disabled = false;
      btnText.innerHTML = '<i class="fas fa-save"></i> Save Changes';
      spinner?.classList.add('hidden');
    }
  });
}

// ── Change Password ───────────────────────────────────────────────────────────
function initPasswordForm() {
  document.getElementById('password-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const current = document.getElementById('currentPw').value;
    const newPw   = document.getElementById('newPw').value;
    const confirm = document.getElementById('confirmPw').value;

    let valid = true;
    if (!current) { setField('fg-current-pw', 'invalid', 'Enter your current password'); valid = false; }
    else            setField('fg-current-pw', 'valid', '');
    if (!newPw || newPw.length < 8) { setField('fg-new-pw', 'invalid', 'Minimum 8 characters'); valid = false; }
    else                              setField('fg-new-pw', 'valid', '');
    if (newPw !== confirm) { setField('fg-confirm-pw', 'invalid', 'Passwords do not match'); valid = false; }
    else                     setField('fg-confirm-pw', 'valid', '');
    if (!valid) { showToast('Please fix the errors above.', 'error'); return; }

    const btn     = document.getElementById('pw-save-btn');
    const btnText = document.getElementById('pw-save-text');
    const spinner = document.getElementById('pw-spinner');
    btn.disabled = true; btnText.textContent = 'Updating…'; spinner?.classList.remove('hidden');

    try {
      const res  = await fetch(API_BASE + '/api/profile/password', {
        method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
        body: JSON.stringify({ current_password: current, new_password: newPw, new_password_confirmation: confirm }),
      });
      const json = await res.json();
      if (res.ok) {
        document.getElementById('password-form').reset();
        ['fg-current-pw','fg-new-pw','fg-confirm-pw'].forEach(id => setField(id, '', ''));
        showToast('Password changed successfully!', 'success');
      } else {
        if (json.errors?.current_password) setField('fg-current-pw', 'invalid', json.errors.current_password);
        showToast(json.message || 'Password change failed.', 'error');
      }
    } catch { showToast('Could not reach the server.', 'error'); }
    finally {
      btn.disabled = false;
      btnText.innerHTML = '<i class="fas fa-key"></i> Update Password';
      spinner?.classList.add('hidden');
    }
  });
}

// ── Render Order Card ─────────────────────────────────────────────────────────
function renderOrderCard(o, showTrack = true) {
  const statusCls = `status-${o.status}`;
  const canReview = !showTrack && o.status === 'delivered' && !o.has_review;
  const card = document.createElement('div');
  card.className = 'order-card';
  card.innerHTML = `
    ${canReview ? `<div style="background:#FEF9C3;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#854D0E;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-star" style="color:#F59E0B;"></i> Your feedback is requested — rate this order!
    </div>` : ''}
    <div class="order-card-head">
      <div class="order-svc-icon">${escHtml(o.service_icon || '🧺')}</div>
      <div class="order-card-meta">
        <div class="order-number">${escHtml(o.order_number)}</div>
        <div class="order-svc-name">${escHtml(o.service_name)}</div>
      </div>
      <span class="order-status-badge ${statusCls}">${statusLabel(o.status)}</span>
    </div>
    <div class="order-card-details">
      <div class="order-detail-item">
        <span class="order-detail-label">Pickup Date</span>
        <span class="order-detail-value">${fmtDate(o.pickup_date)}</span>
      </div>
      <div class="order-detail-item">
        <span class="order-detail-label">Delivery Date</span>
        <span class="order-detail-value">${fmtDate(o.delivery_date)}</span>
      </div>
      <div class="order-detail-item">
        <span class="order-detail-label">Payment</span>
        <span class="order-detail-value">${o.payment_method === 'card' ? 'Card' : o.payment_method === 'cod' ? 'Cash on Delivery' : escHtml(o.payment_method)}</span>
      </div>
      <div class="order-detail-item">
        <span class="order-detail-label">Placed On</span>
        <span class="order-detail-value">${fmtDate(o.created_at)}</span>
      </div>
    </div>
    <div class="order-card-foot">
      <div class="order-total">Total: <span>LKR ${parseFloat(o.total_amount).toFixed(2)}</span></div>
      <div class="order-card-actions">
        ${showTrack ? `<button class="btn-track" data-order-id="${o.order_id}" data-order-num="${escHtml(o.order_number)}">
          <i class="fas fa-map-marker-alt"></i> Track Order
        </button>` : ''}
        ${!showTrack ? `<button class="btn-rebook">
          <i class="fas fa-redo"></i> Rebook
        </button>` : ''}
        ${canReview ? `<button class="btn-rate" style="background:#F59E0B;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;">
          <i class="fas fa-star"></i> Rate Order
        </button>` : ''}
      </div>
    </div>
  `;

  // Track button
  card.querySelector('.btn-track')?.addEventListener('click', () => openTrackModal(o.order_id, o.order_number));

  // Rebook button
  card.querySelector('.btn-rebook')?.addEventListener('click', () => {
    window.location.href = 'services.html';
  });

  // Rate button
  card.querySelector('.btn-rate')?.addEventListener('click', () => {
    openReviewModal(o.order_id, o.order_number, o.service_name);
  });

  return card;
}

// ── Load Active Orders ────────────────────────────────────────────────────────
async function loadActiveOrders() {
  const container = document.getElementById('orders-container');
  container.innerHTML = '<div class="orders-loading"><div class="loading-spinner"></div><p>Loading orders…</p></div>';

  try {
    const res  = await fetch(API_BASE + '/api/customer/orders/active', { credentials: 'include', headers: { 'X-CSRF-Token': getCsrfToken() } });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const orders = data.data.orders;
    container.innerHTML = '';

    if (!orders.length) {
      container.innerHTML = `<div class="orders-empty">
        <i class="fas fa-box-open"></i>
        <h3>No active orders</h3>
        <p>You don't have any orders in progress right now.</p>
      </div>`;
      return;
    }

    // Update badge count
    const badge = document.getElementById('active-count');
    if (badge) { badge.textContent = orders.length; badge.classList.remove('hidden'); }

    orders.forEach(o => container.appendChild(renderOrderCard(o, true)));
  } catch (e) {
    container.innerHTML = `<div class="orders-empty">
      <i class="fas fa-exclamation-circle"></i>
      <h3>Could not load orders</h3>
      <p>${escHtml(e.message || 'Please try again later.')}</p>
    </div>`;
  }
}

// ── Load Order History ────────────────────────────────────────────────────────
async function loadOrderHistory() {
  const container = document.getElementById('history-container');
  container.innerHTML = '<div class="orders-loading"><div class="loading-spinner"></div><p>Loading history…</p></div>';

  try {
    const res  = await fetch(API_BASE + '/api/customer/orders/history', { credentials: 'include', headers: { 'X-CSRF-Token': getCsrfToken() } });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const orders = data.data.orders;
    container.innerHTML = '';

    if (!orders.length) {
      container.innerHTML = `<div class="orders-empty">
        <i class="fas fa-history"></i>
        <h3>No order history</h3>
        <p>Your completed and canceled orders will appear here.</p>
      </div>`;
      return;
    }

    orders.forEach(o => container.appendChild(renderOrderCard(o, false)));
  } catch (e) {
    container.innerHTML = `<div class="orders-empty">
      <i class="fas fa-exclamation-circle"></i>
      <h3>Could not load history</h3>
      <p>${escHtml(e.message || 'Please try again later.')}</p>
    </div>`;
  }
}

// ── Track Order Modal ─────────────────────────────────────────────────────────
const TRACK_STEPS = [
  { key: 'schedule',         label: 'Scheduled',         icon: 'fa-calendar-check' },
  { key: 'confirm',          label: 'Confirmed',          icon: 'fa-check-circle'   },
  { key: 'collected',        label: 'Collected',          icon: 'fa-box'            },
  { key: 'processing',       label: 'Processing',         icon: 'fa-cog'            },
  { key: 'ready',            label: 'Ready',              icon: 'fa-star'           },
  { key: 'out_for_delivery', label: 'Out for Delivery',   icon: 'fa-truck'          },
  { key: 'delivered',        label: 'Delivered',          icon: 'fa-home'           },
];

async function openTrackModal(orderId, orderNumber) {
  const overlay = document.getElementById('track-overlay');
  const body    = document.getElementById('track-body');
  const numEl   = document.getElementById('track-order-num');

  numEl.textContent = orderNumber;
  body.innerHTML = '<div class="orders-loading"><div class="loading-spinner"></div></div>';
  overlay.classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  try {
    const res  = await fetch(`${API_BASE}/api/orders/${orderId}`, { credentials: 'include', headers: { 'X-CSRF-Token': getCsrfToken() } });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const order    = data.data.order;
    const timeline = data.data.timeline || [];
    const isCanceled = order.status === 'canceled';

    // Build a map of status → history entry
    const histMap = {};
    timeline.forEach(t => { histMap[t.status] = t; });

    // Determine which steps are done/current
    const currentIdx = TRACK_STEPS.findIndex(s => s.key === order.status);

    let stepsHtml = '';
    TRACK_STEPS.forEach((step, i) => {
      const hist = histMap[step.key];
      const isDone    = isCanceled ? hist != null : i < currentIdx;
      const isCurrent = !isCanceled && i === currentIdx;
      let cls = isDone ? 'done' : (isCurrent ? 'current' : '');

      stepsHtml += `
        <div class="track-step ${cls}">
          <div class="track-dot"><i class="fas ${step.icon}"></i></div>
          <div class="track-step-info">
            <div class="track-step-label">${step.label}</div>
            ${hist ? `<div class="track-step-time">${fmtDateTime(hist.created_at)}</div>` : ''}
            ${hist?.note ? `<div class="track-step-note">${escHtml(hist.note)}</div>` : ''}
          </div>
        </div>`;
    });

    if (isCanceled) {
      const cancelHist = histMap['canceled'];
      stepsHtml += `
        <div class="track-step canceled-dot">
          <div class="track-dot"><i class="fas fa-times"></i></div>
          <div class="track-step-info">
            <div class="track-step-label">Canceled</div>
            ${cancelHist ? `<div class="track-step-time">${fmtDateTime(cancelHist.created_at)}</div>` : ''}
            ${cancelHist?.note ? `<div class="track-step-note">${escHtml(cancelHist.note)}</div>` : ''}
          </div>
        </div>`;
    }

    body.innerHTML = `
      <div class="track-timeline">${stepsHtml}</div>
      <div class="track-info-grid">
        <div class="track-info-item">
          <span class="track-info-label">Pickup Address</span>
          <span class="track-info-value">${escHtml(order.pickup_address || '—')}</span>
        </div>
        <div class="track-info-item">
          <span class="track-info-label">Delivery Address</span>
          <span class="track-info-value">${escHtml(order.delivery_address || '—')}</span>
        </div>
        <div class="track-info-item">
          <span class="track-info-label">Pickup Date</span>
          <span class="track-info-value">${fmtDate(order.pickup_date)}</span>
        </div>
        <div class="track-info-item">
          <span class="track-info-label">Delivery Date</span>
          <span class="track-info-value">${fmtDate(order.delivery_date)}</span>
        </div>
        <div class="track-info-item">
          <span class="track-info-label">Subtotal</span>
          <span class="track-info-value">LKR ${parseFloat(order.subtotal).toFixed(2)}</span>
        </div>
        <div class="track-info-item">
          <span class="track-info-label">Total</span>
          <span class="track-info-value" style="color:#2563eb;font-weight:800;">LKR ${parseFloat(order.total_amount).toFixed(2)}</span>
        </div>
      </div>`;
  } catch (e) {
    body.innerHTML = `<div class="orders-empty"><i class="fas fa-exclamation-circle"></i><p>${escHtml(e.message || 'Could not load tracking info.')}</p></div>`;
  }
}

function closeTrackModal() {
  document.getElementById('track-overlay')?.classList.add('hidden');
  document.body.style.overflow = '';
}

// ── Review Modal ──────────────────────────────────────────────────────────────
let _reviewOrderId = null;
const STAR_LABELS = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];

function openReviewModal(orderId, orderNumber, serviceName) {
  _reviewOrderId = orderId;
  document.getElementById('review-order-num').textContent = orderNumber;
  document.getElementById('review-service-name').textContent = serviceName;
  document.getElementById('review-title').value = '';
  document.getElementById('review-text').value = '';
  document.getElementById('star-label').textContent = '';

  // Reset stars
  document.querySelectorAll('#star-picker span').forEach(s => {
    s.style.color = '#E2E8F0';
  });

  document.getElementById('review-overlay')?.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeReviewModal() {
  _reviewOrderId = null;
  document.getElementById('review-overlay')?.classList.add('hidden');
  document.body.style.overflow = '';
}

function initReviewModal() {
  const picker = document.getElementById('star-picker');
  const label  = document.getElementById('star-label');
  let selectedRating = 0;

  if (picker) {
    const stars = picker.querySelectorAll('span');

    stars.forEach(star => {
      star.addEventListener('mouseover', () => {
        const val = parseInt(star.dataset.val, 10);
        stars.forEach(s => {
          s.style.color = parseInt(s.dataset.val, 10) <= val ? '#F59E0B' : '#E2E8F0';
        });
        if (label) label.textContent = STAR_LABELS[val];
      });

      star.addEventListener('mouseout', () => {
        stars.forEach(s => {
          s.style.color = parseInt(s.dataset.val, 10) <= selectedRating ? '#F59E0B' : '#E2E8F0';
        });
        if (label) label.textContent = selectedRating ? STAR_LABELS[selectedRating] : '';
      });

      star.addEventListener('click', () => {
        selectedRating = parseInt(star.dataset.val, 10);
        stars.forEach(s => {
          s.style.color = parseInt(s.dataset.val, 10) <= selectedRating ? '#F59E0B' : '#E2E8F0';
        });
        if (label) label.textContent = STAR_LABELS[selectedRating];
      });
    });
  }

  document.getElementById('review-submit-btn')?.addEventListener('click', async () => {
    if (!_reviewOrderId) return;
    if (!selectedRating) { showToast('Please select a star rating.', 'warning'); return; }
    const text = document.getElementById('review-text')?.value.trim();
    if (!text) { showToast('Please write a review.', 'warning'); return; }

    const btn = document.getElementById('review-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Submitting…';

    try {
      const res = await fetch(API_BASE + '/api/reviews', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
        body: JSON.stringify({
          order_id: _reviewOrderId,
          rating: selectedRating,
          title: document.getElementById('review-title')?.value.trim() || '',
          review_text: text,
        }),
      });
      const data = await res.json();
      if (!data.success) {
        showToast(data.message || 'Failed to submit review.', 'error');
        btn.disabled = false; btn.textContent = 'Submit Review';
        return;
      }
      showToast('Thank you! Your review is pending approval.', 'success');
      closeReviewModal();
      selectedRating = 0;
      // Reload history to remove the Rate button
      loadOrderHistory();
    } catch {
      showToast('Network error. Please try again.', 'error');
      btn.disabled = false; btn.textContent = 'Submit Review';
    }
  });

  document.getElementById('review-close')?.addEventListener('click', closeReviewModal);
  document.getElementById('review-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeReviewModal();
  });
}

// ── Logout ────────────────────────────────────────────────────────────────────
function initLogout() {
  const handler = async () => {
    try {
      await fetch(API_BASE + '/api/auth/logout', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() } });
    } catch {}
    clearSession();
    window.location.replace('login.html');
  };
  document.getElementById('nav-logout-btn')?.addEventListener('click', handler);
  document.getElementById('sb-logout-btn')?.addEventListener('click', handler);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (currentUser) populateUI(currentUser);

  initTabs();
  initPasswordToggles();
  initAvatarUpload();
  initProfileForm();
  initPasswordForm();
  initLogout();
  initReviewModal();

  // Track modal close
  document.getElementById('track-close')?.addEventListener('click', closeTrackModal);
  document.getElementById('track-overlay')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeTrackModal();
  });

  // ESC closes modals
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeTrackModal(); closeReviewModal(); } });

  loadProfile();
});
