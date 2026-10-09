/**
 * Album lightbox — opens images and videos from [data-lightbox] groups in a
 * full-screen viewer with previous/next navigation, keyboard support and an
 * always-available non-JS fallback (the tiles link straight to the file).
 */
export function initLightbox() {
  const groups = document.querySelectorAll('[data-lightbox]');
  if (!groups.length) {
    return;
  }

  const box = document.createElement('div');
  box.className = 'lightbox';
  box.hidden = true;
  box.innerHTML = `
    <div class="lightbox-backdrop" data-lightbox-close></div>
    <figure class="lightbox-stage" role="dialog" aria-modal="true" aria-label="Media viewer">
      <button type="button" class="lightbox-btn lightbox-close" data-lightbox-close aria-label="Close viewer">&times;</button>
      <button type="button" class="lightbox-btn lightbox-prev" aria-label="Previous item">&#8249;</button>
      <div class="lightbox-frame"></div>
      <button type="button" class="lightbox-btn lightbox-next" aria-label="Next item">&#8250;</button>
      <figcaption class="lightbox-meta"><span class="lightbox-caption"></span><span class="lightbox-count"></span></figcaption>
    </figure>`;
  document.body.appendChild(box);

  const frame = box.querySelector('.lightbox-frame');
  const caption = box.querySelector('.lightbox-caption');
  const count = box.querySelector('.lightbox-count');
  const prev = box.querySelector('.lightbox-prev');
  const next = box.querySelector('.lightbox-next');
  const closeBtn = box.querySelector('.lightbox-close');

  let items = [];
  let index = 0;
  let trigger = null;

  const show = (i) => {
    index = (i + items.length) % items.length;
    const item = items[index];
    frame.textContent = '';
    if (item.dataset.kind === 'video') {
      const video = document.createElement('video');
      video.src = item.getAttribute('href');
      video.controls = true;
      video.autoplay = true;
      video.playsInline = true;
      frame.appendChild(video);
    } else {
      const img = document.createElement('img');
      img.src = item.getAttribute('href');
      img.alt = item.dataset.caption || '';
      frame.appendChild(img);
    }
    caption.textContent = item.dataset.caption || '';
    count.textContent = `${index + 1} / ${items.length}`;
  };

  const open = (groupItems, i, triggerEl) => {
    items = groupItems;
    trigger = triggerEl;
    document.body.classList.add('lightbox-open');
    box.hidden = false;
    show(i);
    closeBtn.focus();
  };

  const close = () => {
    box.hidden = true;
    frame.textContent = '';
    document.body.classList.remove('lightbox-open');
    if (trigger) {
      trigger.focus();
      trigger = null;
    }
  };

  groups.forEach((group) => {
    const groupItems = Array.from(group.querySelectorAll('[data-lightbox-item]'));
    groupItems.forEach((el, i) => {
      el.addEventListener('click', (event) => {
        event.preventDefault();
        open(groupItems, i, el);
      });
    });
  });

  prev.addEventListener('click', () => show(index - 1));
  next.addEventListener('click', () => show(index + 1));
  box.addEventListener('click', (event) => {
    if (event.target.hasAttribute('data-lightbox-close')) {
      close();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (box.hidden) {
      return;
    }
    if (event.key === 'Escape') {
      close();
    } else if (event.key === 'ArrowLeft') {
      show(index - 1);
    } else if (event.key === 'ArrowRight') {
      show(index + 1);
    }
  });
}
