'use strict';
/* ── ORDERS PAGE — live API ── */

const ORDER_STATUSES = [
  'schedule','confirm','collected','processing','ready','out_for_delivery','delivered','canceled'
];

const STATUS_LABELS = {
  schedule: 'Schedule', confirm: 'Confirm', collected: 'Collected',
  processing: 'Processing', ready: 'Ready', out_for_delivery: 'Out for Delivery',
  delivered: 'Delivered', canceled: 'Canceled',
};

const VALID_TRANSITIONS = {
  schedule:         ['confirm',         'canceled'],
  confirm:          ['collected',       'canceled'],
  collected:        ['processing',      'canceled'],
  processing:       ['ready',           'canceled'],
  ready:            ['out_for_delivery','canceled'],
  out_for_delivery: ['delivered',       'canceled'],
  delivered:        [],
  canceled:         [],
};

let ordPage = 1;
const ORD_PER = 10;
let ordStatus    = 'all';
let ordSearch    = '';
let ordServiceId = '';
let ordDateFrom  = '';
let ordDateTo    = '';
let currentOrderId = null;

async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

async function loadOrderStats() {
  try {
    const j = await apiFetch('/api/admin/orders/stats');
    if (!j.success) return;
    const d  = j.data;
    const el = id => document.getElementById(id);
    if (el('s-pending'))    animateCount(el('s-pending'),    d.schedule);
    if (el('s-processing')) animateCount(el('s-processing'), d.processing);
    if (el('s-delivery'))   animateCount(el('s-delivery'),   d.out_for_delivery);
    if (el('s-delivered'))  animateCount(el('s-delivered'),  d.delivered);
  } catch { /* ignore */ }
}

async function loadServiceOptions() {
  try {
    const j = await apiFetch('/api/admin/services');
    if (!j.success) return;
    const sel = document.getElementById('serviceFilter');
    if (!sel) return;
    const services = Array.isArray(j.data) ? j.data : [];
    sel.innerHTML = '<option value="">All Services</option>' +
      services.map(s => `<option value="${escHtml(s.id)}">${escHtml(s.name)}</option>`).join('');
  } catch (err) {
    console.error('[orders] loadServiceOptions error:', err);
  }
}

async function loadOrders() {
  const body = document.getElementById('orders-body');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--gray-400);">Loading…</td></tr>`;

  const params = new URLSearchParams({ page: ordPage, per_page: ORD_PER });
  if (ordSearch)    params.set('search',     ordSearch);
  if (ordStatus !== 'all') params.set('status', ordStatus);
  if (ordServiceId) params.set('service_id', ordServiceId);
  if (ordDateFrom)  params.set('date_from',  ordDateFrom);
  if (ordDateTo)    params.set('date_to',    ordDateTo);

  try {
    const j = await apiFetch(`/api/admin/orders?${params}`);
    if (!j.success) { showToast(j.message || 'Failed to load orders.', 'error'); return; }
    renderOrderTable(j.data.orders);
    const from = j.data.total > 0 ? (ordPage - 1) * ORD_PER + 1 : 0;
    renderPagination({
      total:     j.data.total,
      from,
      to:        Math.min(from + ORD_PER - 1, j.data.total),
      last_page: j.data.total_pages,
    });
  } catch (err) {
    console.error('[orders] load error:', err);
    body.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--red);">Failed to load orders.</td></tr>`;
  }
}

function renderOrderTable(orders) {
  const body = document.getElementById('orders-body');
  if (!body) return;
  if (!orders || !orders.length) {
    body.innerHTML = `<tr><td colspan="9"><div class="empty-state"><div class="empty-icon">📭</div><p>No orders found</p></div></td></tr>`;
    return;
  }
  body.innerHTML = orders.map((o, i) => `
    <tr>
      <td style="color:var(--gray-400);font-size:12px;text-align:center;">${(ordPage - 1) * ORD_PER + i + 1}</td>
      <td><span class="fw-600" style="color:var(--primary);font-size:12px;">${escHtml(o.order_number)}</span></td>
      <td>
        <div class="d-flex ai-center gap-8">
          <div class="avatar">${initials(o.customer_name)}</div>
          <div>
            <div style="font-size:13px;font-weight:600;">${escHtml(o.customer_name)}</div>
            <div class="text-muted text-sm">${escHtml(o.customer_email)}</div>
          </div>
        </div>
      </td>
      <td style="font-size:13px;">${escHtml(o.service_name)}</td>
      <td style="font-size:13px;">${o.weight_lbs ? o.weight_lbs + ' lbs' : '—'}</td>
      <td class="fw-600" style="font-size:13px;">${formatCurrency(o.total_amount)}</td>
      <td>${statusBadge(o.status)}</td>
      <td class="text-muted text-sm">${o.pickup_scheduled_at ? formatDate(o.pickup_scheduled_at) : formatDate(o.created_at)}</td>
      <td>
        <div class="d-flex ai-center gap-8">
          <button class="btn btn-outline btn-sm btn-icon" title="View" onclick="viewOrder(${o.order_id})">👁</button>
          <div class="dropdown">
            <button class="btn btn-outline btn-sm btn-icon" data-dropdown title="More">⋯</button>
            <div class="dropdown-menu">
              <div class="dropdown-item" onclick="viewOrder(${o.order_id})">👁 View Details</div>
              <div class="dropdown-item" onclick="viewOrder(${o.order_id})">🔄 Update Status</div>
              <div class="dropdown-item danger" onclick="deleteOrder(${o.order_id}, ${jsArg(o.order_number)})">✕ Delete Order</div>
            </div>
          </div>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderPagination(p) {
  const info = document.getElementById('page-info');
  if (info) info.textContent = `Showing ${p.total ? p.from : 0}–${Math.min(p.to, p.total)} of ${p.total}`;

  const prev = document.getElementById('prev-page');
  const next = document.getElementById('next-page');
  if (prev) prev.disabled = ordPage <= 1;
  if (next) next.disabled = ordPage >= p.last_page;

  const nums = document.getElementById('page-numbers');
  if (nums) {
    const pages = [];
    for (let pg = Math.max(1, ordPage - 2); pg <= Math.min(p.last_page, ordPage + 2); pg++) pages.push(pg);
    nums.innerHTML = pages.map(pg =>
      `<button class="page-btn ${pg === ordPage ? 'active' : ''}" onclick="ordGoPage(${pg})">${pg}</button>`
    ).join('');
  }
}

function ordGoPage(p) { ordPage = p; loadOrders(); }

async function viewOrder(id) {
  currentOrderId = id;
  openModal('orderDetailModal');
  document.getElementById('modal-order-id').textContent = 'Loading…';

  try {
    const j = await apiFetch(`/api/admin/orders/${id}`);
    if (!j.success) { showToast('Failed to load order.', 'error'); return; }
    const o        = j.data.order;
    const timeline = j.data.timeline || [];

    document.getElementById('modal-order-id').textContent      = o.order_number;
    document.getElementById('modal-order-date').textContent    = 'Placed: ' + formatDate(o.created_at);
    document.getElementById('modal-avatar').textContent        = initials(o.customer_name);
    document.getElementById('modal-customer-name').textContent = o.customer_name;
    document.getElementById('modal-customer-email').textContent= o.customer_email;
    document.getElementById('modal-customer-phone').textContent= o.customer_phone || '—';
    document.getElementById('modal-service').textContent       = o.service_name;
    document.getElementById('modal-weight').textContent        = o.weight_lbs ? o.weight_lbs + ' lbs' : '—';
    document.getElementById('modal-subtotal').textContent      = formatCurrency(o.subtotal);
    document.getElementById('modal-discount').textContent      = parseFloat(o.discount) > 0 ? `−${formatCurrency(o.discount)}` : '—';
    document.getElementById('modal-tax').textContent           = parseFloat(o.tax) > 0 ? formatCurrency(o.tax) : '—';
    document.getElementById('modal-total').textContent         = formatCurrency(o.total_amount);

    // Timeline
    const tl = document.getElementById('modal-timeline');
    if (tl) {
      if (!timeline.length) {
        tl.innerHTML = '<div class="text-muted text-sm" style="padding:8px 0;">No timeline entries</div>';
      } else {
        const doneIdx = timeline.reduce((acc, t, i) => t.status ? i : acc, -1);
        tl.innerHTML = timeline.map((t, i) => `
          <div class="timeline-item ${t.status ? 'done' : ''} ${i === doneIdx ? 'current' : ''}">
            <div class="timeline-label">${escHtml(STATUS_LABELS[t.status] || t.status?.replace(/_/g,' ') || 'Event')}</div>
            ${t.note ? `<div class="text-muted text-sm">${escHtml(t.note)}</div>` : ''}
            <div class="timeline-time">${t.created_at ? formatDate(t.created_at) : '—'}</div>
          </div>
        `).join('');
      }
    }

    // Status update controls
    const isLocked  = o.status === 'delivered' || o.status === 'canceled';
    const sel       = document.getElementById('modal-status-select');
    const updateBtn = document.getElementById('modal-update-btn');
    const noteEl    = document.getElementById('modal-status-note');
    const lockMsg   = document.getElementById('modal-lock-msg');

    if (sel) {
      if (isLocked) {
        sel.innerHTML = `<option value="${escHtml(o.status)}">${escHtml(STATUS_LABELS[o.status] || o.status)}</option>`;
        sel.disabled  = true;
      } else {
        const nextStatuses = VALID_TRANSITIONS[o.status] || [];
        sel.innerHTML = nextStatuses.map(s =>
          `<option value="${s}">${STATUS_LABELS[s] || s}</option>`
        ).join('');
        sel.disabled  = false;
      }
    }

    if (updateBtn) {
      updateBtn.disabled = isLocked;
      updateBtn.style.opacity = isLocked ? '0.5' : '';
      updateBtn.style.cursor  = isLocked ? 'not-allowed' : '';
    }

    if (noteEl) noteEl.value = '';

    if (lockMsg) {
      if (isLocked) {
        const isDelivered = o.status === 'delivered';
        lockMsg.style.display    = 'block';
        lockMsg.style.background = isDelivered ? 'var(--green-light)' : 'var(--red-light)';
        lockMsg.style.color      = isDelivered ? 'var(--green)' : 'var(--red)';
        lockMsg.textContent      = isDelivered
          ? '✅ This order has been delivered and is permanently locked.'
          : '✖ This order was canceled and is permanently locked.';
      } else {
        lockMsg.style.display = 'none';
      }
    }
  } catch (err) {
    console.error('[orders] view error:', err);
    showToast('Failed to load order details.', 'error');
  }
}

async function updateOrderStatus() {
  if (!currentOrderId) return;
  const newStatus = document.getElementById('modal-status-select')?.value;
  const note      = document.getElementById('modal-status-note')?.value || '';
  if (!newStatus) return;

  try {
    const j = await apiFetch(`/api/admin/orders/${currentOrderId}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status: newStatus, note }),
    });
    if (!j.success) { showToast(j.message || 'Failed to update status.', 'error'); return; }
    showToast(`Order updated to "${STATUS_LABELS[newStatus] || newStatus}"`, 'success');
    closeModal('orderDetailModal');
    loadOrders();
    loadOrderStats();
  } catch { showToast('Network error.', 'error'); }
}

async function deleteOrder(id, orderNum) {
  if (!confirm(`Delete order ${orderNum}? This cannot be undone.`)) return;
  try {
    const j = await apiFetch(`/api/admin/orders/${id}`, { method: 'DELETE' });
    if (!j.success) { showToast(j.message || 'Failed to delete order.', 'error'); return; }
    showToast(`Order ${orderNum} deleted`, 'warning');
    loadOrders();
    loadOrderStats();
  } catch { showToast('Network error.', 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
  loadOrderStats();
  loadServiceOptions();
  loadOrders();

  document.getElementById('orderSearch')?.addEventListener('input', e => {
    ordSearch = e.target.value.trim();
    ordPage   = 1;
    loadOrders();
  });

  document.getElementById('serviceFilter')?.addEventListener('change', e => {
    ordServiceId = e.target.value;
    ordPage      = 1;
    loadOrders();
  });

  document.getElementById('dateFrom')?.addEventListener('change', e => {
    ordDateFrom = e.target.value;
    ordPage     = 1;
    loadOrders();
  });

  document.getElementById('dateTo')?.addEventListener('change', e => {
    ordDateTo = e.target.value;
    ordPage   = 1;
    loadOrders();
  });

  document.getElementById('prev-page')?.addEventListener('click', () => { ordPage--; loadOrders(); });
  document.getElementById('next-page')?.addEventListener('click', () => { ordPage++; loadOrders(); });

  document.getElementById('exportBtn')?.addEventListener('click', () => showToast('Exporting CSV…', 'default'));

  document.querySelectorAll('#statusFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#statusFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      ordStatus = chip.dataset.status;
      ordPage   = 1;
      loadOrders();
    });
  });

  const params = new URLSearchParams(window.location.search);
  const f = params.get('filter');
  if (f) {
    const chip = document.querySelector(`[data-status="${f}"]`);
    if (chip) chip.click();
  }
});
