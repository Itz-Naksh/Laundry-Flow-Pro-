'use strict';
/* ── DASHBOARD PAGE — live API ── */

async function apiFetch(path) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken() },
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

async function loadDashboard() {
  try {
    const [statsJson, mainJson] = await Promise.all([
      apiFetch('/api/dashboard/statistics'),
      apiFetch('/api/admin/dashboard'),
    ]);

    if (statsJson.success) renderStats(statsJson.data);
    if (mainJson.success) {
      renderRecentOrders(mainJson.data.recent_orders);
      renderNewCustomers(mainJson.data.new_customers);
      renderReviews(mainJson.data.recent_reviews);
    }

    await Promise.all([
      loadRevenueChart('week'),
      loadOrderStatus('week'),
      loadTopServices('week'),
    ]);
  } catch (err) {
    console.error('[dashboard] load error:', err);
    showToast('Could not load dashboard data.', 'error');
  }
}

// ── 6 Stat Cards ─────────────────────────────────────────────────────────────
function renderStats(s) {
  const set = (id, val, prefix = '', suffix = '') => {
    const el = document.getElementById(id);
    if (!el) return;
    if (prefix === 'LKR ') { el.textContent = 'LKR 0'; animateCount(el, Math.round(val), 'LKR '); }
    else animateCount(el, val, prefix, suffix);
  };

  set('stat-customers',    s.total_customers);
  set('stat-active',       s.active_orders);
  set('stat-collections',  s.today_collections);
  set('stat-deliveries',   s.today_deliveries);
  set('stat-monthly-rev',  s.monthly_revenue, 'LKR ');
  set('stat-total-rev',    s.total_revenue,   'LKR ');
}

// ── Revenue line chart ───────────────────────────────────────────────────────
async function loadRevenueChart(period) {
  try {
    const j = await apiFetch(`/api/dashboard/revenue?period=${period}`);
    if (j.success) renderLineChart(j.data.values, j.data.labels);
  } catch { showToast('Failed to load revenue chart.', 'error'); }
}

function smoothPath(pts) {
  if (!pts.length) return '';
  if (pts.length === 1) return `M${pts[0].x},${pts[0].y}`;
  let d = `M${pts[0].x},${pts[0].y}`;
  for (let i = 1; i < pts.length; i++) {
    const cp = (pts[i - 1].x + pts[i].x) / 2;
    d += ` C${cp},${pts[i - 1].y} ${cp},${pts[i].y} ${pts[i].x},${pts[i].y}`;
  }
  return d;
}

function renderLineChart(values, labels) {
  const wrap = document.getElementById('revenueChartWrap');
  if (!wrap) return;

  const W = 460, H = 160;
  const padL = 52, padR = 16, padT = 16, padB = 28;
  const cW = W - padL - padR;
  const cH = H - padT - padB;

  const raw    = values || [];
  const lbls   = labels || [];
  const n      = raw.length;

  // Find the last non-null index to cap the line
  let lastReal = -1;
  for (let i = 0; i < n; i++) if (raw[i] !== null && raw[i] !== undefined) lastReal = i;

  // Max over real values only
  const realVals = raw.filter(v => v !== null && v !== undefined);
  const maxVal   = Math.max(1, ...realVals);

  const xOf = i => padL + (n > 1 ? (i / (n - 1)) * cW : cW / 2);
  const yOf = v => padT + (1 - v / maxVal) * cH;

  // Build points for ALL labels (null → position at bottom, for X-axis only)
  const allPts = raw.map((v, i) => ({
    x: xOf(i),
    y: v !== null && v !== undefined ? yOf(v) : null,
    v,
  }));

  // Points for the line (only real data up to lastReal)
  const linePts = allPts.slice(0, lastReal + 1).map(p => ({ x: p.x, y: p.y ?? (padT + cH) }));

  const linePath = smoothPath(linePts);
  const areaPath = linePts.length
    ? `${linePath} L${linePts[linePts.length - 1].x},${padT + cH} L${linePts[0].x},${padT + cH} Z`
    : '';

  const yTicks = [0, 0.25, 0.5, 0.75, 1].map(f => ({
    y: padT + (1 - f) * cH,
    v: Math.round(maxVal * f),
  }));

  wrap.innerHTML = `
    <svg viewBox="0 0 ${W} ${H}" style="width:100%;display:block;" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="lgArea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%"   stop-color="#2563EB" stop-opacity="0.18"/>
          <stop offset="100%" stop-color="#2563EB" stop-opacity="0.01"/>
        </linearGradient>
      </defs>

      ${yTicks.map(t => `
        <line x1="${padL}" y1="${t.y}" x2="${W - padR}" y2="${t.y}"
              stroke="var(--gray-100)" stroke-width="1" stroke-dasharray="4,3"/>
        <text x="${padL - 6}" y="${t.y + 4}" text-anchor="end" font-size="9" fill="var(--gray-400)">
          ${t.v >= 1000 ? (t.v / 1000).toFixed(1) + 'k' : t.v}
        </text>
      `).join('')}

      ${areaPath ? `<path d="${areaPath}" fill="url(#lgArea)"/>` : ''}

      ${linePath ? `<path d="${linePath}" fill="none" stroke="#2563EB" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round"/>` : ''}

      ${allPts.map((p, i) => p.y !== null ? `
        <circle cx="${p.x}" cy="${p.y}" r="4" fill="#2563EB" stroke="white" stroke-width="2">
          <title>${escHtml(lbls[i] || '')}: ${formatCurrency(p.v)}</title>
        </circle>
      ` : '').join('')}

      ${lbls.map((l, i) => `
        <text x="${xOf(i)}" y="${H - 4}" text-anchor="middle" font-size="9" fill="var(--gray-400)">${escHtml(l)}</text>
      `).join('')}
    </svg>`;
}

// ── Order status donut ───────────────────────────────────────────────────────
async function loadOrderStatus(period) {
  try {
    const j = await apiFetch(`/api/dashboard/order-status?period=${period}`);
    if (j.success) renderDonut(j.data);
  } catch { showToast('Failed to load order status.', 'error'); }
}

function renderDonut(statusData) {
  const data   = (statusData || []).filter(d => d.value > 0);
  const total  = data.reduce((s, d) => s + d.value, 0);
  const circ   = 2 * Math.PI * 40;
  const svg    = document.getElementById('donutSvg');
  const legend = document.getElementById('donut-legend');
  const totEl  = document.getElementById('donut-total');

  if (totEl) totEl.textContent = total;
  svg.querySelectorAll('.donut-seg').forEach(el => el.remove());

  const textEl = svg.querySelector('text');
  let offset = 0;
  data.forEach(item => {
    const dash   = (item.value / total) * circ;
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', '55'); circle.setAttribute('cy', '55'); circle.setAttribute('r', '40');
    circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', item.color);
    circle.setAttribute('stroke-width', '18'); circle.setAttribute('class', 'donut-seg');
    circle.style.cssText = `stroke-dasharray:${dash} ${circ-dash};stroke-dashoffset:${-offset};transform:rotate(-90deg);transform-origin:55px 55px;transition:stroke-dasharray .8s ease`;
    svg.insertBefore(circle, textEl);
    offset += dash;
  });

  if (legend) {
    legend.innerHTML = data.map(d => `
      <div class="donut-item">
        <div class="donut-dot" style="background:${d.color}"></div>
        <span class="donut-item-label">${escHtml(d.label)}</span>
        <span class="donut-item-val">${d.value}</span>
      </div>
    `).join('');
  }
}

// ── Top services ─────────────────────────────────────────────────────────────
async function loadTopServices(period) {
  try {
    const j = await apiFetch(`/api/dashboard/top-services?period=${period}`);
    if (j.success) renderTopServices(j.data);
  } catch { showToast('Failed to load top services.', 'error'); }
}

function renderTopServices(services) {
  const el = document.getElementById('top-services');
  if (!el) return;
  if (!services || !services.length) { el.innerHTML = '<p style="color:var(--gray-400);font-size:13px;">No data yet</p>'; return; }
  el.innerHTML = services.map(s => `
    <div style="margin-bottom:14px;">
      <div class="d-flex ai-center gap-8 mb-4">
        <span style="font-size:13px;font-weight:600;color:var(--gray-800);flex:1;">${escHtml(s.name)}</span>
        <span style="font-size:12px;color:var(--gray-500);">${s.count} orders</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" style="width:${s.pct}%;background:var(--primary);"></div>
      </div>
    </div>
  `).join('');
}

function renderRecentOrders(orders) {
  const body = document.getElementById('recent-orders-body');
  if (!body) return;
  if (!orders || !orders.length) {
    body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--gray-400);padding:24px;">No orders yet</td></tr>';
    return;
  }
  body.innerHTML = orders.map(o => `
    <tr>
      <td><span class="fw-600 text-sm" style="color:var(--primary)">${escHtml(o.id)}</span></td>
      <td>
        <div class="d-flex ai-center gap-8">
          <div class="avatar">${initials(o.customer)}</div>
          <span>${escHtml(o.customer)}</span>
        </div>
      </td>
      <td>${escHtml(o.service)}</td>
      <td>${statusBadge(o.status)}</td>
      <td class="fw-600">${formatCurrency(o.total)}</td>
    </tr>
  `).join('');
}

function renderNewCustomers(customers) {
  const el = document.getElementById('new-customers');
  if (!el) return;
  if (!customers || !customers.length) { el.innerHTML = '<p style="color:var(--gray-400);font-size:13px;padding:12px 0;">No new customers this week</p>'; return; }
  el.innerHTML = customers.map(c => `
    <div class="d-flex ai-center gap-12" style="padding:10px 0;border-bottom:1px solid var(--gray-100);">
      <div class="avatar">${initials(c.name)}</div>
      <div style="flex:1;min-width:0;">
        <div class="fw-600" style="font-size:13px;">${escHtml(c.name)}</div>
        <div class="text-muted text-sm">${escHtml(c.email)}</div>
      </div>
      <span class="text-muted text-sm">${formatDate(c.joined)}</span>
    </div>
  `).join('');
}

function renderReviews(reviews) {
  const el = document.getElementById('recent-reviews');
  if (!el) return;
  if (!reviews || !reviews.length) { el.innerHTML = '<p style="color:var(--gray-400);font-size:13px;padding:12px 0;">No reviews yet</p>'; return; }
  el.innerHTML = reviews.map(r => `
    <div style="padding:12px 0;border-bottom:1px solid var(--gray-100);">
      <div class="d-flex ai-center gap-8 mb-4">
        <div class="avatar">${initials(r.name)}</div>
        <span class="fw-600" style="font-size:13px;">${escHtml(r.name)}</span>
        <span style="color:#F59E0B;margin-left:auto;font-size:13px;">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</span>
      </div>
      <p style="font-size:12px;color:var(--gray-500);line-height:1.5;">${escHtml(r.text)}</p>
    </div>
  `).join('');
}

// ── Period filter labels ──────────────────────────────────────────────────────
const PERIOD_LABELS = { week: 'This Week', month: 'This Month', year: 'This Year', all: 'All Time' };

const revPeriodEl = document.getElementById('revenuePeriod');
if (revPeriodEl) {
  revPeriodEl.addEventListener('change', async () => {
    const period = revPeriodEl.value;
    const sub = document.getElementById('revenueSubtitle');
    if (sub) sub.textContent = PERIOD_LABELS[period] || period;
    await loadRevenueChart(period);
  });
}

const statusPeriodEl = document.getElementById('statusPeriod');
if (statusPeriodEl) {
  statusPeriodEl.addEventListener('change', async () => {
    const period = statusPeriodEl.value;
    const sub = document.getElementById('statusSubtitle');
    if (sub) sub.textContent = PERIOD_LABELS[period] || period;
    await loadOrderStatus(period);
  });
}

const topSvcPeriodEl = document.getElementById('topServicesPeriod');
if (topSvcPeriodEl) {
  topSvcPeriodEl.addEventListener('change', async () => {
    const period = topSvcPeriodEl.value;
    const sub = document.getElementById('topServicesSubtitle');
    if (sub) sub.textContent = PERIOD_LABELS[period] + ' · By order count';
    await loadTopServices(period);
  });
}

document.addEventListener('DOMContentLoaded', loadDashboard);
