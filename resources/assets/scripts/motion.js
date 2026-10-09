// Reveal-on-scroll + stat counters. Everything degrades to fully visible
// content when JS or IntersectionObserver is unavailable.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initReveals() {
  const targets = document.querySelectorAll('[data-reveal], [data-reveal-group]');
  if (targets.length === 0) return;
  if (reduced || !('IntersectionObserver' in window)) {
    targets.forEach((el) => el.classList.add('is-visible'));
    return;
  }
  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    }
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
  targets.forEach((el) => observer.observe(el));
}

// Parallax: oversized background images (e.g. the reflections band) drift
// vertically as the section crosses the viewport. No-op under reduced motion.
export function initParallax() {
  const els = [...document.querySelectorAll('[data-parallax]')];
  if (els.length === 0 || reduced) return;
  let ticking = false;
  const update = () => {
    ticking = false;
    const vh = window.innerHeight;
    for (const el of els) {
      const rect = el.parentElement.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > vh) continue;
      const p = Math.min(1, Math.max(0, (vh - rect.top) / (vh + rect.height)));
      el.style.transform = `translate3d(0, ${((p - 0.5) * 20).toFixed(2)}%, 0)`;
    }
  };
  const onScroll = () => {
    if (!ticking) {
      ticking = true;
      requestAnimationFrame(update);
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  update();
}

export function initCounters() {
  const values = document.querySelectorAll('.statistic-value[data-count]');
  if (values.length === 0) return;
  const run = (el) => {
    const raw = el.textContent.trim();
    const match = raw.match(/^([^\d]*)(\d+(?:\.\d+)?)([^\d]*)$/);
    if (!match) return;
    const [, prefix, num, suffix] = match;
    const target = parseFloat(num);
    const decimals = (num.split('.')[1] || '').length;
    if (reduced) { el.textContent = raw; return; }
    const duration = 1100;
    const start = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = prefix + (target * eased).toFixed(decimals) + suffix;
      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = raw;
    };
    requestAnimationFrame(tick);
  };
  if (!('IntersectionObserver' in window)) { values.forEach(run); return; }
  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) { run(entry.target); observer.unobserve(entry.target); }
    }
  }, { threshold: 0.4 });
  values.forEach((el) => observer.observe(el));
}
