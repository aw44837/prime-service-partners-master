/**
 * @file
 * Slide-up panel for external links.
 *
 * Add class `js-open-in-panel` to any <a> element to open it in the panel
 * instead of navigating away. Works with mobile swipe-to-dismiss.
 */
((Drupal, once) => {

  let panel, backdrop, iframe, closeBtn;
  let startY = 0;
  let currentY = 0;
  let isDragging = false;

  /**
   * Build the panel DOM once and append to body.
   */
  function buildPanel() {
    backdrop = document.createElement('div');
    backdrop.className = 'external-panel-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');

    panel = document.createElement('div');
    panel.className = 'external-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'External link');

    panel.innerHTML = `
      <div class="external-panel__handle" aria-hidden="true"></div>
      <div class="external-panel__header">
        <span class="external-panel__url"></span>
        <button class="external-panel__close" aria-label="Close panel">✕</button>
      </div>
      <div class="external-panel__loading">Loading…</div>
      <iframe class="external-panel__iframe" src="" title="External content" sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
    `;

    closeBtn = panel.querySelector('.external-panel__close');
    iframe = panel.querySelector('.external-panel__iframe');

    document.body.append(backdrop, panel);

    // Close on backdrop click
    backdrop.addEventListener('click', closePanel);

    // Close button
    closeBtn.addEventListener('click', closePanel);

    // Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && panel.classList.contains('is-open')) {
        closePanel();
      }
    });

    // Swipe down to dismiss
    const handle = panel.querySelector('.external-panel__handle');
    handle.addEventListener('touchstart', onDragStart, { passive: true });
    handle.addEventListener('touchmove', onDragMove, { passive: false });
    handle.addEventListener('touchend', onDragEnd);

    // iframe loaded
    iframe.addEventListener('load', () => {
      panel.classList.add('is-loaded');
    });
  }

  /**
   * Open the panel with a given URL.
   */
  function openPanel(url) {
    if (!panel) buildPanel();

    const urlDisplay = panel.querySelector('.external-panel__url');
    urlDisplay.textContent = url;

    panel.classList.remove('is-loaded');
    iframe.src = url;

    requestAnimationFrame(() => {
      backdrop.classList.add('is-visible');
      panel.classList.add('is-open');
    });

    // Trap focus on close button
    closeBtn.focus();

    // Prevent body scroll
    document.body.style.overflow = 'hidden';
  }

  /**
   * Close the panel.
   */
  function closePanel() {
    backdrop.classList.remove('is-visible');
    panel.classList.remove('is-open');
    panel.style.transform = '';

    document.body.style.overflow = '';

    // Clear iframe after animation
    setTimeout(() => {
      iframe.src = '';
    }, 350);
  }

  /**
   * Touch drag handlers for swipe-to-dismiss.
   */
  function onDragStart(e) {
    isDragging = true;
    startY = e.touches[0].clientY;
    currentY = 0;
    panel.style.transition = 'none';
  }

  function onDragMove(e) {
    if (!isDragging) return;
    const delta = e.touches[0].clientY - startY;
    if (delta < 0) return; // Don't allow dragging up
    currentY = delta;
    panel.style.transform = `translateY(${delta}px)`;
    e.preventDefault();
  }

  function onDragEnd() {
    if (!isDragging) return;
    isDragging = false;
    panel.style.transition = '';

    if (currentY > 120) {
      closePanel();
    } else {
      // Snap back
      panel.style.transform = '';
    }
  }

  /**
   * Drupal behavior — attach click handlers to trigger links.
   */
  Drupal.behaviors.externalPanel = {
    attach(context) {
      once('external-panel', '.js-open-in-panel', context).forEach((link) => {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          openPanel(link.href);
        });
      });
    },
  };

})(Drupal, once);
