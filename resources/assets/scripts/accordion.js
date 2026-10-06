// Enhance native <details> elements with a smooth height animation via the
// Web Animations API. Without JS they remain fully functional toggles.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initAccordions() {
  if (reduced) return;
  document.querySelectorAll('details').forEach((details) => {
    const summary = details.querySelector('summary');
    if (!summary) return;
    summary.addEventListener('click', (event) => {
      event.preventDefault();
      if (details.open) {
        const anim = details.animate([{ height: `${details.offsetHeight}px` }, { height: `${summary.offsetHeight}px` }], { duration: 220, easing: 'ease-out' });
        anim.onfinish = () => { details.open = false; };
      } else {
        details.open = true;
        details.animate([{ height: `${summary.offsetHeight}px` }, { height: `${details.offsetHeight}px` }], { duration: 260, easing: 'ease-out' });
      }
    });
  });
}
