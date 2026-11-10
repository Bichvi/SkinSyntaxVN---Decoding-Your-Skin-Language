(function () {
  'use strict';

  function initHomepageRails() {
    var rails = document.querySelectorAll('[data-home-rail]');
    if (!rails.length) return;

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    Array.prototype.forEach.call(rails, function (root) {
      var viewport = root.querySelector('[data-home-product-rail]');
      if (!viewport) return;

      var previous = root.querySelector('[data-home-rail-prev]');
      var next = root.querySelector('[data-home-rail-next]');
      var timer = null;

      function stepSize() {
        var item = viewport.querySelector('.home-product-rail__item');
        if (!item) return Math.max(220, viewport.clientWidth * 0.85);
        var styles = window.getComputedStyle(viewport);
        var gap = parseFloat(styles.columnGap || styles.gap || '16') || 16;
        return item.getBoundingClientRect().width + gap;
      }

      function updateButtons() {
        var canScroll = viewport.scrollWidth > viewport.clientWidth + 4;
        var atStart = viewport.scrollLeft <= 4;
        var atEnd = viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 4;
        if (previous) previous.disabled = !canScroll || atStart;
        if (next) next.disabled = !canScroll || atEnd;
      }

      function move(direction) {
        var atEnd = viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 4;
        var atStart = viewport.scrollLeft <= 4;
        if (direction > 0 && atEnd) {
          viewport.scrollTo({ left: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
          return;
        }
        if (direction < 0 && atStart) {
          viewport.scrollTo({ left: viewport.scrollWidth, behavior: reducedMotion ? 'auto' : 'smooth' });
          return;
        }
        viewport.scrollBy({ left: direction * stepSize(), behavior: reducedMotion ? 'auto' : 'smooth' });
      }

      function stopAutoPlay() {
        if (timer) {
          window.clearInterval(timer);
          timer = null;
        }
      }

      function startAutoPlay() {
        stopAutoPlay();
        if (reducedMotion || viewport.scrollWidth <= viewport.clientWidth + 4) return;
        timer = window.setInterval(function () {
          move(1);
        }, 7500);
      }

      if (previous) previous.addEventListener('click', function () { move(-1); });
      if (next) next.addEventListener('click', function () { move(1); });
      viewport.addEventListener('scroll', updateButtons, { passive: true });
      root.addEventListener('mouseenter', stopAutoPlay);
      root.addEventListener('mouseleave', startAutoPlay);
      root.addEventListener('focusin', stopAutoPlay);
      root.addEventListener('focusout', function (event) {
        if (!root.contains(event.relatedTarget)) startAutoPlay();
      });
      window.addEventListener('resize', function () {
        updateButtons();
        startAutoPlay();
      }, { passive: true });

      updateButtons();
      startAutoPlay();
    });
  }

  function initHomepageCardRails() {
    var rails = document.querySelectorAll('[data-home-card-rail]');
    if (!rails.length) return;

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    Array.prototype.forEach.call(rails, function (root) {
      var viewport = root.querySelector('[data-home-card-viewport]');
      if (!viewport) return;

      var previous = root.querySelector('[data-home-card-prev]');
      var next = root.querySelector('[data-home-card-next]');
      var item = function () { return viewport.querySelector('.home-card-rail__item'); };
      var stepSize = function () {
        var current = item();
        if (!current) return Math.max(220, viewport.clientWidth * 0.8);
        var styles = window.getComputedStyle(viewport);
        var gap = parseFloat(styles.columnGap || styles.gap || '14') || 14;
        return current.getBoundingClientRect().width + gap;
      };
      var move = function (direction) {
        var maxLeft = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
        var target = viewport.scrollLeft + direction * stepSize();
        if (target > maxLeft - 4) target = 0;
        if (target < 4) target = maxLeft;
        viewport.scrollTo({ left: target, behavior: reducedMotion ? 'auto' : 'smooth' });
      };

      if (previous) previous.addEventListener('click', function () { move(-1); });
      if (next) next.addEventListener('click', function () { move(1); });

      var touchStartX = 0;
      var touchStartY = 0;
      viewport.addEventListener('touchstart', function (event) {
        if (event.touches.length === 1) {
          touchStartX = event.touches[0].clientX;
          touchStartY = event.touches[0].clientY;
        }
      }, { passive: true });
      viewport.addEventListener('touchend', function (event) {
        if (event.changedTouches.length !== 1) return;
        var deltaX = event.changedTouches[0].clientX - touchStartX;
        var deltaY = event.changedTouches[0].clientY - touchStartY;
        if (Math.abs(deltaX) > 40 && Math.abs(deltaX) > Math.abs(deltaY) * 1.4) {
          move(deltaX < 0 ? 1 : -1);
        }
      }, { passive: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initHomepageRails();
      initHomepageCardRails();
    });
  } else {
    initHomepageRails();
    initHomepageCardRails();
  }
}());
