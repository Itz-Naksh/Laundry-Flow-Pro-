/* ── Schedule Pickup page ── */
'use strict';

let selectedService = null;
let savedAddresses  = [];
let pickupAddrId    = 0;   // 0 = manually-typed (new) address
let deliveryAddrId  = 0;

// ── Bootstrap ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  guardAuth();
  initNavbar();
  loadSelectedService();
  loadSavedAddresses();
  initDateDefaults();
  initSameAddressToggle();
  initMeasurementListeners();
  document.getElementById('pickup-form').addEventListener('submit', handleSubmit);
});

// ── Saved addresses (dropdown + manual entry) ─────────────────────────────────
async function loadSavedAddresses() {
  try {
    const res  = await fetch(`${API_BASE}/api/addresses`, { credentials: 'include' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.data.addresses) || !data.data.addresses.length) return;

    savedAddresses = data.data.addresses;
    populateAddressSelect('pickup');
    populateAddressSelect('delivery');

    // Pre-select the default address for pickup as a convenience
    const def = savedAddresses.find(a => a.is_default) || savedAddresses[0];
    if (def) {
      document.getElementById('pickup_address_select').value = String(def.id);
      applySavedAddress('pickup', def.id);
    }
  } catch { /* addresses are an enhancement — never block booking */ }
}

function populateAddressSelect(which) {
  const row = document.getElementById(`${which}-saved-row`);
  const sel = document.getElementById(`${which}_address_select`);
  if (!row || !sel) return;

  sel.innerHTML =
    savedAddresses.map(a =>
      `<option value="${a.id}">${escHtml(a.label)} — ${escHtml(a.formatted)}</option>`
    ).join('') +
    `<option value="new">➕ Enter a new address</option>`;

  row.style.display = '';

  sel.addEventListener('change', () => {
    if (sel.value === 'new') clearAddress(which);
    else applySavedAddress(which, parseInt(sel.value, 10));
  });
}

function applySavedAddress(which, id) {
  const a = savedAddresses.find(x => x.id === id);
  if (!a) return;
  const input = document.getElementById(`${which}_address`);
  input.value = a.formatted;
  input.setAttribute('readonly', 'readonly');
  if (which === 'pickup') pickupAddrId = id; else deliveryAddrId = id;

  if (which === 'pickup' && document.getElementById('same-address')?.checked) {
    syncDeliveryFromPickup();
  }
}

function clearAddress(which) {
  const input = document.getElementById(`${which}_address`);
  input.value = '';
  input.removeAttribute('readonly');
  input.focus();
  if (which === 'pickup') pickupAddrId = 0; else deliveryAddrId = 0;
}

function syncDeliveryFromPickup() {
  const pickupEl   = document.getElementById('pickup_address');
  const deliveryEl = document.getElementById('delivery_address');
  deliveryEl.value = pickupEl.value;
  deliveryAddrId   = pickupAddrId;
}

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function guardAuth() {
  const user = getStoredUser();
  if (!user) window.location.href = `login.html?return=${encodeURIComponent('services.html')}`;
}

// ── Navbar ────────────────────────────────────────────────────────────────────
function initNavbar() {
  const user = getStoredUser();
  if (user) {
    const auth = document.getElementById('nav-auth');
    if (auth) auth.style.display = 'flex';
    const g = document.getElementById('nav-user-greeting');
    if (g) g.textContent = user.full_name?.split(' ')[0] || 'Profile';
  }

  const logoutBtn = document.getElementById('nav-logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try {
        await fetch(`${API_BASE}/api/auth/logout`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-CSRF-Token': getCsrfToken() },
        });
      } finally {
        clearSession();
        window.location.href = 'login.html';
      }
    });
  }
}

// ── Load service from sessionStorage + API ────────────────────────────────────
async function loadSelectedService() {
  const sid = sessionStorage.getItem('lfp_booking_service_id');
  if (!sid) { window.location.href = 'services.html'; return; }

  try {
    const res  = await fetch(`${API_BASE}/api/services`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const service = data.data.services.find(s => s.id === parseInt(sid));
    if (!service) { window.location.href = 'services.html'; return; }

    selectedService = service;
    renderLockedService(service);
    showMeasurementField(service.unit);
  } catch (e) {
    showToast('Failed to load service details.', 'error');
    setTimeout(() => window.location.href = 'services.html', 1800);
  }
}

function renderLockedService(s) {
  document.getElementById('svc-icon').textContent = s.icon || '🧺';
  document.getElementById('svc-name').textContent = s.name;
  const unitLabel = { lb: 'per lb', item: 'per item', bag: 'per bag' };
  document.getElementById('svc-meta').textContent =
    `LKR${s.base_price.toFixed(2)} ${unitLabel[s.unit] || s.unit}  ·  ${s.turnaround_h}h turnaround`;
}

// ── Dynamic measurement field ─────────────────────────────────────────────────
function showMeasurementField(unit) {
  document.getElementById('field-weight').style.display = unit === 'lb'   ? '' : 'none';
  document.getElementById('field-items').style.display  = unit === 'item' ? '' : 'none';
  document.getElementById('field-bags').style.display   = unit === 'bag'  ? '' : 'none';
  updateEstimate();
}

function initMeasurementListeners() {
  ['weight_lbs', 'item_count', 'bag_count'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateEstimate);
  });
}

function updateEstimate() {
  if (!selectedService) return;

  const unit   = selectedService.unit;
  const price  = selectedService.base_price;
  let qty = 0;

  if (unit === 'lb')   qty = parseFloat(document.getElementById('weight_lbs')?.value) || 0;
  if (unit === 'item') qty = parseInt(document.getElementById('item_count')?.value)  || 0;
  if (unit === 'bag')  qty = parseInt(document.getElementById('bag_count')?.value)   || 0;

  const box = document.getElementById('estimate-box');
  if (qty <= 0) { box.style.display = 'none'; return; }

  const subtotal = price * qty;
  const tax      = subtotal * 0.08;
  const total    = subtotal + tax;

  document.getElementById('est-subtotal').textContent = `LKR${subtotal.toFixed(2)}`;
  document.getElementById('est-tax').textContent      = `LKR${tax.toFixed(2)}`;
  document.getElementById('est-total').textContent    = `LKR${total.toFixed(2)}`;
  box.style.display = '';
}

// ── Date defaults ─────────────────────────────────────────────────────────────
function initDateDefaults() {
  const today = new Date();
  const fmtDate = d => d.toISOString().slice(0,10);

  const pickupEl   = document.getElementById('pickup_date');
  const deliveryEl = document.getElementById('delivery_date');

  // Default pickup = tomorrow
  const tomorrow = new Date(today);
  tomorrow.setDate(today.getDate() + 1);
  pickupEl.value = fmtDate(tomorrow);
  pickupEl.min   = fmtDate(today);

  // Default delivery = pickup + 2 days
  const defaultDelivery = new Date(tomorrow);
  defaultDelivery.setDate(tomorrow.getDate() + 2);
  deliveryEl.value = fmtDate(defaultDelivery);
  deliveryEl.min   = fmtDate(tomorrow);

  // Keep delivery min in sync with pickup
  pickupEl.addEventListener('change', () => {
    const pd = new Date(pickupEl.value);
    deliveryEl.min = fmtDate(pd);
    if (deliveryEl.value && new Date(deliveryEl.value) <= pd) {
      const next = new Date(pd);
      next.setDate(pd.getDate() + 1);
      deliveryEl.value = fmtDate(next);
    }
  });
}

// ── Same-address toggle ───────────────────────────────────────────────────────
function initSameAddressToggle() {
  const checkbox   = document.getElementById('same-address');
  const addrRow    = document.getElementById('delivery-addr-row');
  const pickupEl   = document.getElementById('pickup_address');
  const deliveryEl = document.getElementById('delivery_address');

  const savedRow = document.getElementById('delivery-saved-row');

  checkbox.addEventListener('change', () => {
    if (checkbox.checked) {
      syncDeliveryFromPickup();
      addrRow.style.display = 'none';
      if (savedRow) savedRow.style.display = 'none';
    } else {
      addrRow.style.display = '';
      if (savedRow && savedAddresses.length) savedRow.style.display = '';
    }
  });

  // Keep delivery in sync while typing if checkbox is checked
  pickupEl.addEventListener('input', () => {
    if (checkbox.checked) syncDeliveryFromPickup();
  });
}

// ── Form submit → save to sessionStorage → checkout ──────────────────────────
function handleSubmit(e) {
  e.preventDefault();

  if (!selectedService) { showToast('No service selected.', 'error'); return; }

  const unit   = selectedService.unit;
  const fields = {
    service_id:    selectedService.id,
    pickup_date:   document.getElementById('pickup_date').value,
    pickup_time:   document.getElementById('pickup_time').value,
    pickup_address: document.getElementById('pickup_address').value.trim(),
    pickup_address_id: pickupAddrId || null,
    delivery_date: document.getElementById('delivery_date').value,
    delivery_time: document.getElementById('delivery_time').value,
    delivery_address: document.getElementById('delivery_address').value.trim(),
    delivery_address_id: deliveryAddrId || null,
    notes:           document.getElementById('notes').value.trim(),
    special_instructions: document.getElementById('special_instructions').value.trim(),
    weight_lbs:  unit === 'lb'   ? parseFloat(document.getElementById('weight_lbs').value)  : null,
    item_count:  unit === 'item' ? parseInt(document.getElementById('item_count').value)    : null,
    bag_count:   unit === 'bag'  ? parseInt(document.getElementById('bag_count').value)     : null,
  };

  // Validation
  if (!fields.pickup_date)    { showToast('Please select a pickup date.',    'error'); return; }
  if (!fields.pickup_time)    { showToast('Please select a pickup time.',    'error'); return; }
  if (!fields.pickup_address) { showToast('Please enter a pickup address.',  'error'); return; }
  if (!fields.delivery_address) { showToast('Please enter a delivery address.', 'error'); return; }

  if (unit === 'lb'   && (!fields.weight_lbs || fields.weight_lbs <= 0))  { showToast('Please enter weight in lbs.',    'error'); return; }
  if (unit === 'item' && (!fields.item_count || fields.item_count <= 0))  { showToast('Please enter number of items.', 'error'); return; }
  if (unit === 'bag'  && (!fields.bag_count  || fields.bag_count  <= 0))  { showToast('Please enter number of bags.',  'error'); return; }

  // Estimate pricing (server will recalculate, this is for the summary display)
  const qty      = fields.weight_lbs ?? fields.item_count ?? fields.bag_count;
  const subtotal = selectedService.base_price * qty;
  const tax      = subtotal * 0.08;
  const total    = subtotal + tax;

  sessionStorage.setItem('lfp_booking_draft', JSON.stringify({
    ...fields,
    service_name:  selectedService.name,
    service_icon:  selectedService.icon,
    service_unit:  selectedService.unit,
    service_price: selectedService.base_price,
    subtotal, tax, total,
  }));

  window.location.href = 'checkout.html';
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(message, type = 'info', duration = 4000) {
  const wrap = document.getElementById('toast-wrap');
  if (!wrap) return;
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="fas ${icons[type]||icons.info} toast-icon"></i><span>${message}</span>
    <button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>`;
  el.querySelector('.toast-close').addEventListener('click', () => dismiss(el));
  wrap.appendChild(el);
  const t = setTimeout(() => dismiss(el), duration);
  function dismiss(node) { clearTimeout(t); node.classList.add('hide'); setTimeout(() => node.remove(), 320); }
}
