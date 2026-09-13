/**
 * @file
 * Keeps NeonByte's --offset-from-header accurate on resize.
 *
 * The mega-menu dropdown is anchored to its menu item but stretches to the
 * right edge of the header, so its width is
 *   calc(100% + var(--offset-from-header) - var(--header-padding-inline))
 * where --offset-from-header is the gap between the nav's right edge and the
 * header's right edge. NeonByte's own script sets that variable once during
 * init and never again — its resize handler only dismisses overlays. After a
 * window resize the value is stale, so the dropdown (and the menu cards inside
 * it) can extend past the header, where `overflow: clip` on the header
 * container crops them.
 *
 * This recomputes the variable with NeonByte's formula whenever the viewport
 * changes. Attached only when the NeonByte header style is active.
 */
(function () {
  'use strict';

  var REGION = '[data-drupal-selector="header-navigation-wrapper"]';
  var frame = null;

  function updateOffset() {
    frame = null;

    var region = document.querySelector(REGION);
    if (!region) {
      return;
    }
    var header = region.closest('.site-header__container');
    var nav = header && header.querySelector('.primary-menu__list--level-1');
    if (!header || !nav) {
      return;
    }

    var headerRect = header.getClientRects()[0];
    var navRect = nav.getClientRects()[0];
    // Hidden (mobile flyout) — nothing meaningful to measure.
    if (!headerRect || !navRect) {
      return;
    }

    var offset = document.documentElement.matches(':dir(rtl)')
      ? navRect.left - headerRect.left
      : headerRect.right - navRect.right;

    // setProperty rather than setAttribute('style', …) so we don't clobber
    // anything else NeonByte may have put on the element.
    region.style.setProperty('--offset-from-header', Math.max(0, Math.round(offset)) + 'px');
  }

  function scheduleUpdate() {
    if (frame === null) {
      frame = window.requestAnimationFrame(updateOffset);
    }
  }

  // The mobile flyout is a white takeover on this site (see
  // neonbyte-header-support.css); NeonByte's header.js toggles `theme--black`
  // on it whenever the burger is visible, so swap that for `theme--white`.
  var region = document.querySelector(REGION);
  if (region) {
    var swapTheme = function () {
      if (region.classList.contains('theme--black')) {
        region.classList.remove('theme--black');
        region.classList.add('theme--white');
      }
    };
    new MutationObserver(swapTheme).observe(region, { attributes: true, attributeFilter: ['class'] });
    swapTheme();
  }

  window.addEventListener('resize', scheduleUpdate, { passive: true });
  window.addEventListener('orientationchange', scheduleUpdate, { passive: true });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleUpdate);
  }
  else {
    scheduleUpdate();
  }
})();
