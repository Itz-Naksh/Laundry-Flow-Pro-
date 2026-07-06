'use strict';
/* ── Admin Notification Center — live API ── */

let NOTIFS_CACHE = [];
let nfFilter = 'all';

const TEMPLATES = {
  promo:       { subject: 'Exclusive Weekend Offer — 20% Off!',              body: 'Hi! We have a special offer just for you. Use code WEEKEND20 for 20% off your next order this weekend only. Book now and enjoy fresh, clean clothes delivered to your door!' },
  maintenance: { subject: 'Scheduled Maintenance – Brief Service Interruption', body: "We'll be performing scheduled maintenance on Saturday from 2:00–4:00 AM. During this time, our booking system may be temporarily unavailable. We apologize for any inconvenience." },
  reminder:    { subject: "Don't forget — Your laundry is ready to schedule!", body: "Hi! It's been a while since your last pickup. Your clothes deserve a refresh. Schedule now and we'll handle the rest!" },
  new_service: { subject: 'New Service Announcement!',                        body: "We've upgraded our services! Enjoy premium care for all your laundry needs. Book your first order today and experience the Laundry Flow Pro difference!" },
  holiday:     { subject: '🎉 Special Holiday Offer — Limited Time Only!',    body: "The holidays are here! Treat yourself to fresh, clean laundry delivered right to your door. Use our special holiday promo and save big. Happy celebrations from the Laundry Flow Pro team!" },
};

const TYPE_META = {
  'new.order':    { icon: '📦', label: 'New Order'    },
  'new.customer': { icon: '👤', label: 'New Customer' },
  'new.review':   { icon: '⭐', label: 'New Review'   },
  system:         { icon: '⚙️', label: 'System'       },
  promo:          { icon: '📢', label: 'Campaign'     },
  custom:         { icon: '✉️', label: 'Custom'       },
  announcement:   { icon: '📣', label: 'Announcement' },
};

function resolveTypeMeta(type) {
  if (TYPE_META[type]) return TYPE_META[type];
  const key = Object.keys(TYPE_META).find(k => type.startsWith(k));
  return key ? TYPE_META[key] : { icon: '🔔', label: 'Notification' };
}

// ── API helper ────────────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) {
    clearSession(); window.location.replace('login.html');
    return { success: false, message: '' };
  }
  return res.json();
}

// ── Load notifications ────────────────────────────────────────────────────────
async function loadNotifications() {
  const feed = document.getElementById('notif-feed');
  if (!feed) return;
  feed.innerHTML = `<div style="text-align:center;padding:32px;color:var(--gray-400);">Loading…</div>`;

  try {
    const j = await apiFetch(`/api/admin/notifications?filter=${nfFilter}`);
    if (!j.success) { showToast(j.message || 'Failed to load notifications.', 'error'); return; }
    NOTIFS_CACHE = j.data.notifications || [];
    renderFeed(NOTIFS_CACHE);
    renderStats(j.data);
    renderTodayActivity();

    const badge = document.getElementById('nav-notif-badge');
    if (badge) badge.textContent = j.data.unread_count > 0 ? j.data.unread_count : '';
  } catch (err) {
    console.error('[notifications] load error:', err);
    feed.innerHTML = `<div style="text-align:center;padding:32px;color:var(--red);">Failed to load.</div>`;
  }
}

// ── Render feed ───────────────────────────────────────────────────────────────
function renderFeed(list) {
  const feed = document.getElementById('notif-feed');
  if (!feed) return;

  if (!list || !list.length) {
    feed.innerHTML = `<div class="empty-state"><div class="empty-icon">🔔</div><p>No notifications</p></div>`;
    return;
  }

  feed.innerHTML = list.map(n => {
    const meta = resolveTypeMeta(n.type);
    const isUnread = !n.read;
    return `
      <div class="card" style="margin-bottom:10px;cursor:pointer;${isUnread ? 'border-left:3px solid var(--primary);' : ''}"
           onclick="markRead(${n.id})">
        <div class="card-body" style="padding:14px 18px;">
          <div class="d-flex ai-center gap-12">
            <div style="width:40px;height:40px;border-radius:10px;background:${isUnread ? 'var(--primary-light)' : 'var(--gray-100)'};
                        display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">${meta.icon}</div>
            <div style="flex:1;min-width:0;">
              <div class="d-flex ai-center gap-8" style="margin-bottom:2px;">
                <span class="fw-600" style="font-size:13px;">${escHtml(n.subject)}</span>
                ${isUnread ? '<span style="width:7px;height:7px;border-radius:50%;background:var(--primary);flex-shrink:0;"></span>' : ''}
                <span style="font-size:11px;color:var(--gray-400);background:var(--gray-100);padding:1px 7px;border-radius:99px;">${meta.label}</span>
              </div>
              <div class="text-muted text-sm" style="font-size:12px;">${escHtml(n.message || '')}</div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
              <span class="text-muted text-sm">${timeAgo(n.created_at)}</span>
              <button class="btn btn-outline btn-sm btn-icon" style="color:var(--red);padding:4px 8px;"
                      onclick="event.stopPropagation();deleteNotif(${n.id})" title="Delete">🗑</button>
            </div>
          </div>
        </div>
      </div>`;
  }).join('');
}

// ── Stats panels ──────────────────────────────────────────────────────────────
function renderStats(data) {
  const list  = data.notifications || NOTIFS_CACHE;
  const unread = data.unread_count ?? list.filter(n => !n.read).length;
  const orders    = list.filter(n => n.type === 'new.order').length;
  const customers = list.filter(n => n.type === 'new.customer').length;

  setText('stat-total',     list.length);
  setText('stat-unread',    unread);
  setText('stat-orders',    orders);
  setText('stat-customers', customers);

  const el = document.getElementById('notif-stats');
  if (!el) return;
  const rows = [
    { label: 'Total Inbox',    val: list.length,                                  icon: '🔔' },
    { label: 'Unread',         val: unread,        accent: unread > 0,             icon: '📬' },
    { label: 'New Orders',     val: orders,                                        icon: '📦' },
    { label: 'New Customers',  val: customers,                                     icon: '👤' },
    { label: 'New Reviews',    val: list.filter(n => n.type === 'new.review').length, icon: '⭐' },
    { label: 'System Alerts',  val: list.filter(n => n.type === 'system').length,  icon: '⚙️' },
  ];
  el.innerHTML = rows.map(r => `
    <div class="d-flex ai-center gap-10" style="padding:8px 0;border-bottom:1px solid var(--gray-100);">
      <span>${r.icon}</span>
      <span style="font-size:13px;color:var(--gray-600);flex:1;">${r.label}</span>
      <span class="fw-700" style="color:${r.accent ? 'var(--primary)' : 'var(--gray-800)'};">${r.val}</span>
    </div>`).join('');
}

async function renderTodayActivity() {
  const el = document.getElementById('today-activity');
  if (!el) return;
  try {
    const j = await apiFetch('/api/dashboard/statistics');
    if (!j || !j.success) return;
    const d = j.data;
    el.innerHTML = `
      <div class="d-flex ai-center gap-10" style="padding:6px 0;border-bottom:1px solid var(--gray-100);">
        <span>📦</span>
        <span style="font-size:13px;color:var(--gray-600);flex:1;">Today's Pickups</span>
        <span class="fw-700" style="color:var(--primary);">${d.today_pickups ?? '—'}</span>
      </div>
      <div class="d-flex ai-center gap-10" style="padding:6px 0;border-bottom:1px solid var(--gray-100);">
        <span>🚚</span>
        <span style="font-size:13px;color:var(--gray-600);flex:1;">Today's Deliveries</span>
        <span class="fw-700" style="color:var(--green);">${d.today_deliveries ?? '—'}</span>
      </div>
      <div class="d-flex ai-center gap-10" style="padding:6px 0;border-bottom:1px solid var(--gray-100);">
        <span>🆕</span>
        <span style="font-size:13px;color:var(--gray-600);flex:1;">New Orders Today</span>
        <span class="fw-700">${d.new_orders_today ?? '—'}</span>
      </div>
      <div class="d-flex ai-center gap-10" style="padding:6px 0;">
        <span>👤</span>
        <span style="font-size:13px;color:var(--gray-600);flex:1;">New Customers Today</span>
        <span class="fw-700">${d.new_customers_today ?? '—'}</span>
      </div>`;
  } catch { /* ignore */ }
}

// ── Actions ───────────────────────────────────────────────────────────────────
async function markRead(id) {
  const n = NOTIFS_CACHE.find(x => x.id === id);
  if (n && n.read) return;
  try {
    await apiFetch(`/api/admin/notifications/${id}/read`, { method: 'PUT' });
    loadNotifications();
  } catch { /* ignore */ }
}

async function markAllRead() {
  const j = await apiFetch('/api/admin/notifications/read-all', { method: 'PUT' });
  if (!j.success) { showToast(j.message || 'Failed.', 'error'); return; }
  showToast('All notifications marked as read', 'success');
  loadNotifications();
}

async function deleteNotif(id) {
  const j = await apiFetch(`/api/admin/notifications/${id}`, { method: 'DELETE' });
  if (!j.success) { showToast(j.message || 'Failed to delete.', 'error'); return; }
  showToast('Notification deleted', 'success');
  loadNotifications();
}

// ── Quick template loader ─────────────────────────────────────────────────────
function useTemplate(key) {
  const t = TEMPLATES[key];
  if (!t) return;
  document.getElementById('notif-subject').value = t.subject;
  document.getElementById('notif-body').value    = t.body;
  openModal('sendNotifModal');
}

// ── Send custom notification ──────────────────────────────────────────────────
async function sendNotification() {
  const subject   = document.getElementById('notif-subject')?.value.trim();
  const body      = document.getElementById('notif-body')?.value.trim();
  const type      = document.getElementById('notif-type')?.value || 'custom';
  const sendInApp = document.getElementById('notif-send-inapp')?.checked ?? true;
  const sendEmail = document.getElementById('notif-send-email')?.checked ?? false;

  if (!subject) { showToast('Subject is required', 'error'); return; }
  if (!body)    { showToast('Message is required', 'error'); return; }

  const j = await apiFetch('/api/admin/notifications/send', {
    method: 'POST',
    body: JSON.stringify({
      subject,
      message:     body,
      type,
      recipients:  'all',
      send_in_app: sendInApp,
      send_email:  sendEmail,
    }),
  });

  if (!j.success) { showToast(j.message || 'Failed to send.', 'error'); return; }
  showToast(`Notification sent to ${j.data?.sent_to ?? 'all'} customers`, 'success');
  closeModal('sendNotifModal');
  document.getElementById('notif-subject').value = '';
  document.getElementById('notif-body').value    = '';
  loadNotifications();
}

// ── Send promotional campaign ─────────────────────────────────────────────────
async function sendPromotion() {
  const title     = document.getElementById('promo-title')?.value.trim();
  const subject   = document.getElementById('promo-subject')?.value.trim();
  const message   = document.getElementById('promo-message')?.value.trim();
  const promoCode = document.getElementById('promo-code')?.value.trim().toUpperCase();
  const startDate = document.getElementById('promo-start')?.value;
  const endDate   = document.getElementById('promo-end')?.value;
  const sendInApp = document.getElementById('promo-send-inapp')?.checked ?? true;
  const sendEmail = document.getElementById('promo-send-email')?.checked ?? true;

  if (!title)   { showToast('Campaign title is required', 'error'); return; }
  if (!subject) { showToast('Subject is required', 'error'); return; }
  if (!message) { showToast('Message is required', 'error'); return; }
  if (!sendInApp && !sendEmail) { showToast('Select at least one delivery channel', 'error'); return; }

  const btn = document.querySelector('#promoModal .btn-primary');
  if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

  const j = await apiFetch('/api/admin/promotions/send', {
    method: 'POST',
    body: JSON.stringify({
      title, subject, message,
      promo_code:  promoCode,
      start_date:  startDate,
      end_date:    endDate,
      recipients:  'all',
      send_in_app: sendInApp,
      send_email:  sendEmail,
    }),
  });

  if (btn) { btn.disabled = false; btn.textContent = '🚀 Send Campaign'; }

  if (!j.success) { showToast(j.message || 'Campaign failed to send.', 'error'); return; }
  showToast(`Campaign sent to ${j.data?.sent_to ?? 'all'} customers!`, 'success');
  closeModal('promoModal');
  // Reset form
  ['promo-title','promo-subject','promo-message','promo-code','promo-start','promo-end'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  loadNotifications();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadNotifications();

  document.querySelectorAll('#notifFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#notifFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      nfFilter = chip.dataset.nf;
      loadNotifications();
    });
  });
});
