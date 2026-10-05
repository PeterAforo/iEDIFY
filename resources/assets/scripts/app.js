import Alpine from '@alpinejs/csp';
import '../styles/app.scss';

Alpine.data('disclosure', () => ({
  open: false,
  toggle() { this.open = !this.open; },
  close() { this.open = false; },
}));

Alpine.start();
