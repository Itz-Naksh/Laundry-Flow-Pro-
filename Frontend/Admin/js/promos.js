'use strict';
/* ── PROMOS PAGE — live API ── */

let PROMOS_CACHE = [];
let pfFilter    = 'all';
let promoSearch = '';
let editPromoId = null;

async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

async function loadPromos() {
  const body = document.getElementById('promos-body');
  if (!body) return;

  const params = new URLSearchParams({
    ...(promoSearch && { search: promoSearch }),
    ...(pfFilter !== 'all' && { status: pfFilter }),
  });

  try {
    const j = await apiFetch(`/api/admin/promos?${params}`);
    if (!j.success) { showToast(j.message || 'Failed to load promos.', 'error'); return; }
    PROMOS_CACHE = j.data.promos;
    renderPromos(PROMOS_CACHE);
  } catch (err) {
    console.error('[promos] load error:', err);
    showToast('Could not load promo codes.', 'error');
  }
}

function renderPromos(list) {
  const body = document.getElementById('promos-body');
  if (!body) return;
  if (!list || !list.length) {
    body.innerHTML = `<tr><td colspan="9"><div class="empty-state"><div class="empty-icon">🎟️</div><p>No promo codes found</p></div></td></tr>`;
    return;
  }
  body.innerHTML = list.map(p => {
    const st = p.status || 'inactive';
    const pctUsed = p.max_uses > 0 ? Math.round(((p.uses_count ?? 0) / p.max_uses) * 100) : 0;
    const statusBadgeHtml = st === 'active'
      ? '<span class="badge badge-active">Active</span>'
      : st === 'expired'
        ? '<span class="badge badge-canceled">Expired</span>'
        : '<span class="badge badge-suspended">Inactive</span>';
    return `
      <tr>
        <td><code style="font-size:13px;font-weight:700;color:var(--primary);background:var(--primary-light);padding:3px 8px;border-radius:6px;">${p.code}</code></td>
        <td class="text-sm">${p.discount_type === 'percent' ? 'Percentage' : 'Fixed Amount'}</td>
        <td class="fw-700 text-sm" style="color:var(--green);">${p.discount_type === 'percent' ? p.discount_value + '%' : 'LKR ' + p.discount_value}</td>
        <td class="text-sm">${p.min_order_value > 0 ? 'LKR ' + p.min_order_value : '—'}</td>
        <td>
          <div style="font-size:12px;margin-bottom:4px;">${p.uses_count ?? 0} / ${p.max_uses ?? '∞'}</div>
          ${p.max_uses ? `<div class="progress-bar" style="height:4px;"><div class="progress-fill ${pctUsed > 80 ? 'orange' : ''}" style="width:${pctUsed}%;"></div></div>` : ''}
        </td>
        <td class="text-sm">${p.max_uses ?? '—'}</td>
        <td class="text-sm">${p.valid_until ? formatDate(p.valid_until) : '—'}</td>
        <td>${statusBadgeHtml}</td>
        <td>
          <div class="d-flex ai-center gap-8">
            <button class="btn btn-outline btn-sm btn-icon" onclick="copyCode('${p.code}')" title="Copy">📋</button>
            <button class="btn btn-outline btn-sm btn-icon" onclick="editPromo(${p.id})" title="Edit">✏️</button>
            <button class="btn btn-outline btn-sm btn-icon" onclick="deletePromo(${p.id}, '${p.code}')" title="Delete" style="color:var(--red);">🗑</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function copyCode(code) {
  navigator.clipboard?.writeText(code).catch(() => {});
  showToast(`Copied: ${code}`, 'success');
}

function editPromo(id) {
  const p = PROMOS_CACHE.find(x => x.id === id);
  if (!p) return;
  editPromoId = id;
  document.getElementById('promo-modal-title').textContent = 'Edit Promo Code';
  document.getElementById('p-code').value  = p.code;
  document.getElementById('p-type').value  = p.discount_type || 'percent';
  document.getElementById('p-value').value = p.discount_value;
  document.getElementById('p-min').value   = p.min_order_value || '';
  document.getElementById('p-max-uses').value = p.max_uses || '';
  document.getElementById('p-from').value  = p.valid_from ? p.valid_from.substring(0, 10) : '';
  document.getElementById('p-until').value = p.valid_until ? p.valid_until.substring(0, 10) : '';
  document.getElementById('p-status').value = (p.is_active || p.status === 'active') ? '1' : '0';
  openModal('addPromoModal');
}

async function deletePromo(id, code) {
  if (!confirm(`Delete promo code "${code}"?`)) return;
  try {
    const j = await apiFetch(`/api/admin/promos/${id}`, { method: 'DELETE' });
    if (!j.success) { showToast(j.message || 'Failed to delete.', 'error'); return; }
    showToast(`${code} deleted`, 'warning');
    loadPromos();
  } catch { showToast('Network error.', 'error'); }
}

function generateCode() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  const code = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
  document.getElementById('p-code').value = code;
}

function openAddPromo() {
  editPromoId = null;
  document.getElementById('promo-modal-title').textContent = 'New Promo Code';
  ['p-code','p-value','p-min','p-max-uses','p-from','p-until'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const statusEl = document.getElementById('p-status');
  if (statusEl) statusEl.value = '1';
  openModal('addPromoModal');
}

async function savePromo() {
  const code  = document.getElementById('p-code')?.value.trim().toUpperCase();
  const value = parseFloat(document.getElementById('p-value')?.value);
  if (!code || isNaN(value)) { showToast('Code and value are required', 'error'); return; }

  const payload = {
    code,
    discount_type:    document.getElementById('p-type')?.value || 'percent',
    discount_value:   value,
    min_order_value: parseFloat(document.getElementById('p-min')?.value) || 0,
    max_uses:         parseInt(document.getElementById('p-max-uses')?.value) || null,
    valid_from:       document.getElementById('p-from')?.value || null,
    valid_until:      document.getElementById('p-until')?.value || null,
    is_active:        document.getElementById('p-status')?.value === '1' ? 1 : 0,
  };

  try {
    const path = editPromoId ? `/api/admin/promos/${editPromoId}` : '/api/admin/promos';
    const j = await apiFetch(path, {
      method: editPromoId ? 'PUT' : 'POST',
      body: JSON.stringify(payload),
    });
    if (!j.success) { showToast(j.message || 'Failed to save promo.', 'error'); return; }
    showToast(`${code} ${editPromoId ? 'updated' : 'created'}`, 'success');
    closeModal('addPromoModal');
    editPromoId = null;
    loadPromos();
  } catch { showToast('Network error.', 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
  loadPromos();

  document.getElementById('promoSearch')?.addEventListener('input', e => {
    promoSearch = e.target.value.trim();
    loadPromos();
  });

  document.querySelectorAll('#promoFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#promoFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      pfFilter = chip.dataset.pf;
      loadPromos();
    });
  });
});
