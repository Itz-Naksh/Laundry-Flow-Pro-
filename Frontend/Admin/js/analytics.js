'use strict';
/* ── ANALYTICS PAGE — Analytics Microservice + Report Export Microservice ── */

// ── State ───────────────────────────────────────────────────────────────────
let currentPeriod = '12months';

// ── API fetch helper ─────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return null; }
  return res.json();
}

// ── Period label helpers ──────────────────────────────────────────────────────
const PERIOD_LABELS = {
  '30days':   'Last 30 Days',
  '3months':  'Last 3 Months',
  '6months':  'Last 6 Months',
  '12months': 'Last 12 Months',
};
const CHART_SUBTITLES = {
  '30days':   'Daily — past 30 days',
  '3months':  'Weekly — past 3 months',
  '6months':  'Monthly — past 6 months',
  '12months': 'Monthly — past 12 months',
};

// ── Bar chart renderer ────────────────────────────────────────────────────────
function renderBarChart(containerId, labelId, data, labels, color) {
  const el  = document.getElementById(containerId);
  const lbl = document.getElementById(labelId);
  if (!el) return;

  const max  = Math.max(1, ...data);
  const step = Math.ceil(data.length / 10); // show at most 10 labels

  el.innerHTML = data.map((v, i) => {
    const h     = Math.max(4, Math.round((v / max) * 100));
    const title = `${labels[i]}: ${v >= 1000 ? '' + formatCurrency(v) : v}`;
    return `<div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;">
      <div style="margin-top:auto;width:100%;background:${color};border-radius:4px 4px 0 0;height:${h}%;min-height:4px;transition:height .5s ease;" title="${title}"></div>
    </div>`;
  }).join('');

  if (lbl) {
    lbl.innerHTML = labels.map((l, i) =>
      `<span style="flex:1;text-align:center;">${i % step === 0 ? l : ''}</span>`
    ).join('');
  }
}

// ── KPI card updater ─────────────────────────────────────────────────────────
function updateKpiCard(valueId, changeId, value, stat, formatter) {
  const valEl    = document.getElementById(valueId);
  const changeEl = document.getElementById(changeId);
  if (valEl)    valEl.textContent    = formatter(value);
  if (changeEl) {
    const sign = stat.trend === 'up' ? '↑' : '↓';
    changeEl.textContent = `${sign} ${stat.changePercentage}%`;
    changeEl.className   = 'stat-change ' + (stat.trend === 'up' ? 'up' : 'down');
  }
}

// ── Service revenue chart ─────────────────────────────────────────────────────
function renderServiceRevenueChart(list) {
  const el = document.getElementById('service-revenue-chart');
  if (!el) return;
  if (!list || !list.length) {
    el.innerHTML = '<p style="color:var(--gray-400);font-size:13px;">No data yet</p>';
    return;
  }
  el.innerHTML = list.map(s => `
    <div style="margin-bottom:14px;">
      <div class="d-flex ai-center gap-8 mb-4">
        <div style="width:10px;height:10px;border-radius:3px;background:${s.color};flex-shrink:0;"></div>
        <span style="font-size:13px;font-weight:600;flex:1;">${s.name}</span>
        <span style="font-size:12px;color:var(--gray-500);">${s.pct}%</span>
        <span class="fw-700" style="font-size:13px;color:var(--green);">${formatCurrency(s.revenue)}</span>
      </div>
      <div class="progress-bar">
        <div style="height:100%;width:${s.pct}%;background:${s.color};border-radius:10px;transition:width .6s;"></div>
      </div>
    </div>
  `).join('');
}

// ── Key metrics panel ─────────────────────────────────────────────────────────
function renderKeyMetrics(km, summary) {
  const el = document.getElementById('key-metrics');
  if (!el) return;
  const metrics = [
    { label: 'Order Completion Rate', value: (km.completion_rate ?? '—') + '%',                    icon: '✅' },
    { label: 'Cancellation Rate',     value: (km.cancellation_rate ?? '—') + '%',                  icon: '❌' },
    { label: 'Customer Satisfaction', value: km.avg_rating ? parseFloat(km.avg_rating).toFixed(1) + ' ★' : '—', icon: '⭐' },
    { label: 'Revenue per Customer',  value: km.revenue_per_customer ? '' + formatCurrency(km.revenue_per_customer) : '—', icon: '💰' },
  ];
  el.innerHTML = metrics.map(m => `
    <div class="d-flex ai-center gap-12" style="padding:10px 0;border-bottom:1px solid var(--gray-100);">
      <span style="font-size:18px;width:26px;text-align:center;">${m.icon}</span>
      <span style="font-size:13px;color:var(--gray-600);flex:1;">${m.label}</span>
      <span class="fw-700" style="font-size:14px;">${m.value}</span>
    </div>
  `).join('');
}

// ── Top customers table ───────────────────────────────────────────────────────
function renderTopCustomers(list) {
  const body = document.getElementById('top-customers-body');
  if (!body) return;
  if (!list || !list.length) {
    body.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--gray-400);padding:24px;">No data for this period</td></tr>`;
    return;
  }
  body.innerHTML = list.map(c => `
    <tr>
      <td><span style="font-size:13px;font-weight:700;color:var(--gray-400);">#${c.rank}</span></td>
      <td>
        <div class="d-flex ai-center gap-8">
          <div class="avatar">${initials(c.name)}</div>
          <span class="fw-600" style="font-size:13px;">${c.name}</span>
        </div>
      </td>
      <td class="fw-600 text-sm">${c.orders}</td>
      <td class="fw-700" style="color:var(--green);font-size:13px;"> ${formatCurrency(c.total_spent)}</td>
      <td class="text-sm"> ${formatCurrency(c.avg_order)}</td>
      <td class="text-muted text-sm">${c.last_order ? formatDate(c.last_order) : '—'}</td>
    </tr>
  `).join('');
}

// ── Main analytics loader ─────────────────────────────────────────────────────
async function loadAnalytics(period) {
  currentPeriod = period;

  // Update subtitle
  const subtitle = document.getElementById('analyticsSubtitle');
  if (subtitle) subtitle.textContent = PERIOD_LABELS[period] || 'Performance metrics';

  // Update chart subtitles
  const revSub    = document.getElementById('revenueChartSubtitle');
  const growthSub = document.getElementById('growthChartSubtitle');
  if (revSub)    revSub.textContent    = CHART_SUBTITLES[period];
  if (growthSub) growthSub.textContent = CHART_SUBTITLES[period];

  try {
    const j = await apiFetch(`/api/analytics?period=${period}`);
    if (!j || !j.success) {
      showToast((j && j.message) || 'Failed to load analytics.', 'error');
      return;
    }
    const d = j.data;

    // ── KPI cards ─────────────────────────────────────────────────────────────
    updateKpiCard('kpi-revenue', 'kpi-revenue-change',
      d.summary.revenue.current, d.summary.revenue,
      v => '' + formatCurrency(v));

    updateKpiCard('kpi-orders', 'kpi-orders-change',
      d.summary.orders.current, d.summary.orders,
      v => v.toLocaleString());

    updateKpiCard('kpi-active', 'kpi-active-change',
      d.summary.activeCustomers.current, d.summary.activeCustomers,
      v => v.toLocaleString());

    updateKpiCard('kpi-new', 'kpi-new-change',
      d.summary.newCustomers.current, d.summary.newCustomers,
      v => v.toLocaleString());

    // ── Growth stats row ───────────────────────────────────────────────────────
    updateKpiCard('kpi-avg', 'kpi-avg-change',
      d.summary.avgOrderValue.current, d.summary.avgOrderValue,
      v => '' + formatCurrency(v));

    updateKpiCard('kpi-repeat', 'kpi-repeat-change',
      d.summary.repeatRate.current, d.summary.repeatRate,
      v => v.toFixed(1) + '%');

    const ratingEl = document.getElementById('kpi-rating');
    if (ratingEl) {
      ratingEl.textContent = d.metrics.avg_rating
        ? parseFloat(d.metrics.avg_rating).toFixed(1) + ' ★'
        : '—';
    }

    // ── Charts ────────────────────────────────────────────────────────────────
    renderBarChart(
      'monthlyChart', 'monthlyLabels',
      d.charts.revenue, d.charts.labels,
      'linear-gradient(180deg,rgba(37,99,235,.85),rgba(6,182,212,.5))'
    );
    renderBarChart(
      'growthChart', 'growthLabels',
      d.charts.customers, d.charts.labels,
      'linear-gradient(180deg,rgba(5,150,105,.85),rgba(52,211,153,.4))'
    );

    // ── Service chart ─────────────────────────────────────────────────────────
    renderServiceRevenueChart(d.services);

    // ── Key metrics ───────────────────────────────────────────────────────────
    renderKeyMetrics(d.metrics, d.summary);

    // ── Top customers ─────────────────────────────────────────────────────────
    renderTopCustomers(d.topCustomers);

  } catch (err) {
    console.error('[analytics] load error:', err);
    showToast('Could not load analytics.', 'error');
  }
}

// ── Export helpers ────────────────────────────────────────────────────────────
async function exportReport(format) {
  const btn = document.getElementById(format === 'excel' ? 'exportExcelBtn' : 'exportPdfBtn');
  const origText = btn?.textContent ?? '';
  if (btn) { btn.disabled = true; btn.textContent = 'Generating…'; }

  try {
    const res = await fetch(API_BASE + `/api/reports/export/${format}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ period: currentPeriod }),
    });

    if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return; }

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      showToast((err.message) || 'Export failed.', 'error');
      return;
    }

    const blob     = await res.blob();
    const ext      = format === 'excel' ? 'xlsx' : 'pdf';
    const url      = URL.createObjectURL(blob);
    const a        = document.createElement('a');
    a.href         = url;
    a.download     = `analytics-report-${currentPeriod}-${new Date().toISOString().slice(0,10)}.${ext}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);

    showToast(`${format.toUpperCase()} report downloaded.`, 'success');
  } catch (err) {
    console.error('[export] error:', err);
    showToast('Export failed. Please try again.', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = origText; }
  }
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const rangeSelect = document.getElementById('analyticsRange');

  // Load default period
  const initialPeriod = rangeSelect?.value || '12months';
  loadAnalytics(initialPeriod);

  // Period change → reload all data
  rangeSelect?.addEventListener('change', e => loadAnalytics(e.target.value));

  // Export buttons
  document.getElementById('exportExcelBtn')?.addEventListener('click', () => exportReport('excel'));
  document.getElementById('exportPdfBtn')?.addEventListener('click',  () => exportReport('pdf'));
});
