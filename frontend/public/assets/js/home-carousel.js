(function () {
  'use strict';

  function init(root) {
    var track = root.querySelector('[data-hgc-track]');
    var prev = root.querySelector('[data-hgc-prev]');
    var next = root.querySelector('[data-hgc-next]');
    var nav = root.querySelector('[data-hgc-nav]');
    if (!track || !prev || !next || !nav) { return; }

    function update() {
      var max = track.scrollWidth - track.clientWidth - 2;
      nav.hidden = max <= 0;
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max;
    }

    function step(dir) {
      var amount = dir * track.clientWidth;
      if (typeof track.scrollBy === 'function') {
        track.scrollBy({ left: amount, behavior: 'smooth' });
      } else {
        track.scrollLeft += amount;
      }
    }

    var ticking = false;
    function onScroll() {
      if (ticking) { return; }
      ticking = true;
      window.requestAnimationFrame(function () {
        ticking = false;
        update();
      });
    }

    prev.addEventListener('click', function () { step(-1); });
    next.addEventListener('click', function () { step(1); });
    track.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', update);
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    });
    update();
  }

  function run() {
    var roots = document.querySelectorAll('[data-hgc]');
    for (var i = 0; i < roots.length; i++) { init(roots[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
