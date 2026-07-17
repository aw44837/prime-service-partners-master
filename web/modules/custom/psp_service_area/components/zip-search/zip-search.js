/**
 * @file
 * ZIP search component: look up the service area, remember it, redirect.
 * Shares the psp_area cookie and /psp-area/zip endpoint with the
 * location bar.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.pspZipSearch = {
    attach: function (context) {
      const forms = (context.querySelectorAll ? context.querySelectorAll('.js-psp-zip-search-form') : []);
      forms.forEach(function (form) {
        if (form.dataset.pspProcessed) { return; }
        form.dataset.pspProcessed = '1';
        const input = form.querySelector('.js-psp-zip-search-input');
        const error = form.querySelector('.js-psp-zip-search-error');
        const errorText = form.querySelector('.js-psp-zip-search-error-text');
        const defaultError = errorText.textContent;
        const geo = form.querySelector('.js-psp-zip-search-geo');

        form.querySelector('.js-psp-zip-search-error-close').addEventListener('click', function () {
          error.hidden = true;
        });

        // Short placeholder on narrow screens so it never clips.
        const fullPlaceholder = input.placeholder;
        const narrow = window.matchMedia('(max-width: 480px)');
        const setPlaceholder = function () {
          input.placeholder = narrow.matches ? Drupal.t('ZIP code') : fullPlaceholder;
        };
        setPlaceholder();
        narrow.addEventListener('change', setPlaceholder);

        // "Use my current location": browser geolocation -> keyless
        // reverse geocode (BigDataCloud) -> fill + auto-search. The
        // third-party call happens only after the visitor clicks and
        // grants the browser permission.
        if (geo && navigator.geolocation) {
          geo.hidden = false;
          const geoLabel = geo.querySelector('.js-psp-zip-search-geo-label');
          const originalLabel = geoLabel.textContent;
          const geoFail = function () {
            geo.disabled = false;
            geoLabel.textContent = originalLabel;
            errorText.textContent = Drupal.t("We couldn't detect your location — please enter your ZIP code instead.");
            error.hidden = false;
          };
          geo.addEventListener('click', function () {
            error.hidden = true;
            geo.disabled = true;
            geoLabel.textContent = Drupal.t('Locating…');
            navigator.geolocation.getCurrentPosition(function (position) {
              const url = 'https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=' +
                position.coords.latitude + '&longitude=' + position.coords.longitude + '&localityLanguage=en';
              fetch(url)
                .then(function (response) { return response.json(); })
                .then(function (data) {
                  const zip = (data.postcode || '').slice(0, 5);
                  if (/^\d{5}$/.test(zip)) {
                    geo.disabled = false;
                    geoLabel.textContent = originalLabel;
                    input.value = zip;
                    form.dispatchEvent(new Event('submit', {cancelable: true}));
                  }
                  else { geoFail(); }
                })
                .catch(geoFail);
            }, geoFail, {timeout: 10000, maximumAge: 300000});
          });
        }

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
                const value = encodeURIComponent(data.area + '|' + data.label);
                document.cookie = 'psp_area=' + value + '; path=/; max-age=31536000; samesite=lax';
                window.location.href = data.url;
              }
              else {
                errorText.textContent = defaultError;
                error.hidden = false;
              }
            })
            .catch(function () { errorText.textContent = defaultError; error.hidden = false; });
        });
      });
    }
  };
})(Drupal);
