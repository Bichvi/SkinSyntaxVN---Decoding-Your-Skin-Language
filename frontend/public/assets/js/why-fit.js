(function () {
  'use strict';
  // Hộp "Vì sao phù hợp" trên thẻ sản phẩm: bấm/Enter/Space để mở popover chi tiết, Esc hoặc bấm ra ngoài để đóng.
  // Hover đã được CSS xử lý trên thiết bị có chuột.
  var SEL = '.why-fit.why-fit--has-detail'; // hộp không có chi tiết thì không có popup

  function setOpen(box, open) {
    if (open) {
      box.classList.add('is-open');
    } else {
      box.classList.remove('is-open');
    }
    box.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function closeAll(except) {
    var opened = document.querySelectorAll(SEL + '.is-open');
    for (var i = 0; i < opened.length; i++) {
      if (opened[i] !== except) setOpen(opened[i], false);
    }
  }

  function toggle(box) {
    var willOpen = !box.classList.contains('is-open');
    closeAll(box);
    setOpen(box, willOpen);
  }

  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if ((el.matches || el.msMatchesSelector).call(el, sel)) return el;
      el = el.parentNode;
    }
    return null;
  }

  document.addEventListener('click', function (e) {
    var box = closest(e.target, SEL);
    if (!box) {
      closeAll(null);
      return;
    }
    toggle(box);
  });

  // Thiết bị có chuột: rời chuột khỏi hộp thì đóng luôn trạng thái mở do bấm.
  var canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
  document.addEventListener('mouseout', function (e) {
    if (!canHover) return;
    var box = closest(e.target, SEL);
    if (box && box.classList.contains('is-open') && !box.contains(e.relatedTarget)) {
      setOpen(box, false);
    }
  });

  document.addEventListener('keydown', function (e) {
    var box = closest(e.target, SEL);
    if (e.key === 'Escape' || e.key === 'Esc') {
      if (box) {
        setOpen(box, false);
        box.focus();
      }
      closeAll(null);
      return;
    }
    if (!box || box !== e.target) return;
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
      if (e.key !== 'Enter') e.preventDefault();
      toggle(box);
    }
  });
})();
