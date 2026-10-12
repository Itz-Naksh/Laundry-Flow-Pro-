'use strict';
/* ── REVIEWS PAGE — live API ── */

let rfFilter = 'all';
let rsFilter = 'all';
let reviewSearch = '';

async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

/* ── Star rendering (supports decimal ratings) ────────────────────────────── */
function starsHtml(rating) {
  const num = parseFloat(rating) || 0;
  let html = '';
  for (let i = 1; i <= 5; i++) {
    if (num >= i) {
      html += '<span class="star-full">★</span>';
    } else if (num >= i - 0.5) {
      html += '<span class="star-half">★</span>';
    } else {
      html += '<span class="star-empty">☆</span>';
    }
  }
  return `<span class="stars-wrap">${html}</span><span class="rating-val">${num.toFixed(1)}</span>`;
}

/* ── Stats ────────────────────────────────────────────────────────────────── */
async function loadReviewStats() {
  try {
    const j = await apiFetch('/api/admin/reviews/stats');
    if (!j.success) return;
    const d = j.data;

    const avgBig = document.getElementById('avg-rating-big');
    const avgLbl = document.getElementById('avg-rating-label');
    const avgStars = document.getElementById('avg-stars-display');

    if (avgBig)   avgBig.textContent = parseFloat(d.average).toFixed(1);
    if (avgStars) avgStars.innerHTML = starsHtml(d.average);
    if (avgLbl)   avgLbl.textContent = `Based on ${(d.approved || 0).toLocaleString()} approved reviews`;

    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('stat-total',    d.total    ?? 0);
    set('stat-pending',  d.pending  ?? 0);
    set('stat-approved', d.approved ?? 0);
    set('stat-featured', d.featured ?? 0);

    renderRatingBreakdown(d.distribution || {});
    renderRatingDist(d.distribution || {});
  } catch (err) {
    console.error('[reviews] stats error:', err);
  }
}

function renderRatingBreakdown(dist) {
  const el = document.getElementById('rating-breakdown');
  if (!el) return;
  const total = Object.values(dist).reduce((a, b) => a + b, 0);
  el.innerHTML = [5,4,3,2,1].map(r => {
    const pct = total > 0 ? Math.round(((dist[r] ?? 0) / total) * 100) : 0;
    return `
      <div class="d-flex ai-center gap-8" style="margin-bottom:6px;">
        <span style="font-size:12px;color:var(--gray-500);width:14px;">${r}</span>
        <span style="color:#F59E0B;font-size:11px;">★</span>
        <div class="progress-bar" style="flex:1;">
          <div class="progress-fill" style="width:${pct}%;background:#F59E0B;"></div>
        </div>
        <span style="font-size:11px;color:var(--gray-500);width:28px;text-align:right;">${pct}%</span>
      </div>`;
  }).join('');
}

function renderRatingDist(dist) {
  const el = document.getElementById('rating-dist');
  if (!el) return;
  const total = Object.values(dist).reduce((a, b) => a + b, 0);
  el.innerHTML = [5,4,3,2,1].map(r => {
    const count = dist[r] ?? 0;
    const pct   = total > 0 ? Math.round((count / total) * 100) : 0;
    return `
      <div style="margin-bottom:10px;">
        <div class="d-flex ai-center gap-8 mb-4">
          <span style="font-size:12px;font-weight:600;color:#F59E0B;">${'★'.repeat(r)}${'☆'.repeat(5-r)}</span>
          <span style="font-size:11px;color:var(--gray-500);margin-left:auto;">${count.toLocaleString()}</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:${pct}%;background:#F59E0B;"></div>
        </div>
      </div>`;
  }).join('');
}

/* ── Load & Render Reviews ────────────────────────────────────────────────── */
async function loadReviews() {
  const body = document.getElementById('reviews-body');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">Loading…</td></tr>`;

  const params = new URLSearchParams({
    ...(reviewSearch && { search: reviewSearch }),
    ...(rfFilter !== 'all' && { rating: rfFilter }),
    ...(rsFilter !== 'all' && { status: rsFilter }),
  });

  try {
    const j = await apiFetch(`/api/admin/reviews?${params}`);
    if (!j.success) { showToast(j.message || 'Failed to load reviews.', 'error'); return; }
    renderReviewsTable(j.data.reviews);
  } catch (err) {
    console.error('[reviews] load error:', err);
    body.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--red);">Failed to load reviews.</td></tr>`;
  }
}

function renderReviewsTable(list) {
  const body = document.getElementById('reviews-body');
  if (!body) return;
  if (!list || !list.length) {
    body.innerHTML = `<tr><td colspan="8"><div class="empty-state"><div class="empty-icon">⭐</div><p>No reviews found</p></div></td></tr>`;
    return;
  }
  body.innerHTML = list.map(r => {
    const statusBadge = {
      pending:  `<span class="badge-pending">Pending</span>`,
      approved: `<span class="badge-approved">Approved</span>`,
      rejected: `<span class="badge-rejected">Rejected</span>`,
    }[r.status] || `<span class="badge-pending">${escHtml(r.status)}</span>`;

    const featuredBadge = r.is_featured
      ? `<span class="badge-featured">★ Featured</span>`
      : `<span style="font-size:11px;color:var(--gray-400);">—</span>`;

    const approveBtn = r.status !== 'approved'
      ? `<button class="btn btn-success btn-sm" onclick="approveReview(${r.id})">✓ Approve</button>`
      : '';
    const rejectBtn = r.status !== 'rejected'
      ? `<button class="btn btn-outline btn-sm" onclick="rejectReview(${r.id})">✗ Reject</button>`
      : '';
    const featureBtn = r.status === 'approved'
      ? (r.is_featured
          ? `<button class="btn btn-outline btn-sm" onclick="toggleFeature(${r.id})">☆ Unfeature</button>`
          : `<button class="btn btn-outline btn-sm" onclick="toggleFeature(${r.id})">★ Feature</button>`)
      : '';
    const deleteBtn = `<button class="btn btn-danger btn-sm" onclick="deleteReview(${r.id})">🗑</button>`;

    return `
      <tr>
        <td>
          <div class="d-flex ai-center gap-8">
            <div class="avatar" style="width:30px;height:30px;font-size:11px;flex-shrink:0;">${initials(r.customer_name)}</div>
            <span style="font-size:12px;font-weight:600;">${escHtml(r.customer_name)}</span>
          </div>
        </td>
        <td class="text-sm text-muted">${escHtml(r.service_name)}</td>
        <td style="font-size:11px;font-weight:600;color:var(--primary);">${escHtml(r.order_number)}</td>
        <td>${starsHtml(r.rating)}</td>
        <td>
          ${r.title ? `<div style="font-size:12px;font-weight:600;margin-bottom:2px;">${escHtml(r.title)}</div>` : ''}
          <div class="review-snippet" title="${escHtml(r.review_text || '')}">${escHtml(r.review_text || '—')}</div>
          <div style="font-size:10px;color:var(--gray-400);margin-top:2px;">${formatDate(r.created_at)}</div>
        </td>
        <td>${statusBadge}</td>
        <td>${featuredBadge}</td>
        <td>
          <div class="action-group">
            ${approveBtn}${rejectBtn}${featureBtn}${deleteBtn}
          </div>
        </td>
      </tr>`;
  }).join('');
}

/* ── Actions ──────────────────────────────────────────────────────────────── */
async function approveReview(id) {
  try {
    const j = await apiFetch(`/api/admin/reviews/${id}/approve`, { method: 'PUT' });
    if (!j.success) { showToast(j.message || 'Failed to approve.', 'error'); return; }
    showToast('Review approved.', 'success');
    loadReviews(); loadReviewStats();
  } catch { showToast('Network error.', 'error'); }
}

async function rejectReview(id) {
  try {
    const j = await apiFetch(`/api/admin/reviews/${id}/reject`, { method: 'PUT' });
    if (!j.success) { showToast(j.message || 'Failed to reject.', 'error'); return; }
    showToast('Review rejected.', 'warning');
    loadReviews(); loadReviewStats();
  } catch { showToast('Network error.', 'error'); }
}

async function toggleFeature(id) {
  try {
    const j = await apiFetch(`/api/admin/reviews/${id}/feature`, { method: 'PUT' });
    if (!j.success) { showToast(j.message || 'Failed to update.', 'error'); return; }
    showToast(j.message, 'success');
    loadReviews(); loadReviewStats();
  } catch { showToast('Network error.', 'error'); }
}

async function deleteReview(id) {
  if (!confirm('Delete this review? This cannot be undone.')) return;
  try {
    const j = await apiFetch(`/api/admin/reviews/${id}`, { method: 'DELETE' });
    if (!j.success) { showToast(j.message || 'Failed to delete.', 'error'); return; }
    showToast('Review deleted.', 'warning');
    loadReviews(); loadReviewStats();
  } catch { showToast('Network error.', 'error'); }
}

/* ── Init ─────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  loadReviewStats();
  loadReviews();

  document.getElementById('reviewSearch')?.addEventListener('input', e => {
    reviewSearch = e.target.value.trim();
    loadReviews();
  });

  document.querySelectorAll('#statusFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#statusFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      rsFilter = chip.dataset.rs;
      loadReviews();
    });
  });

  document.querySelectorAll('#reviewRatingFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#reviewRatingFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      rfFilter = chip.dataset.rf;
      loadReviews();
    });
  });
});
