// Header morph + mobile drawer with focus trapping.

export function initNav() {
  const header = document.querySelector('.site-header');
  const nav = document.getElementById('site-nav');
  const toggle = document.querySelector('.nav-toggle');
  const backdrop = document.querySelector('.nav-backdrop');

  if (header) {
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        header.classList.toggle('is-scrolled', window.scrollY > 24);
        ticking = false;
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  if (!nav || !toggle) return;

  const focusable = () => nav.querySelectorAll('a[href], button:not([disabled])');
  let open = false;

  const setOpen = (state) => {
    open = state;
    nav.classList.toggle('nav-open', state);
    if (backdrop) backdrop.classList.toggle('nav-open', state);
    document.body.classList.toggle('nav-locked', state);
    toggle.setAttribute('aria-expanded', state ? 'true' : 'false');
    if (state) {
      const first = focusable()[0];
      if (first) first.focus();
    } else {
      toggle.focus();
    }
  };

  toggle.addEventListener('click', () => setOpen(!open));
  if (backdrop) backdrop.addEventListener('click', () => setOpen(false));

  document.addEventListener('keydown', (event) => {
    if (!open) return;
    if (event.key === 'Escape') { setOpen(false); return; }
    if (event.key === 'Tab') {
      const items = focusable();
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  nav.addEventListener('click', (event) => {
    if (event.target.closest('a') && open) setOpen(false);
  });
}
