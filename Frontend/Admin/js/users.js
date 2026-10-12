'use strict';
/* ── CUSTOMERS PAGE — live API ── */

let uPage = 1;
const U_PER = 10;
let uRole   = 'all';
let uStatus = 'all';
let uSearch = '';
let viewingUserId = null;

async function apiFetch(path, opts = {}) {
  const res = await fetch(API_BASE + path, {
    credentials: 'include',
    headers: { 'X-CSRF-Token': getCsrfToken(), 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  if (res.status === 401 || res.status === 403) { clearSession(); window.location.replace('login.html'); return { success: false, message: '' }; }
  return res.json();
}

async function loadCustomerStats() {
  try {
    const j = await apiFetch('/api/admin/customers/stats');
    if (!j.success) return;
    const d = j.data;
    const el = id => document.getElementById(id);
    if (el('stat-total'))     animateCount(el('stat-total'),     d.total);
    if (el('stat-active'))    animateCount(el('stat-active'),    d.active);
    if (el('stat-new-month')) animateCount(el('stat-new-month'), d.new_month);
  } catch { /* ignore */ }
}

async function loadCustomers() {
  const body = document.getElementById('users-body');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">Loading…</td></tr>`;

  const params = new URLSearchParams({
    page: uPage, per_page: U_PER,
    ...(uSearch && { search: uSearch }),
    ...(uRole   !== 'all' && { role:   uRole }),
    ...(uStatus !== 'all' && { status: uStatus }),
  });

  try {
    const j = await apiFetch(`/api/admin/customers?${params}`);
    if (!j.success) { showToast(j.message || 'Failed to load customers.', 'error'); return; }
    renderCustomerTable(j.data.customers);
    const from = j.data.total > 0 ? (uPage - 1) * U_PER + 1 : 0;
    renderUserPagination({
      total:     j.data.total,
      from,
      to:        Math.min(from + U_PER - 1, j.data.total),
      last_page: j.data.total_pages,
    });
  } catch (err) {
    console.error('[users] load error:', err);
    body.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--red);">Failed to load customers.</td></tr>`;
  }
}

function renderCustomerTable(customers) {
  const body = document.getElementById('users-body');
  if (!body) return;
  if (!customers || !customers.length) {
    body.innerHTML = `<tr><td colspan="8"><div class="empty-state"><div class="empty-icon">👤</div><p>No customers found</p></div></td></tr>`;
    return;
  }
  body.innerHTML = customers.map((u, i) => `
    <tr>
      <td style="color:var(--gray-400);font-size:12px;text-align:center;">${(uPage - 1) * U_PER + i + 1}</td>
      <td>
        <div class="d-flex ai-center gap-8">
          <div class="avatar">${initials(u.full_name)}</div>
          <div>
            <div class="fw-600" style="font-size:13px;">${escHtml(u.full_name)}</div>
            <div class="text-muted text-sm">${escHtml(u.email)}</div>
          </div>
        </div>
      </td>
      <td class="text-sm">${escHtml(u.phone || '—')}</td>
      <td>${statusBadge(u.role || 'customer')}</td>
      <td>${statusBadge(u.status)}</td>
      <td class="fw-600 text-sm">${u.orders_count ?? 0}</td>
      <td class="text-muted text-sm">${formatDate(u.created_at)}</td>
      <td>
        <div class="d-flex ai-center gap-8">
          <button class="btn btn-outline btn-sm btn-icon" title="View" onclick="viewUser(${u.id})">👁</button>
          <div class="dropdown">
            <button class="btn btn-outline btn-sm btn-icon" data-dropdown>⋯</button>
            <div class="dropdown-menu">
              <div class="dropdown-item" onclick="viewUser(${u.id})">👁 View Profile</div>
              <div class="dropdown-item danger" onclick="toggleSuspend(${u.id}, ${jsArg(u.status)}, ${jsArg(u.full_name)})">
                ${u.status === 'suspended' ? '✅ Activate' : '🚫 Suspend'}
              </div>
            </div>
          </div>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderUserPagination(p) {
  const info = document.getElementById('users-page-info');
  if (info) info.textContent = `Showing ${p.total ? p.from : 0}–${p.to} of ${p.total}`;

  const prev = document.getElementById('users-prev');
  const next = document.getElementById('users-next');
  if (prev) prev.disabled = uPage <= 1;
  if (next) next.disabled = uPage >= p.last_page;

  const nums = document.getElementById('users-page-nums');
  if (nums) {
    const pages = [];
    for (let pg = Math.max(1, uPage - 2); pg <= Math.min(p.last_page, uPage + 2); pg++) pages.push(pg);
    nums.innerHTML = pages.map(pg =>
      `<button class="page-btn ${pg === uPage ? 'active' : ''}" onclick="uGoPage(${pg})">${pg}</button>`
    ).join('');
  }
}

function uGoPage(p) { uPage = p; loadCustomers(); }

async function viewUser(id) {
  viewingUserId = id;
  openModal('viewUserModal');

  document.getElementById('vu-name').textContent = 'Loading…';
  try {
    const j = await apiFetch(`/api/admin/customers/${id}`);
    if (!j.success) { showToast('Failed to load customer.', 'error'); return; }
    const u            = j.data.customer;
    const recentOrders = j.data.orders || [];

    document.getElementById('vu-avatar').textContent = initials(u.full_name);
    document.getElementById('vu-name').textContent = u.full_name;
    document.getElementById('vu-email').textContent = u.email;
    document.getElementById('vu-status-badge').innerHTML = statusBadge(u.status);
    document.getElementById('vu-details').innerHTML = `
      <div><span style="color:var(--gray-500);">Phone:</span> ${escHtml(u.phone || '—')}</div>
      <div><span style="color:var(--gray-500);">Address:</span> ${escHtml(u.address || '—')}</div>
      <div><span style="color:var(--gray-500);">Role:</span> ${escHtml(u.role || 'customer')}</div>
      <div><span style="color:var(--gray-500);">Joined:</span> ${formatDate(u.created_at)}</div>
      <div><span style="color:var(--gray-500);">Total Orders:</span> <strong>${u.order_count ?? 0}</strong></div>
      <div><span style="color:var(--gray-500);">Total Spent:</span> <strong>${formatCurrency(u.total_spent ?? 0)}</strong></div>
    `;
    document.getElementById('vu-orders').innerHTML = recentOrders.length
      ? recentOrders.map(o => `
        <div class="d-flex ai-center gap-8" style="padding:8px 0;border-bottom:1px solid var(--gray-100);">
          <span class="fw-600" style="color:var(--primary);font-size:12px;flex:1;">${escHtml(o.order_number)}</span>
          <span class="text-sm">${escHtml(o.service_name)}</span>
          ${statusBadge(o.status)}
          <span class="fw-600 text-sm">${formatCurrency(o.total_amount)}</span>
        </div>
      `).join('')
      : '<div style="color:var(--gray-400);font-size:12px;text-align:center;padding:20px;">No orders yet.</div>';

    const suspBtn = document.getElementById('vu-suspend-btn');
    if (suspBtn) {
      const isSuspended = u.status === 'suspended';
      suspBtn.textContent = isSuspended ? 'Activate Account' : 'Suspend Account';
      suspBtn.className   = isSuspended ? 'btn btn-success btn-sm' : 'btn btn-danger btn-sm';
      suspBtn.onclick     = () => toggleSuspend(id, u.status, u.full_name);
    }
  } catch (err) {
    console.error('[users] view error:', err);
    showToast('Failed to load customer details.', 'error');
  }
}

async function toggleSuspend(id, currentStatus, name) {
  const newStatus = currentStatus === 'suspended' ? 'active' : 'suspended';
  try {
    const j = await apiFetch(`/api/admin/customers/${id}/status`, {
      method: 'PUT',
      body: JSON.stringify({ status: newStatus }),
    });
    if (!j.success) { showToast(j.message || 'Failed to update status.', 'error'); return; }
    showToast(`${name} account ${newStatus}`, newStatus === 'active' ? 'success' : 'warning');
    closeModal('viewUserModal');
    loadCustomers();
    loadCustomerStats();
  } catch { showToast('Network error.', 'error'); }
}

async function addUser() {
  const first = document.getElementById('add-first')?.value.trim();
  const last  = document.getElementById('add-last')?.value.trim();
  const email = document.getElementById('add-email')?.value.trim();
  const phone = document.getElementById('add-phone')?.value.trim();
  const role  = document.getElementById('add-role')?.value || 'customer';
  const pass  = document.getElementById('add-pass')?.value;

  if (!first || !last || !email || !pass) {
    showToast('Please fill in all required fields', 'error');
    return;
  }

  try {
    const j = await apiFetch('/api/admin/customers', {
      method: 'POST',
      body: JSON.stringify({ first_name: first, last_name: last, email, phone, role, password: pass }),
    });
    if (!j.success) { showToast(j.message || 'Failed to create account.', 'error'); return; }
    showToast(`Account created for ${first} ${last}`, 'success');
    closeModal('addUserModal');
    ['add-first','add-last','add-email','add-phone','add-pass'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadCustomers();
    loadCustomerStats();
  } catch { showToast('Network error.', 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
  loadCustomerStats();
  loadCustomers();

  document.getElementById('userSearch')?.addEventListener('input', e => {
    uSearch = e.target.value.trim();
    uPage = 1;
    loadCustomers();
  });

  document.getElementById('users-prev')?.addEventListener('click', () => { uPage--; loadCustomers(); });
  document.getElementById('users-next')?.addEventListener('click', () => { uPage++; loadCustomers(); });

  document.querySelectorAll('#roleFilters .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#roleFilters .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      uRole = chip.dataset.role;
      uPage = 1;
      loadCustomers();
    });
  });

  document.querySelectorAll('#statusFilterUsers .filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#statusFilterUsers .filter-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      uStatus = chip.dataset.ustatus;
      uPage = 1;
      loadCustomers();
    });
  });
});
