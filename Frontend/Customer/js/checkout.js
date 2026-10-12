/* ── Checkout page ── */
'use strict';

const TAX_RATE = 0.08;
let draft        = null;
let promoData    = null;   // { id, code, discount_type, discount_value, min_order_value }
let paymentMethod = 'cod';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  guardAuth();
  initNavbar();
  loadDraft();
  initPromo();
  document.getElementById('btn-place-order').addEventListener('click', handlePlaceOrder);
  initCardFormatting();
});

function guardAuth() {
  const user = getStoredUser();
  if (!user) window.location.href = `login.html?return=${encodeURIComponent('checkout.html')}`;
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

// ── Load draft from sessionStorage ───────────────────────────────────────────
function loadDraft() {
  const raw = sessionStorage.getItem('lfp_booking_draft');
  if (!raw) { window.location.href = 'schedule-pickup.html'; return; }

  try {
    draft = JSON.parse(raw);
  } catch {
    window.location.href = 'schedule-pickup.html';
    return;
  }

  const unitLabel = { lb: 'lbs', item: 'items', bag: 'bags' };
  const qty = draft.weight_lbs ?? draft.item_count ?? draft.bag_count;
  const unit = draft.service_unit;

  document.getElementById('co-svc-icon').textContent = draft.service_icon || '🧺';
  document.getElementById('co-svc-name').textContent = draft.service_name;
  document.getElementById('co-svc-meta').textContent =
    `LKR${parseFloat(draft.service_price).toFixed(2)} ${unitLabel[unit] || unit}  ·  ${qty} ${unitLabel[unit] || unit}`;

  document.getElementById('co-pickup-date').textContent =
    `${formatDate(draft.pickup_date)}${draft.pickup_time ? ' at ' + fmt12h(draft.pickup_time) : ''}`;
  document.getElementById('co-pickup-addr').textContent  = draft.pickup_address;
  document.getElementById('co-delivery-date').textContent =
    `${draft.delivery_date ? formatDate(draft.delivery_date) : 'TBD'}${draft.delivery_time ? ' at ' + fmt12h(draft.delivery_time) : ''}`;
  document.getElementById('co-delivery-addr').textContent = draft.delivery_address;

  const sub = parseFloat(draft.service_price) * qty;
  document.getElementById('co-qty-label').textContent =
    `${qty} ${unitLabel[unit] || unit} × LKR${parseFloat(draft.service_price).toFixed(2)}`;

  updatePricing(sub, 0, null);
}

// ── Pricing recalculation ─────────────────────────────────────────────────────
function updatePricing(subtotal, discount, promoCode) {
  const taxable = Math.max(0, subtotal - discount);
  const tax     = taxable * TAX_RATE;
  const total   = taxable + tax;

  document.getElementById('co-subtotal').textContent = `LKR${subtotal.toFixed(2)}`;
  document.getElementById('co-tax').textContent      = `LKR${tax.toFixed(2)}`;
  document.getElementById('co-total').textContent    = `LKR${total.toFixed(2)}`;

  const discountRow = document.getElementById('discount-row');
  if (discount > 0 && promoCode) {
    document.getElementById('co-promo-code-label').textContent = promoCode;
    document.getElementById('co-discount').textContent = `-LKR${discount.toFixed(2)}`;
    discountRow.style.display = '';
  } else {
    discountRow.style.display = 'none';
  }
}

function recalcWithPromo() {
  if (!draft) return;
  const qty      = draft.weight_lbs ?? draft.item_count ?? draft.bag_count;
  const subtotal = parseFloat(draft.service_price) * qty;
  let discount   = 0;

  if (promoData && subtotal >= promoData.min_order_value) {
    if (promoData.discount_type === 'percent') {
      discount = subtotal * (promoData.discount_value / 100);
    } else {
      discount = Math.min(promoData.discount_value, subtotal);
    }
  }

  updatePricing(subtotal, discount, promoData?.code ?? null);
}

// ── Promo code ────────────────────────────────────────────────────────────────
function initPromo() {
  document.getElementById('btn-apply-promo').addEventListener('click', applyPromo);
  document.getElementById('promo_code').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); applyPromo(); }
  });
}

async function applyPromo() {
  const code = document.getElementById('promo_code').value.trim().toUpperCase();
  const msg  = document.getElementById('promo-msg');

  if (!code) { showPromoMsg('Please enter a promo code.', 'error'); return; }

  const btn = document.getElementById('btn-apply-promo');
  btn.disabled = true;
  btn.textContent = '…';

  try {
    const res  = await fetch(`${API_BASE}/api/promo/validate?code=${encodeURIComponent(code)}`, {
      credentials: 'include',
    });
    const data = await res.json();

    if (!data.success) {
      promoData = null;
      recalcWithPromo();
      showPromoMsg(data.message, 'error');
      return;
    }

    promoData = data.data;
    const qty      = draft.weight_lbs ?? draft.item_count ?? draft.bag_count;
    const subtotal = parseFloat(draft.service_price) * qty;

    if (subtotal < promoData.min_order_value) {
      promoData = null;
      recalcWithPromo();
      showPromoMsg(
        `Minimum order of $${promoData?.min_order_value?.toFixed(2) ?? data.data.min_order_value.toFixed(2)} required.`,
        'error'
      );
      return;
    }

    recalcWithPromo();
    const discLabel = promoData.discount_type === 'percent'
      ? `${promoData.discount_value}% off`
      : `$${promoData.discount_value.toFixed(2)} off`;
    showPromoMsg(`${promoData.description || promoData.code} — ${discLabel} applied!`, 'success');

  } catch {
    showPromoMsg('Failed to validate promo code.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Apply';
  }
}

function showPromoMsg(text, type) {
  const el = document.getElementById('promo-msg');
  el.textContent = text;
  el.className   = `promo-msg ${type}`;
}

// ── Payment method selection ──────────────────────────────────────────────────
function selectPayment(method) {
  paymentMethod = method;
  document.getElementById('opt-card').classList.toggle('selected', method === 'card');
  document.getElementById('opt-cod').classList.toggle('selected',  method === 'cod');
  document.getElementById('card-fields').classList.toggle('visible', method === 'card');
}

// ── Card input formatting ─────────────────────────────────────────────────────
function initCardFormatting() {
  const cardEl = document.getElementById('card_number');
  cardEl.addEventListener('input', () => {
    let v = cardEl.value.replace(/\D/g, '').slice(0, 16);
    cardEl.value = v.replace(/(.{4})/g, '$1 ').trim();
  });

  const expiryEl = document.getElementById('expiry');
  expiryEl.addEventListener('input', () => {
    let v = expiryEl.value.replace(/\D/g, '').slice(0, 4);
    if (v.length >= 3) v = v.slice(0,2) + ' / ' + v.slice(2);
    expiryEl.value = v;
  });

  const cvvEl = document.getElementById('cvv');
  cvvEl.addEventListener('input', () => {
    cvvEl.value = cvvEl.value.replace(/\D/g, '').slice(0, 4);
  });
}

// ── Place order ───────────────────────────────────────────────────────────────
async function handlePlaceOrder() {
  if (!draft) return;

  // Card validation
  if (paymentMethod === 'card') {
    if (!document.getElementById('card_holder').value.trim()) {
      showToast('Please enter card holder name.', 'error'); return;
    }
    if (document.getElementById('card_number').value.replace(/\D/g,'').length < 16) {
      showToast('Please enter a valid 16-digit card number.', 'error'); return;
    }
    const exp = document.getElementById('expiry').value.replace(/\s/g,'');
    if (!/^\d{2}\/\d{2}$/.test(exp)) {
      showToast('Please enter expiry as MM / YY.', 'error'); return;
    }
    if (document.getElementById('cvv').value.replace(/\D/g,'').length < 3) {
      showToast('Please enter a valid CVV.', 'error'); return;
    }
  }

  const btn = document.getElementById('btn-place-order');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing Order…';

  try {
    const payload = {
      service_id:           draft.service_id,
      pickup_date:          draft.pickup_date,
      pickup_time:          draft.pickup_time,
      pickup_address:       draft.pickup_address,
      pickup_address_id:    draft.pickup_address_id || undefined,
      delivery_date:        draft.delivery_date || null,
      delivery_time:        draft.delivery_time || null,
      delivery_address:     draft.delivery_address,
      delivery_address_id:  draft.delivery_address_id || undefined,
      weight_lbs:           draft.weight_lbs   ?? undefined,
      item_count:           draft.item_count   ?? undefined,
      bag_count:            draft.bag_count    ?? undefined,
      notes:                draft.notes        || undefined,
      special_instructions: draft.special_instructions || undefined,
      promo_code:           promoData ? promoData.code : undefined,
      payment_method:       paymentMethod,
    };

    const res  = await fetch(`${API_BASE}/api/orders`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (!data.success) throw new Error(data.message);

    // Clear booking session data
    sessionStorage.removeItem('lfp_booking_service_id');
    sessionStorage.removeItem('lfp_booking_draft');

    // Show confirmation
    document.getElementById('checkout-view').style.display = 'none';
    document.getElementById('confirm-view').style.display  = '';
    document.getElementById('confirm-order-number').textContent = data.data.order_number;

  } catch (e) {
    showToast(e.message || 'Failed to place order. Please try again.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatDate(str) {
  if (!str) return '—';
  const d = new Date(str + 'T00:00:00');
  return d.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' });
}

function fmt12h(time24) {
  if (!time24) return '';
  const [h, m] = time24.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  return `${h % 12 || 12}:${String(m).padStart(2,'0')} ${ampm}`;
}

function showToast(message, type = 'info', duration = 4000) {
  const wrap = document.getElementById('toast-wrap');
  if (!wrap) return;
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="fas ${icons[type]||icons.info} toast-icon"></i><span>${escHtml(message)}</span>
    <button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>`;
  el.querySelector('.toast-close').addEventListener('click', () => dismiss(el));
  wrap.appendChild(el);
  const t = setTimeout(() => dismiss(el), duration);
  function dismiss(node) { clearTimeout(t); node.classList.add('hide'); setTimeout(() => node.remove(), 320); }
}
