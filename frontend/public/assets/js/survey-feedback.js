(() => {
  'use strict';

  const DAY = 24 * 60 * 60 * 1000;
  const safeStore = {
    get(key) {
      try { return Number(window.localStorage.getItem(key) || 0); } catch (_) { return 0; }
    },
    set(key, value) {
      try { window.localStorage.setItem(key, String(value)); } catch (_) { /* Storage is optional. */ }
    },
  };

  const closeDialog = dialog => {
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else {
      dialog.removeAttribute('open');
      dialog.hidden = true;
    }
  };

  const openDialog = dialog => {
    dialog.hidden = false;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  };

  const init = invitation => {
    if (invitation.dataset.surveyReady === '1') return;
    invitation.dataset.surveyReady = '1';
    const dialog = invitation.querySelector('[data-survey-dialog]');
    const form = invitation.querySelector('[data-survey-form]');
    const openButton = invitation.querySelector('[data-survey-open]');
    const submitButton = invitation.querySelector('[data-survey-submit]');
    const errorBox = invitation.querySelector('[data-survey-error]');
    const success = invitation.querySelector('[data-survey-success]');
    const questions = [...invitation.querySelectorAll('[data-survey-question]')];
    const progressText = invitation.querySelector('[data-survey-progress-text]');
    const progressBar = invitation.querySelector('[data-survey-progress-bar]');
    const storageKey = `skinsyntax:survey:${invitation.dataset.surveyKey}`;
    let openedAutomatically = false;

    if (!dialog || !form || !openButton || !submitButton || !errorBox || !success) return;

    const updateProgress = () => {
      const answered = questions.filter(question => question.querySelector('input:checked')).length;
      progressText.textContent = `${answered}/${questions.length} câu`;
      progressBar.style.width = `${questions.length ? (answered / questions.length) * 100 : 0}%`;
      if (answered === questions.length && errorBox.dataset.validationError === '1') errorBox.hidden = true;
    };

    const open = automatic => {
      if (dialog.open) return;
      openedAutomatically = automatic;
      if (automatic) safeStore.set(storageKey, Date.now());
      openDialog(dialog);
      window.setTimeout(() => {
        const focusTarget = form.hidden ? success.querySelector('button') : dialog.querySelector('[data-survey-close]');
        if (focusTarget) focusTarget.focus({ preventScroll: true });
      }, 50);
    };

    openButton.addEventListener('click', () => open(false));
    invitation.querySelectorAll('[data-survey-close]').forEach(button => {
      button.addEventListener('click', () => closeDialog(dialog));
    });
    invitation.querySelector('[data-survey-skip]')?.addEventListener('click', () => {
      safeStore.set(storageKey, Date.now());
      closeDialog(dialog);
      openButton.focus();
    });
    dialog.addEventListener('cancel', () => {
      if (openedAutomatically) safeStore.set(storageKey, Date.now());
    });
    dialog.addEventListener('close', () => openButton.focus({ preventScroll: true }));
    form.addEventListener('change', updateProgress);

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitButton.disabled) return;
      errorBox.hidden = true;
      const firstMissing = questions.find(question => !question.querySelector('input:checked'));
      if (firstMissing) {
        errorBox.dataset.validationError = '1';
        errorBox.textContent = 'Vui lòng chọn một mức cho tất cả câu hỏi trước khi gửi.';
        errorBox.hidden = false;
        firstMissing.querySelector('input')?.focus();
        return;
      }
      delete errorBox.dataset.validationError;
      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
      submitButton.querySelector('span').textContent = 'Đang gửi…';
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Chưa gửi được đánh giá. Hãy thử lại.');
        safeStore.set(storageKey, Date.now());
        form.hidden = true;
        success.hidden = false;
        success.querySelector('button')?.focus();
      } catch (error) {
        errorBox.textContent = error instanceof SyntaxError
          ? 'Phiên đăng nhập đã thay đổi. Hãy tải lại trang rồi thử lại.'
          : error.message;
        errorBox.hidden = false;
      } finally {
        submitButton.disabled = false;
        submitButton.classList.remove('is-loading');
        submitButton.querySelector('span').textContent = 'Gửi đánh giá';
      }
    });

    updateProgress();
    const frequencyDays = Number(invitation.dataset.surveyFrequencyDays || 14);
    if (invitation.dataset.surveyAuto === '1' && 'IntersectionObserver' in window) {
      const observer = new IntersectionObserver(entries => {
        if (!entries.some(entry => entry.isIntersecting)) return;
        observer.disconnect();
        const lastSeen = safeStore.get(storageKey);
        if (!lastSeen || Date.now() - lastSeen >= frequencyDays * DAY) open(true);
      }, { threshold: .6 });
      observer.observe(openButton);
    }
  };

  const boot = () => document.querySelectorAll('[data-survey-invitation]').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
