/* ═══════════════════════════════════════════════════════════════
   Laundry Flow Pro — main.js
   Handles: Navbar, Hamburger, Smooth scroll, IntersectionObserver,
            FAQ accordion, Testimonials carousel, Pricing toggle,
            Toast notifications, Back-to-top
═══════════════════════════════════════════════════════════════ */

'use strict';

/* ─── AUTH-AWARE NAVBAR ──────────────────────────────────────────
   Reads the cached user object written by login.js after a
   successful login.  The PHP session cookie (lfp_session) is the
   real authority — this just drives the UI instantly on load.
   A background GET /api/auth/profile call verifies the session is
   still alive; if it has expired we clear the stale cache and
   redirect to login.html.
─────────────────────────────────────────────────────────────────── */
(function initAuthNav() {
  const user = getStoredUser();           // from config.js

  const guestEl  = document.getElementById('nav-guest');
  const authEl   = document.getElementById('nav-auth');
  const greeting = document.getElementById('nav-user-greeting');

  if (user) {
    /* Show auth state immediately — no layout flash */
    if (guestEl) guestEl.style.display = 'none';
    if (authEl)  authEl.style.display  = 'flex';
    if (greeting) {
      greeting.textContent = (user.full_name || user.email || 'Profile').split(' ')[0];
    }

    /* Background session validation */
    fetch(API_BASE + '/api/auth/profile', {
      credentials: 'include',
      headers: { 'X-CSRF-Token': getCsrfToken() },
    }).then(res => {
      if (res.status === 401) {
        /* Server session expired — clean up client state */
        clearSession();
        window.location.href = 'login.html';
      }
    }).catch(() => { /* offline / server down — keep local state */ });

    /* Logout button */
    document.getElementById('nav-logout-btn')?.addEventListener('click', async () => {
      try {
        await fetch(API_BASE + '/api/auth/logout', {
          method:      'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
          },
        });
      } catch { /* ignore network error — still clear client state */ }
      clearSession();
      window.location.href = 'login.html';
    });
  }
})();

/* ─── NAVBAR SCROLL ──────────────────────────────────────────── */
(function initNavbar() {
  const navbar    = document.getElementById('navbar');
  const hamburger = document.getElementById('hamburger');
  const navLinks  = document.getElementById('nav-links');

  if (!navbar) return;

  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 40);
    document.getElementById('back-top')?.classList.toggle('visible', window.scrollY > 400);
  }, { passive: true });

  /* Hamburger toggle */
  hamburger?.addEventListener('click', () => {
    const isOpen = hamburger.classList.toggle('open');
    hamburger.setAttribute('aria-expanded', isOpen);
    navLinks?.classList.toggle('open', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });

  /* Close mobile menu on link click */
  navLinks?.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', () => {
      hamburger?.classList.remove('open');
      navLinks.classList.remove('open');
      document.body.style.overflow = '';
    });
  });

  /* Active nav link on scroll */
  const sections = document.querySelectorAll('section[id]');
  const links    = document.querySelectorAll('.nav-link');

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        links.forEach(l => l.classList.remove('active'));
        const active = [...links].find(l => l.getAttribute('href') === `#${entry.target.id}`);
        active?.classList.add('active');
      }
    });
  }, { threshold: 0.4 });

  sections.forEach(s => observer.observe(s));
})();

/* ─── SCROLL ANIMATIONS ──────────────────────────────────────── */
(function initScrollAnimations() {
  const els = document.querySelectorAll('[data-animate]');
  if (!els.length) return;

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const delay = parseInt(entry.target.dataset.delay || 0, 10);
      setTimeout(() => entry.target.classList.add('visible'), delay);
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.12 });

  els.forEach(el => observer.observe(el));
})();

/* ─── FAQ ACCORDION ──────────────────────────────────────────── */
(function initFAQ() {
  document.querySelectorAll('.faq-q').forEach(btn => {
    btn.addEventListener('click', () => {
      const item   = btn.closest('.faq-item');
      const answer = item.querySelector('.faq-a');
      const isOpen = item.classList.contains('open');

      /* Close all */
      document.querySelectorAll('.faq-item.open').forEach(openItem => {
        openItem.classList.remove('open');
        openItem.querySelector('.faq-a').style.maxHeight = null;
        openItem.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
      });

      /* Open clicked */
      if (!isOpen) {
        item.classList.add('open');
        answer.style.maxHeight = answer.scrollHeight + 'px';
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });
})();

/* ─── TESTIMONIALS CAROUSEL ──────────────────────────────────── */
let _carAutoPlay;

function initCarousel() {
  const track    = document.getElementById('carousel-track');
  const dotsWrap = document.getElementById('car-dots');
  const prevBtn  = document.getElementById('car-prev');
  const nextBtn  = document.getElementById('car-next');

  if (!track) return;

  clearInterval(_carAutoPlay);
  if (dotsWrap) dotsWrap.innerHTML = '';

  const cards  = track.querySelectorAll('.testi-card');
  const total  = cards.length;
  let current  = 0;

  cards.forEach((_, i) => {
    const dot = document.createElement('button');
    dot.className = 'car-dot' + (i === 0 ? ' active' : '');
    dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
    dot.addEventListener('click', () => goTo(i));
    dotsWrap?.appendChild(dot);
  });

  function goTo(index) {
    if (total === 0) return;
    current = (index + total) % total;
    const cardW = cards[0].offsetWidth + 28;
    track.style.transform = `translateX(-${current * cardW}px)`;
    dotsWrap?.querySelectorAll('.car-dot').forEach((d, i) =>
      d.classList.toggle('active', i === current)
    );
  }

  prevBtn?.addEventListener('click', () => { goTo(current - 1); resetAuto(); });
  nextBtn?.addEventListener('click', () => { goTo(current + 1); resetAuto(); });

  function startAuto() { _carAutoPlay = setInterval(() => goTo(current + 1), 5000); }
  function resetAuto() { clearInterval(_carAutoPlay); startAuto(); }

  if (total > 0) {
    startAuto();
    window.addEventListener('resize', () => goTo(current));
  }
}

/* ─── FEATURED REVIEWS LOADER ────────────────────────────────── */
const AVATAR_GRADIENTS = [
  'linear-gradient(135deg,#667eea,#764ba2)',
  'linear-gradient(135deg,#f093fb,#f5576c)',
  'linear-gradient(135deg,#4facfe,#00f2fe)',
  'linear-gradient(135deg,#43e97b,#38f9d7)',
  'linear-gradient(135deg,#fa709a,#fee140)',
  'linear-gradient(135deg,#a18cd1,#fbc2eb)',
];

function buildStarIcons(rating) {
  const full = Math.floor(rating);
  const half = (rating - full) >= 0.5;
  const empty = 5 - full - (half ? 1 : 0);
  return '<i class="fas fa-star"></i>'.repeat(full) +
    (half ? '<i class="fas fa-star-half-alt"></i>' : '') +
    '<i class="far fa-star"></i>'.repeat(empty);
}

async function loadFeaturedReviews() {
  const track = document.getElementById('carousel-track');
  if (!track) return;

  try {
    const res  = await fetch(API_BASE + '/api/reviews/featured');
    const data = await res.json();

    if (!data.success || !data.data.reviews.length) {
      track.innerHTML = `
        <div class="testi-card" style="text-align:center;padding:48px 24px;">
          <div style="font-size:48px;margin-bottom:12px;">⭐</div>
          <p style="color:var(--gray-500,#64748B);">Be the first to leave a review!</p>
        </div>`;
      initCarousel();
      return;
    }

    track.innerHTML = data.data.reviews.map((r, i) => {
      const initParts = (r.customer_name || '?').split(' ');
      const av = (initParts[0]?.[0] || '') + (initParts[1]?.[0] || '');
      const gradient = AVATAR_GRADIENTS[i % AVATAR_GRADIENTS.length];
      const date = new Date(r.created_at).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });

      return `
        <div class="testi-card">
          <div class="testi-stars">${buildStarIcons(r.rating)}</div>
          <p>"${escHtml(r.review_text || '')}"</p>
          <div class="testi-author">
            <div class="author-av" style="background:${gradient}">${escHtml(av.toUpperCase())}</div>
            <div>
              <strong>${escHtml(r.customer_name)}</strong>
              <span>${r.service_name ? escHtml(r.service_name) + ' · ' : ''}${date}</span>
            </div>
          </div>
        </div>`;
    }).join('');

    initCarousel();
  } catch (e) {
    console.error('[reviews] featured load error:', e);
    track.innerHTML = `
      <div class="testi-card" style="text-align:center;padding:40px 24px;">
        <p style="color:#94A3B8;">Reviews temporarily unavailable.</p>
      </div>`;
    initCarousel();
  }
}

/* ─── SERVICE RATINGS INJECTOR ───────────────────────────────── */
async function injectServiceRatings() {
  try {
    const res  = await fetch(API_BASE + '/api/services');
    const data = await res.json();
    if (!data.success) return;

    data.data.services.forEach(svc => {
      if (!svc.avg_rating || svc.review_count === 0) return;
      const card = document.querySelector(`[data-service-id="${svc.id}"]`);
      if (!card) return;
      const link = card.querySelector('.svc-link');
      if (!link) return;

      const full  = Math.floor(svc.avg_rating);
      const stars = '★'.repeat(full) + '☆'.repeat(5 - full);

      const el = document.createElement('div');
      el.style.cssText = 'display:flex;align-items:center;gap:6px;margin:10px 0;';
      el.innerHTML = `
        <span style="color:#F59E0B;font-size:13px;letter-spacing:1px;">${stars}</span>
        <span style="font-size:12px;font-weight:700;color:#475569;">${svc.avg_rating.toFixed(1)}</span>
        <span style="font-size:11px;color:#94A3B8;">(${svc.review_count} review${svc.review_count !== 1 ? 's' : ''})</span>`;
      link.parentNode.insertBefore(el, link);
    });
  } catch { /* ratings are enhancement — fail silently */ }
}

document.addEventListener('DOMContentLoaded', () => {
  loadFeaturedReviews();
  injectServiceRatings();
});

/* ─── PRICING TOGGLE ─────────────────────────────────────────── */
(function initPricing() {
  const tog    = document.getElementById('billing-tog');
  const prices = document.querySelectorAll('.pc-amt');

  if (!tog) return;

  tog.addEventListener('click', () => {
    const yearly = tog.getAttribute('aria-checked') !== 'true';
    tog.setAttribute('aria-checked', yearly ? 'true' : 'false');

    prices.forEach(p => {
      const target = parseInt(yearly ? p.dataset.y : p.dataset.m, 10);
      animateNumber(p, parseInt(p.textContent, 10), target, 400);
    });
  });

  function animateNumber(el, from, to, duration) {
    const start = performance.now();
    function step(timestamp) {
      const progress = Math.min((timestamp - start) / duration, 1);
      el.textContent = Math.round(from + (to - from) * easeOut(progress));
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function easeOut(t) { return 1 - Math.pow(1 - t, 3); }
})();

/* ─── BACK TO TOP ────────────────────────────────────────────── */
document.getElementById('back-top')?.addEventListener('click', () => {
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

/* ─── TOAST SYSTEM ───────────────────────────────────────────── */
window.showToast = function(message, type = 'info', duration = 4000) {
  const wrap = document.getElementById('toast-wrap');
  if (!wrap) return;

  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <i class="fas ${icons[type] || icons.info} toast-icon"></i>
    <span>${escHtml(message)}</span>
    <button class="toast-close" aria-label="Close"><i class="fas fa-times"></i></button>
  `;

  toast.querySelector('.toast-close').addEventListener('click', () => dismiss(toast));
  wrap.appendChild(toast);

  const timer = setTimeout(() => dismiss(toast), duration);

  function dismiss(el) {
    clearTimeout(timer);
    el.classList.add('hide');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }
};

/* ─── COUNTER ANIMATION (HERO STATS) ─────────────────────────── */
(function initCounters() {
  const counters = document.querySelectorAll('.stat-num[data-target]');
  if (!counters.length) return;

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el     = entry.target;
      const target = parseInt(el.dataset.target, 10);
      let current  = 0;
      const step   = target / 60;
      const timer  = setInterval(() => {
        current += step;
        if (current >= target) { el.textContent = (target / 1000).toFixed(0); clearInterval(timer); }
        else el.textContent = Math.floor(current / 1000);
      }, 25);
      observer.unobserve(el);
    });
  }, { threshold: 0.5 });

  counters.forEach(c => observer.observe(c));
})();

/* ─── SMOOTH ANCHOR SCROLL ───────────────────────────────────── */
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', e => {
    const target = document.querySelector(anchor.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    const offset = 80;
    window.scrollTo({ top: target.offsetTop - offset, behavior: 'smooth' });
  });
});

/* ─── HOMEPAGE SERVICE BOOKING ───────────────────────────────── */
(function initHomeBooking() {
  document.querySelectorAll('.book-now-link').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      const card = link.closest('[data-service-id]');
      const id   = card?.dataset?.serviceId;
      if (!id) { window.location.href = 'services.html'; return; }

      const user = getStoredUser();
      if (!user) {
        window.location.href = `login.html?return=${encodeURIComponent('schedule-pickup.html')}`;
        return;
      }
      sessionStorage.setItem('lfp_booking_service_id', id);
      window.location.href = 'schedule-pickup.html';
    });
  });
})();

/* ─── WELCOME POPUP — once per login only ─────────────────────────
   login.js sets the 'lfp_show_welcome' flag immediately after a
   successful login. We consume it once here, so the popup NEVER
   reappears on page refresh, navigation, or browser reopen — it only
   shows right after the user logs in. */
window.addEventListener('load', () => {
  if (sessionStorage.getItem('lfp_show_welcome') !== '1') return;
  sessionStorage.removeItem('lfp_show_welcome');

  const user  = getStoredUser();
  const first = (user?.full_name || '').split(' ')[0] || 'there';
  setTimeout(() => showToast(`Welcome back, ${first}! 🎉`, 'success', 4000), 400);
});
