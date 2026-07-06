'use strict';
/* ── SERVICES PAGE — live API ── */

let SERVICES_CACHE = [];
let editingId = null;
const svcColors = ['var(--primary)','var(--purple)','var(--accent)','var(--green)','var(--orange)','var(--red)'];

async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

async function loadServices() {
  try {
    const j = await apiFetch('/api/admin/services');
    if (!j.success) { showToast(j.message || 'Failed to load services.', 'error'); return; }
    SERVICES_CACHE = j.data.services;
    renderServiceCards();
    renderServiceTable();
  } catch (err) {
    console.error('[services] load error:', err);
    showToast('Could not load services.', 'error');
  }
}

function renderServiceCards() {
  const el = document.getElementById('service-cards');
  if (!el) return;
  if (!SERVICES_CACHE.length) {
    el.innerHTML = '<div style="grid-column:1/-1;"><div class="empty-state"><div class="empty-icon">🧼</div><p>No services yet</p></div></div>';
    return;
  }
  el.innerHTML = SERVICES_CACHE.map((s, i) => `
    <div class="card" style="position:relative;overflow:hidden;">
      <div style="position:absolute;top:0;left:0;right:0;height:4px;background:${svcColors[i % svcColors.length]};"></div>
      <div class="card-body">
        <div class="d-flex ai-center gap-12" style="margin-bottom:12px;">
          <div style="width:44px;height:44px;border-radius:12px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;font-size:22px;">${s.icon || '🧺'}</div>
          <div style="flex:1;">
            <div class="fw-700" style="font-size:14px;">${s.name}</div>
            <div class="text-muted text-sm">${formatCurrency(s.base_price)}/${s.unit || 'lb'}${s.is_express ? ' · ⚡ Express' : ''}</div>
          </div>
          <span class="badge ${s.is_active ? 'badge-active' : 'badge-canceled'}">${s.is_active ? 'active' : 'inactive'}</span>
        </div>
        <p style="font-size:12px;color:var(--gray-500);line-height:1.5;margin-bottom:16px;">${s.description || ''}</p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px;text-align:center;">
          <div style="background:var(--gray-50);border-radius:8px;padding:8px 4px;">
            <div class="fw-700" style="font-size:16px;">${s.order_count ?? 0}</div>
            <div class="text-muted text-sm">Orders</div>
          </div>
          <div style="background:var(--gray-50);border-radius:8px;padding:8px 4px;">
            <div class="fw-700" style="font-size:16px;color:var(--green);">${s.revenue > 0 ? 'LKR ' + (s.revenue/1000).toFixed(1) + 'k' : '—'}</div>
            <div class="text-muted text-sm">Revenue</div>
          </div>
          <div style="background:var(--gray-50);border-radius:8px;padding:8px 4px;">
            <div class="fw-700" style="font-size:16px;color:#F59E0B;">${s.avg_rating > 0 ? '★ ' + parseFloat(s.avg_rating).toFixed(1) : '—'}</div>
            <div class="text-muted text-sm">Rating</div>
          </div>
        </div>
        <div class="d-flex ai-center gap-8">
          <button class="btn btn-outline btn-sm" style="flex:1;" onclick="editService(${s.id})">✏️ Edit</button>
          <button class="btn btn-outline btn-sm btn-icon" onclick="deleteService(${s.id}, '${s.name.replace(/'/g,"\\'")}')">🗑</button>
        </div>
      </div>
    </div>
  `).join('');
}

function renderServiceTable() {
  const body = document.getElementById('service-table-body');
  if (!body) return;
  if (!SERVICES_CACHE.length) {
    body.innerHTML = `<tr><td colspan="9"><div class="empty-state"><div class="empty-icon">🧼</div><p>No services yet</p></div></td></tr>`;
    return;
  }
  body.innerHTML = SERVICES_CACHE.map(s => `
    <tr>
      <td>
        <div class="d-flex ai-center gap-8">
          <span style="font-size:20px;">${s.icon || '🧺'}</span>
          <div>
            <div class="fw-600" style="font-size:13px;">${s.name}</div>
            <div class="text-muted text-sm">${(s.description || '').substring(0, 50)}${s.description?.length > 50 ? '…' : ''}</div>
          </div>
        </div>
      </td>
      <td class="text-sm">per ${s.unit || 'lb'}</td>
      <td class="fw-600 text-sm">${formatCurrency(s.base_price)}</td>
      <td class="text-sm">${s.turnaround_h ?? '—'}h${s.is_express ? ' ⚡' : ''}</td>
      <td class="fw-600 text-sm">${s.order_count ?? 0}</td>
      <td class="fw-600 text-sm" style="color:var(--green);">${s.revenue > 0 ? formatCurrency(s.revenue) : '—'}</td>
      <td>
        <span style="color:#F59E0B;font-size:13px;">★</span>
        <span class="fw-600 text-sm">${s.avg_rating > 0 ? parseFloat(s.avg_rating).toFixed(1) : '—'}</span>
      </td>
      <td><span class="badge ${s.is_active ? 'badge-active' : 'badge-canceled'}">${s.is_active ? 'active' : 'inactive'}</span></td>
      <td>
        <div class="d-flex ai-center gap-8">
          <button class="btn btn-outline btn-sm btn-icon" onclick="editService(${s.id})" title="Edit">✏️</button>
          <button class="btn btn-outline btn-sm btn-icon" onclick="deleteService(${s.id}, '${s.name.replace(/'/g,"\\'")}')">🗑</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function editService(id) {
  const s = SERVICES_CACHE.find(x => x.id === id);
  if (!s) return;
  editingId = id;
  document.getElementById('svc-modal-title').textContent = 'Edit Service';
  document.getElementById('svc-name').value       = s.name;
  document.getElementById('svc-desc').value       = s.description || '';
  document.getElementById('svc-price').value      = s.base_price;
  document.getElementById('svc-unit').value       = s.unit || 'lb';
  document.getElementById('svc-turnaround').value = s.turnaround_h || '';
  document.getElementById('svc-express').value    = s.is_express ? '1' : '0';
  document.getElementById('svc-icon').value       = s.icon || '';
  document.getElementById('svc-status').value     = s.is_active ? 'active' : 'inactive';
  openModal('addServiceModal');
}

async function saveService() {
  const name  = document.getElementById('svc-name')?.value.trim();
  const price = parseFloat(document.getElementById('svc-price')?.value);
  if (!name || isNaN(price)) { showToast('Service name and price are required', 'error'); return; }

  const payload = {
    name,
    description: document.getElementById('svc-desc')?.value || '',
    base_price:  price,
    unit:        document.getElementById('svc-unit')?.value || 'lb',
    turnaround_h: parseInt(document.getElementById('svc-turnaround')?.value) || 24,
    is_express:  document.getElementById('svc-express')?.value === '1' ? 1 : 0,
    icon:        document.getElementById('svc-icon')?.value || '🧺',
    is_active:   document.getElementById('svc-status')?.value === 'active' ? 1 : 0,
  };

  try {
    const path = editingId ? `/api/admin/services/${editingId}` : '/api/admin/services';
    const j = await apiFetch(path, {
      method: editingId ? 'PUT' : 'POST',
      body: JSON.stringify(payload),
    });
    if (!j.success) { showToast(j.message || 'Failed to save service.', 'error'); return; }
    showToast(`${name} ${editingId ? 'updated' : 'added'}`, 'success');
    editingId = null;
    closeModal('addServiceModal');
    loadServices();
  } catch { showToast('Network error.', 'error'); }
}

async function deleteService(id, name) {
  if (!confirm(`Delete service "${name}"? Services with existing orders will be deactivated instead.`)) return;
  try {
    const j = await apiFetch(`/api/admin/services/${id}`, { method: 'DELETE' });
    if (!j.success) { showToast(j.message || 'Failed to delete service.', 'error'); return; }
    showToast(j.message || `${name} removed`, 'warning');
    loadServices();
  } catch { showToast('Network error.', 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
  loadServices();

  document.querySelector('[onclick="openModal(\'addServiceModal\')"]')?.addEventListener('click', () => {
    editingId = null;
    document.getElementById('svc-modal-title').textContent = 'Add Service';
    ['svc-name','svc-desc','svc-price','svc-turnaround','svc-icon'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
  });
});
