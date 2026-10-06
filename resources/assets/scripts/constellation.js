// Constellation hero — a drifting node network behind the hero copy.
// Nodes = people, edges = opportunity connections. Pauses offscreen,
// static under reduced-motion, skipped on small screens.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const NODES = 42;
const LINK_DISTANCE = 130;
const POINTER_RADIUS = 160;

export function initConstellation() {
  const canvas = document.querySelector('.hero-canvas');
  if (!canvas || !(canvas instanceof HTMLCanvasElement)) return;
  if (window.innerWidth < 768) { canvas.remove(); return; }

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  let width = 0;
  let height = 0;
  let nodes = [];
  let running = false;
  let raf = 0;
  const pointer = { x: -9999, y: -9999 };

  const resize = () => {
    const rect = canvas.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    width = rect.width;
    height = rect.height;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  };

  const seed = () => {
    nodes = Array.from({ length: NODES }, () => ({
      x: Math.random() * width,
      y: Math.random() * height,
      vx: (Math.random() - 0.5) * 0.22,
      vy: (Math.random() - 0.5) * 0.22,
      r: 1.4 + Math.random() * 1.8,
      hue: Math.random() < 0.72 ? 'leaf' : 'clay',
    }));
  };

  const draw = () => {
    ctx.clearRect(0, 0, width, height);
    for (let i = 0; i < nodes.length; i++) {
      const a = nodes[i];
      for (let j = i + 1; j < nodes.length; j++) {
        const b = nodes[j];
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        const d = Math.hypot(dx, dy);
        if (d < LINK_DISTANCE) {
          ctx.strokeStyle = `rgba(110, 189, 82, ${0.16 * (1 - d / LINK_DISTANCE)})`;
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(a.x, a.y);
          ctx.lineTo(b.x, b.y);
          ctx.stroke();
        }
      }
    }
    for (const n of nodes) {
      ctx.fillStyle = n.hue === 'leaf' ? 'rgba(110, 189, 82, .55)' : 'rgba(220, 120, 60, .5)';
      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
      ctx.fill();
    }
  };

  const step = () => {
    for (const n of nodes) {
      n.x += n.vx;
      n.y += n.vy;
      const dx = pointer.x - n.x;
      const dy = pointer.y - n.y;
      const d = Math.hypot(dx, dy);
      if (d < POINTER_RADIUS && d > 0.1) {
        n.x += (dx / d) * 0.25;
        n.y += (dy / d) * 0.25;
      }
      if (n.x < -10) n.x = width + 10; else if (n.x > width + 10) n.x = -10;
      if (n.y < -10) n.y = height + 10; else if (n.y > height + 10) n.y = -10;
    }
    draw();
    if (running) raf = requestAnimationFrame(step);
  };

  const hero = canvas.closest('.hero') || canvas.parentElement;
  if (hero) {
    hero.addEventListener('pointermove', (event) => {
      const rect = canvas.getBoundingClientRect();
      pointer.x = event.clientX - rect.left;
      pointer.y = event.clientY - rect.top;
    });
    hero.addEventListener('pointerleave', () => { pointer.x = -9999; pointer.y = -9999; });
  }

  resize();
  seed();

  if (reduced) { draw(); return; }

  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting && !running) { running = true; raf = requestAnimationFrame(step); }
      else if (!entry.isIntersecting && running) { running = false; cancelAnimationFrame(raf); }
    }
  });
  observer.observe(canvas);

  window.addEventListener('resize', () => { resize(); seed(); if (reduced) draw(); }, { passive: true });
}
