/**
 * @file
 * Location bar: remembered-location display, ZIP lookup, chooser toggle.
 *
 * Cookie psp_area stores "prefix|label". Server renders the current area
 * on area pages; on global pages this script swaps the neutral label for
 * the remembered location so cached pages stay generic.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  const COOKIE = 'psp_area';

  function readCookie() {
    const match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
    if (!match) { return null; }
    const [prefix, label] = decodeURIComponent(match[1]).split('|');
    return prefix && label ? { prefix: prefix, label: label } : null;
  }

  function writeCookie(prefix, label) {
    const value = encodeURIComponent(prefix + '|' + label);
    document.cookie = COOKIE + '=' + value + '; path=/; max-age=31536000; samesite=lax';
  }

  Drupal.behaviors.pspLocationBar = {
    attach: function (context) {
      const bar = context.querySelector ? context.querySelector('.psp-location-bar') : null;
      if (!bar || bar.dataset.pspProcessed) { return; }
      bar.dataset.pspProcessed = '1';

      const settings = (drupalSettings.pspServiceArea || {});
      const label = bar.querySelector('.js-psp-location-label');
      const toggle = bar.querySelector('.js-psp-location-toggle');
      const chooser = bar.querySelector('.psp-location-chooser');
      const form = bar.querySelector('.js-psp-zip-form');
      const input = bar.querySelector('.js-psp-zip-input');
      const error = bar.querySelector('.js-psp-zip-error');

      // Visiting an area section remembers it.
      if (settings.currentArea && settings.currentLabel) {
        writeCookie(settings.currentArea, settings.currentLabel);
      }
      else {
        // Global page: show the remembered location if any.
        const remembered = readCookie();
        if (remembered) {
          label.textContent = remembered.label;
          toggle.textContent = '(' + Drupal.t('change') + ')';
          bar.classList.add('psp-location-bar--remembered');
        }
      }

      toggle.addEventListener('click', function () {
        const open = chooser.hidden;
        chooser.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && input) { input.focus(); }
      });

      // Choosing from the list remembers the choice (link then navigates).
      bar.querySelectorAll('.js-psp-location-link').forEach(function (link) {
        link.addEventListener('click', function () {
          writeCookie(link.dataset.areaPrefix, link.textContent.trim());
        });
      });

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        error.hidden = true;
        const zip = input.value.trim();
        if (!/^\d{5}$/.test(zip)) {
          input.focus();
          return;
        }
        fetch(Drupal.url('psp-area/zip/' + zip))
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.found) {
              writeCookie(data.area, data.label);
              window.location.href = data.url;
            }
            else {
              error.hidden = false;
            }
          })
          .catch(function () {
            error.hidden = false;
          });
      });
    }
  };
})(Drupal, drupalSettings);
