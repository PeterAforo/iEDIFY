import Alpine from '@alpinejs/csp';
import '../styles/app.scss';
import { initReveals, initCounters, initParallax } from './motion.js';
import { initNav } from './nav.js';
import { initCarousel } from './carousel.js';
import { initAccordions } from './accordion.js';
import { initLightbox } from './lightbox.js';
import { initAuth } from './auth.js';

document.documentElement.classList.add('js');

Alpine.data('disclosure', () => ({
  open: false,
  toggle() { this.open = !this.open; },
  close() { this.open = false; },
}));
Alpine.start();

const init = () => {
  initReveals();
  initCounters();
  initParallax();
  initNav();
  initCarousel();
  initAccordions();
  initLightbox();
  initAuth();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
