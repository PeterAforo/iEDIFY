import Alpine from '@alpinejs/csp';
import '../styles/app.scss';
import { initReveals, initCounters } from './motion.js';
import { initNav } from './nav.js';
import { initCarousel } from './carousel.js';
import { initConstellation } from './constellation.js';
import { initAccordions } from './accordion.js';

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
  initNav();
  initCarousel();
  initConstellation();
  initAccordions();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
