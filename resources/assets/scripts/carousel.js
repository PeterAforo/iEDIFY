// Hero carousel — crossfades slides, auto-advances with hover/focus pause,
// dots + arrows only rendered when more than one slide exists. Without JS
// all slides render stacked and fully readable.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initCarousel() {
  const slides = [...document.querySelectorAll('.hero-slide')];
  if (slides.length === 0) return;
  slides[0].classList.add('is-active');
  if (slides.length === 1) return;

  const hero = slides[0].closest('.hero');
  const controls = document.createElement('div');
  controls.className = 'hero-controls';

  const dots = document.createElement('div');
  dots.className = 'hero-dots';
  dots.setAttribute('role', 'tablist');
  dots.setAttribute('aria-label', 'Slides');

  const dotButtons = slides.map((slide, i) => {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'hero-dot' + (i === 0 ? ' is-active' : '');
    dot.setAttribute('role', 'tab');
    dot.setAttribute('aria-label', `Slide ${i + 1} of ${slides.length}`);
    dot.addEventListener('click', () => go(i, true));
    dots.appendChild(dot);
    return dot;
  });

  const makeArrow = (label, glyph, dir) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hero-arrow';
    btn.setAttribute('aria-label', label);
    btn.textContent = glyph;
    btn.addEventListener('click', () => go(current + dir, true));
    return btn;
  };

  controls.appendChild(makeArrow('Previous slide', '\u2039', -1));
  controls.appendChild(dots);
  controls.appendChild(makeArrow('Next slide', '\u203A', 1));
  hero.appendChild(controls);

  let current = 0;
  let timer = null;
  let hovered = false;

  const go = (index, manual = false) => {
    slides[current].classList.remove('is-active');
    dotButtons[current].classList.remove('is-active');
    current = (index + slides.length) % slides.length;
    slides[current].classList.add('is-active');
    dotButtons[current].classList.add('is-active');
    if (manual) restart();
  };

  const restart = () => {
    if (timer) clearInterval(timer);
    if (!reduced && !hovered) timer = setInterval(() => go(current + 1), 7000);
  };

  hero.addEventListener('mouseenter', () => { hovered = true; if (timer) clearInterval(timer); });
  hero.addEventListener('mouseleave', () => { hovered = false; restart(); });
  hero.addEventListener('focusin', () => { hovered = true; if (timer) clearInterval(timer); });
  hero.addEventListener('focusout', () => { hovered = false; restart(); });

  restart();
}
