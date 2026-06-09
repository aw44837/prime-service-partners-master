/**
 * @file
 * Helper to
 *  Remove stickiness if the footer height is larger than the viewport
 *  Remove stickiness if the main content is taller than the viewport.
 *  Handle scrolling to bottom when tabbing to footer controls if footer is sticky.
 */

((Drupal, once) => {
  function init(footer) {
    const footerHeight = footer.offsetHeight;
    const viewportHeight = window.innerHeight;
    const siteMain = document.querySelector('.site-main');

    // Check if footer is too tall for viewport
    if (footerHeight > (viewportHeight * 0.9)) {
      footer.classList.add('site-footer--no-sticky')
    }

    // Check if main content is taller than viewport
    if (siteMain && siteMain.offsetHeight < viewportHeight) {
      footer.classList.add('site-footer--no-sticky')
    }

    // Add focus event listeners to all focusable elements within the footer
    const focusableElements = footer.querySelectorAll(Drupal.dripyard.focusableElementsSelector);

    focusableElements.forEach(element => {
      element.addEventListener('focus', () => {
        // Check if footer is sticky by checking if it doesn't have the no-sticky class
        const isFooterSticky = !footer.classList.contains('site-footer--no-sticky');

        if (isFooterSticky && element.matches(':focus-visible')) {
          // Scroll to bottom of the page
          window.scrollTo({
            top: document.body.scrollHeight
          });
        }
      });
    });
  }

  Drupal.behaviors.meridianFooter = {
    attach(context) {
      once('meridianFooter', '.site-footer', context).forEach(init);

      // Menu might pop in late with BigPipe, so we re-run behavior if it does.
      once('meridianFooterMenu', '.site-footer:has(.footer-menu__item, .menu)', context).forEach(init);
    },
  };
})(Drupal, once);
