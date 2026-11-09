(function () {
  'use strict';

  function parseSpeech(root) {
    try {
      var parsed = JSON.parse(root.getAttribute('data-syna-speech') || '[]');
      return Array.isArray(parsed) ? parsed.filter(function (item) {
        return typeof item === 'string' && item.trim() !== '';
      }) : [];
    } catch (error) {
      return [];
    }
  }

  function initSynaMascot() {
    var root = document.querySelector('[data-syna-hero]');
    if (!root || root.getAttribute('data-syna-initialized') === '1') return;

    var stage = root.querySelector('[data-syna-mascot]');
    var textElem = root.querySelector('#synaTypewriterText');
    var mascotImg = root.querySelector('#synaMascotImg');
    var mascotButton = root.querySelector('#synaMascotButton');
    if (!stage || !textElem || !mascotImg || !mascotButton) return;

    root.setAttribute('data-syna-initialized', '1');

    var baseUrl = (root.getAttribute('data-syna-base-url') || '').replace(/\/+$/, '') + '/assets/images/';
    var fallbackSrc = baseUrl + 'syna_mascot.png';
    var states = {
      idle: baseUrl + 'syna_talking_2.png',
      blink: baseUrl + 'syna_listening.png',
      greeting: baseUrl + 'syna_greeting.png',
      talking1: baseUrl + 'syna_talking_1.png',
      talking2: baseUrl + 'syna_talking_2.png',
      thinking: baseUrl + 'syna_thinking.png',
      listening: baseUrl + 'syna_listening.png',
      recommend: baseUrl + 'syna_recommend.png',
      processing: baseUrl + 'syna_processing.png',
      active: baseUrl + 'syna_active.png',
      happy: baseUrl + 'syna_happy.png',
      bye: baseUrl + 'syna_bye.png'
    };
    var sentences = parseSpeech(root);
    var reducedMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var reducedMotion = !!(reducedMotionQuery && reducedMotionQuery.matches);
    var finePointer = !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches);

    function prefersReducedMotion() {
      return !!(reducedMotionQuery && reducedMotionQuery.matches);
    }

    var currentState = 'idle';
    var currentRestState = 'idle';
    var sentenceIndex = 0;
    var characterIndex = 0;
    var typeTimer = null;
    var talkingTimer = null;
    var blinkTimer = null;
    var eventTimer = null;
    var transitionTimer = null;
    var talkingToggle = false;
    var eventLocked = false;
    var lastCartEventAt = 0;
    var rafId = null;
    var cartObserver = null;
    var cartMarkerTimer = null;
    var chatVisibilityObserver = null;
    var chatClassObserver = null;
    var chatVisibilityRaf = null;
    var chatWidget = document.querySelector('[data-ai-chat-widget]');
    var lastCartMarker = root.getAttribute('data-syna-cart-added') || '';
    var destroyed = false;
    var imageSwitchToken = 0;

    Object.keys(states).forEach(function (key) {
      var preload = new Image();
      preload.src = states[key];
    });

    mascotImg.onerror = function () {
      if (this.getAttribute('data-fallback-applied') === '1') return;
      this.setAttribute('data-fallback-applied', '1');
      this.classList.remove('is-switching');
      this.src = fallbackSrc;
    };

    function clearTimer(timer) {
      if (timer) window.clearTimeout(timer);
    }

    function stopTalking() {
      if (talkingTimer) {
        window.clearInterval(talkingTimer);
        talkingTimer = null;
      }
    }

    function setMascotImage(src, smooth) {
      if (!src || mascotImg.getAttribute('src') === src) return;

      imageSwitchToken += 1;
      var token = imageSwitchToken;
      clearTimer(transitionTimer);
      transitionTimer = null;

      if (prefersReducedMotion() || !smooth) {
        mascotImg.classList.remove('is-switching');
        mascotImg.src = src;
        return;
      }

      mascotImg.classList.add('is-switching');
      var reveal = function () {
        if (token !== imageSwitchToken) return;
        clearTimer(transitionTimer);
        transitionTimer = window.setTimeout(function () {
          transitionTimer = null;
          if (token === imageSwitchToken) mascotImg.classList.remove('is-switching');
        }, 90);
      };

      mascotImg.addEventListener('load', reveal, { once: true });
      mascotImg.src = src;
      if (mascotImg.complete) reveal();
      transitionTimer = window.setTimeout(reveal, 260);
    }

    function setState(stateName) {
      if (!states[stateName] && stateName !== 'talking') return;
      currentState = stateName;
      if (stateName !== 'talking') stopTalking();
      mascotImg.className = 'syna-mascot-img state-' + (stateName === 'talking' ? 'talking' : stateName);

      if (stateName === 'talking') {
        if (!talkingTimer) {
          talkingToggle = false;
          setMascotImage(states.talking1, true);
          talkingTimer = window.setInterval(function () {
            if (destroyed || eventLocked) return;
            talkingToggle = !talkingToggle;
            setMascotImage(talkingToggle ? states.talking2 : states.talking1, false);
          }, 420);
        }
      } else {
        setMascotImage(states[stateName], true);
      }
    }

    window.setSynaState = setState;

    function scheduleType(delay) {
      clearTimer(typeTimer);
      typeTimer = window.setTimeout(typeSentence, delay);
    }

    function typeSentence() {
      if (destroyed || eventLocked || sentences.length === 0) return;

      if (sentenceIndex >= sentences.length) {
        sentenceIndex = 0;
        currentRestState = 'idle';
        setState('idle');
        scheduleType(6200);
        return;
      }

      var sentence = sentences[sentenceIndex];
      var restState = ['greeting', 'thinking', 'listening', 'recommend'][sentenceIndex] || 'idle';

      if (characterIndex === 0) {
        currentRestState = restState;
        setState(restState);
        clearTimer(talkingTimer);
        talkingTimer = window.setTimeout(function () {
          talkingTimer = null;
          if (!destroyed && !eventLocked) setState('talking');
        }, 140);
      }

      if (characterIndex < sentence.length) {
        textElem.textContent = sentence.substring(0, characterIndex + 1);
        characterIndex += 1;
        scheduleType(42);
        return;
      }

      characterIndex = 0;
      sentenceIndex += 1;
      setState(restState);
      scheduleType(3300);
    }

    function resetAfterEvent() {
      eventLocked = false;
      sentenceIndex = sentences.length ? (sentenceIndex + 1) % sentences.length : 0;
      characterIndex = 0;
      if (sentences.length && !prefersReducedMotion()) {
        typeSentence();
      } else {
        textElem.textContent = sentences[0] || 'Chào bạn! Mình là SYNA, cùng khám phá làn da nhé!';
        currentRestState = 'idle';
        setState('idle');
      }
    }

    function showEventMessage(kind, stateName, duration) {
      var messages = {
        click: 'Mình đây! Bạn muốn hỏi gì về skincare?',
        chat: 'SYNA có thể giúp bạn tìm hiểu sản phẩm nha!',
        survey: 'Yay! Mình đã có kết quả tư vấn cho bạn rồi!',
        cart: 'Lựa chọn đã vào giỏ hàng rồi nè!'
      };

      if (!messages[kind]) return;
      eventLocked = true;
      clearTimer(typeTimer);
      clearTimer(eventTimer);
      clearTimer(talkingTimer);
      clearTimer(transitionTimer);
      stopTalking();
      textElem.textContent = messages[kind];
      setState(stateName);
      eventTimer = window.setTimeout(resetAfterEvent, duration || 3600);
    }

    function notifyCartAdded() {
      var now = Date.now();
      if (now - lastCartEventAt < 250) return;
      lastCartEventAt = now;
      showEventMessage('cart', 'happy', 4200);
    }

    function scheduleBlink() {
      if (destroyed || prefersReducedMotion()) return;
      clearTimer(blinkTimer);
      blinkTimer = window.setTimeout(function () {
        if (!destroyed && !eventLocked && currentState === 'idle') {
          setState('blink');
          window.setTimeout(function () {
            if (!destroyed && !eventLocked && currentState === 'blink') setState('idle');
          }, 280);
        }
        scheduleBlink();
      }, 6500 + Math.floor(Math.random() * 4200));
    }

    function resetLook() {
      stage.style.setProperty('--syna-look-x', '0px');
      stage.style.setProperty('--syna-look-y', '0px');
    }

    function updateLook() {
      rafId = null;
      if (destroyed || !finePointer || prefersReducedMotion()) return;

      var rect = stage.getBoundingClientRect();
      var centerX = rect.left + rect.width * 0.5;
      var centerY = rect.top + rect.height * 0.62;
      var rangeX = Math.max(rect.width * 0.5, 1);
      var rangeY = Math.max(rect.height * 0.5, 1);
      var x = Math.max(-1, Math.min(1, (stage._synaPointerX - centerX) / rangeX));
      var y = Math.max(-1, Math.min(1, (stage._synaPointerY - centerY) / rangeY));
      var deadZone = 0.12;

      if (Math.abs(x) < deadZone) x = 0;
      if (Math.abs(y) < deadZone) y = 0;

      stage.style.setProperty('--syna-look-x', (x * 5).toFixed(2) + 'px');
      stage.style.setProperty('--syna-look-y', (y * 3).toFixed(2) + 'px');
    }

    function onPointerMove(event) {
      if (destroyed || !finePointer || prefersReducedMotion() || event.pointerType === 'touch') return;
      stage._synaPointerX = event.clientX;
      stage._synaPointerY = event.clientY;
      if (rafId === null) rafId = window.requestAnimationFrame(updateLook);
    }

    function openChat() {
      if (typeof window.openSynaChat === 'function') {
        window.openSynaChat();
        return;
      }

      var launcher = document.querySelector('[data-ai-chat-toggle], [data-ai-chat-open]');
      if (launcher) {
        launcher.click();
        return;
      }

      var fallback = root.getAttribute('data-syna-chat-fallback');
      if (fallback) window.location.href = fallback;
    }

    function updateChatVisibility() {
      if (!chatWidget) return;
      var mobile = !!(window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches);
      var heroRect = root.getBoundingClientRect();
      var heroVisible = heroRect.bottom > 0 && heroRect.top < window.innerHeight;
      var hideTrigger = mobile && heroVisible && !chatWidget.classList.contains('is-open');
      chatWidget.classList.toggle('is-syna-hero-visible', hideTrigger);
    }

    function scheduleChatVisibility() {
      if (chatVisibilityRaf !== null) return;
      if (!window.requestAnimationFrame) {
        updateChatVisibility();
        return;
      }
      chatVisibilityRaf = window.requestAnimationFrame(function () {
        chatVisibilityRaf = null;
        updateChatVisibility();
      });
    }

    if (chatWidget) {
      updateChatVisibility();
      window.addEventListener('scroll', scheduleChatVisibility, { passive: true });
      window.addEventListener('resize', scheduleChatVisibility, { passive: true });
      if (window.IntersectionObserver) {
        chatVisibilityObserver = new IntersectionObserver(scheduleChatVisibility, { threshold: [0, 0.01, 1] });
        chatVisibilityObserver.observe(root);
      }
      if (window.MutationObserver) {
        chatClassObserver = new MutationObserver(scheduleChatVisibility);
        chatClassObserver.observe(chatWidget, { attributes: true, attributeFilter: ['class'] });
      }
    }

    stage.addEventListener('pointermove', onPointerMove, { passive: true });
    stage.addEventListener('pointerleave', resetLook, { passive: true });
    mascotButton.addEventListener('click', function () {
      showEventMessage('click', 'active', 3000);
    });

    root.querySelectorAll('[data-syna-open-chat]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        openChat();
      });
    });

    document.addEventListener('skinsyntax:syna-chat-opened', function () {
      scheduleChatVisibility();
      showEventMessage('chat', 'greeting', 4200);
    });

    document.addEventListener('skinsyntax:cart-added', function () {
      notifyCartAdded();
    });
    window.notifySynaCartAdded = notifyCartAdded;
    if (window.MutationObserver) {
      var consumeCartMarker = function () {
        var marker = root.getAttribute('data-syna-cart-added') || '';
        if (!marker || marker === lastCartMarker) return;
        lastCartMarker = marker;
        notifyCartAdded();
      };
      cartObserver = new MutationObserver(function (records) {
        records.forEach(function (record) {
          if (record.attributeName === 'data-syna-cart-added') consumeCartMarker();
        });
      });
      cartObserver.observe(root, { attributes: true, attributeFilter: ['data-syna-cart-added'] });
      cartMarkerTimer = window.setInterval(consumeCartMarker, 300);
    }

    if (root.getAttribute('data-syna-survey-completed') === '1') {
      window.setTimeout(function () {
        showEventMessage('survey', 'happy', 4800);
      }, 260);
    }

    if (sentences.length) {
      if (prefersReducedMotion()) {
        textElem.textContent = sentences[0];
        setState('idle');
      } else {
        typeSentence();
        scheduleBlink();
      }
    } else {
      textElem.textContent = 'Chào bạn! Mình là SYNA, cùng khám phá làn da nhé!';
      setState('idle');
    }

    window.addEventListener('beforeunload', function () {
      destroyed = true;
      clearTimer(typeTimer);
      clearTimer(eventTimer);
      clearTimer(blinkTimer);
      clearTimer(talkingTimer);
      clearTimer(transitionTimer);
      stopTalking();
      if (rafId !== null && window.cancelAnimationFrame) window.cancelAnimationFrame(rafId);
      if (chatVisibilityRaf !== null && window.cancelAnimationFrame) window.cancelAnimationFrame(chatVisibilityRaf);
      if (cartObserver) cartObserver.disconnect();
      if (chatVisibilityObserver) chatVisibilityObserver.disconnect();
      if (chatClassObserver) chatClassObserver.disconnect();
      if (cartMarkerTimer) window.clearInterval(cartMarkerTimer);
      window.removeEventListener('scroll', scheduleChatVisibility);
      window.removeEventListener('resize', scheduleChatVisibility);
      stage.removeEventListener('pointermove', onPointerMove);
      stage.removeEventListener('pointerleave', resetLook);
    }, { once: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSynaMascot, { once: true });
  } else {
    initSynaMascot();
  }
})();
