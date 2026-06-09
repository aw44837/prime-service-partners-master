((Drupal) => {
  const siteHeader = document.querySelector('.site-header');
  const mobileNavigationButton = document.querySelector('[data-drupal-selector="mobile-nav-button"]');
  Drupal.dripyard = Drupal.dripyard || {};

  /**
   * A selector that will return all focusable elements.
   */
  Drupal.dripyard.focusableElementsSelector = ':is(audio, button, canvas, details, iframe, input, select, summary, textarea, video, [accesskey], [contenteditable], [href], [tabindex]:not([tabindex*="-"])):not(:is([disabled], [inert]))';

  /**
   * Determines if the mobile navigation is open by checking if its
   * disclosure button is visible.
   *
   * @returns {boolean}
   */
  function isDesktopNav() {
    return mobileNavigationButton.clientHeight === 0;
  }
  Drupal.dripyard.isDesktopNav = isDesktopNav;

  /**
   * Enables/disables a focus trap on the mobile menu wrapper.
   *
   * @param {boolean} focusTrapToBeEnabled - True if the focus trap should be enabled,
   * otherwise false.
   */
  Drupal.dripyard.toggleFocusTrap = focusTrapToBeEnabled => {
    if (!Drupal.dripyard.isDesktopNav() && focusTrapToBeEnabled === true) {
      document.querySelectorAll(Drupal.dripyard.focusableElementsSelector).forEach(focusableElement => {
        if (!siteHeader.contains(focusableElement)) {
          focusableElement.inert = true;
          focusableElement.dataset.mobileMenuInert = true;
        }
      });
    }
    else {
      document.querySelectorAll('[data-mobile-menu-inert]').forEach(el => {
        el.inert = false;
        delete el.dataset.mobileMenuInert;
      });
    }
  }

})(Drupal);
