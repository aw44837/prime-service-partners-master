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
                error.hidden = false;
              }
            })
            .catch(function () { error.hidden = false; });
        });
      });
    }
  };
})(Drupal);
