(() => {
  'use strict';

  const viewport = document.querySelector('[data-home-live-products]');
  if (!viewport) return;

  const cards = Array.from(viewport.querySelectorAll('.home-live-product-card'));
  const previous = document.querySelector('[data-home-live-prev]');
  const next = document.querySelector('[data-home-live-next]');
  const current = document.querySelector('[data-home-live-current]');
  if (cards.length < 2) return;

  const activeIndex = () => {
    const viewportLeft = viewport.getBoundingClientRect().left;
    let closestIndex = 0;
    let closestDistance = Number.POSITIVE_INFINITY;
    cards.forEach((card, index) => {
      const distance = Math.abs(card.getBoundingClientRect().left - viewportLeft);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestIndex = index;
      }
    });
    return closestIndex;
  };

  const update = () => {
    const index = activeIndex();
    if (current) current.textContent = String(index + 1);
    if (previous) previous.disabled = index === 0;
    if (next) next.disabled = index === cards.length - 1;
  };

  const move = direction => {
    const target = Math.max(0, Math.min(cards.length - 1, activeIndex() + direction));
    const firstCardOffset = cards[0].offsetLeft;
    viewport.scrollTo({
      left: cards[target].offsetLeft - firstCardOffset,
      behavior: 'smooth'
    });
  };

  previous?.addEventListener('click', () => move(-1));
  next?.addEventListener('click', () => move(1));
  viewport.addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    move(event.key === 'ArrowRight' ? 1 : -1);
  });

  let updateFrame = 0;
  viewport.addEventListener('scroll', () => {
    cancelAnimationFrame(updateFrame);
    updateFrame = requestAnimationFrame(update);
  }, {passive: true});
  update();
})();
