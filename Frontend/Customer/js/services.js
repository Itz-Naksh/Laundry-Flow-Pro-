/* ── Services page ── */
'use strict';

const unitLabel = { lb: 'per lb', item: 'per item', bag: 'per bag' };

async function loadServices() {
  const grid = document.getElementById('services-grid');
  try {
    const res  = await fetch(`${API_BASE}/api/services`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const services = data.data.services;
    if (!services.length) {
      grid.innerHTML = `<div class="state-msg" style="grid-column:1/-1">
        <i class="fas fa-box-open"></i>No services available at the moment.</div>`;
      return;
    }

    grid.innerHTML = services.map(s => {
      const fullStars  = Math.floor(s.avg_rating || 0);
      const hasRating  = s.review_count > 0;
      const starsStr   = '★'.repeat(fullStars) + '☆'.repeat(5 - fullStars);
      const ratingHtml = hasRating
        ? `<div class="svc-rating-row">
             <span class="svc-stars" style="color:#F59E0B;font-size:13px;letter-spacing:1px;">${starsStr}</span>
             <span style="font-size:12px;font-weight:700;color:#475569;">${(s.avg_rating).toFixed(1)}</span>
             <span style="font-size:11px;color:#94A3B8;">(${s.review_count} review${s.review_count !== 1 ? 's' : ''})</span>
           </div>`
        : '<div class="svc-rating-row" style="color:#94A3B8;font-size:11px;">No reviews yet</div>';

      return `
        <div class="svc-booking-card">
          <div class="svc-emoji">${escHtml(s.icon || '🧺')}</div>
          <h3>${escHtml(s.name)}</h3>
          <p>${escHtml(s.description || '')}</p>
          ${ratingHtml}
          <div class="svc-meta-row">
            <span class="svc-price">LKR ${s.base_price.toFixed(2)}</span>
            <span class="svc-unit">${escHtml(unitLabel[s.unit] || s.unit)}</span>
            ${s.is_express ? '<span class="express-badge">⚡ Express</span>' : ''}
            <span class="svc-turnaround"><i class="fas fa-clock"></i> ${s.turnaround_h}h</span>
          </div>
          <button class="btn-book-now" onclick="bookService(${s.id})">
            Book Now <i class="fas fa-arrow-right"></i>
          </button>
        </div>`;
    }).join('');
  } catch (e) {
    grid.innerHTML = `<div class="state-msg" style="grid-column:1/-1">
      <i class="fas fa-exclamation-circle"></i>${escHtml(e.message || 'Failed to load services.')}</div>`;
  }
}

function bookService(serviceId) {
  const user = getStoredUser();
  if (!user) {
    window.location.href = `login.html?return=${encodeURIComponent('services.html')}`;
    return;
  }
  // Persist chosen service ID and redirect to schedule pickup
  sessionStorage.setItem('lfp_booking_service_id', serviceId);
  window.location.href = 'schedule-pickup.html';
}

// ── Navbar auth state ─────────────────────────────────────────────────────────
function initNavbar() {
  const user = getStoredUser();
  if (user) {
    document.getElementById('nav-guest').style.display = 'none';
    const auth = document.getElementById('nav-auth');
    auth.style.display = 'flex';
    const greeting = document.getElementById('nav-user-greeting');
    if (greeting) greeting.textContent = user.full_name?.split(' ')[0] || 'Profile';
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

  // Hamburger
  const ham = document.getElementById('hamburger');
  const nav = document.getElementById('nav-links');
  if (ham && nav) {
    ham.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      ham.setAttribute('aria-expanded', open);
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  initNavbar();
  loadServices();
});

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
