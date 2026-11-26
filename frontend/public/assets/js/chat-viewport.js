(function () {
  const viewport = window.visualViewport;
  if (!viewport) return;

  const root = document.documentElement;
  function updateChatViewport() {
    // Pinch zoom should retain the browser's usual panning behavior.
    if (viewport.scale !== 1) return;
    const offset = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
    root.style.setProperty('--chat-viewport-height', viewport.height + 'px');
    root.style.setProperty('--chat-keyboard-offset', offset + 'px');
  }

  viewport.addEventListener('resize', updateChatViewport);
  viewport.addEventListener('scroll', updateChatViewport);
  updateChatViewport();
})();
