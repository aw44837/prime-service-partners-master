/**
 * @file
 * Request description placeholder that follows the chosen service.
 *
 * Example text only (never submitted): "e.g. AC not cooling…" when Air is
 * chosen, plumbing examples for Plumbing, and so on; a general prompt when
 * no service is chosen. Settings come from psp_request_service_webform_
 * submission_form_alter().
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  Drupal.behaviors.pspServicePlaceholder = {
    attach(context) {
      const settings = drupalSettings.pspServicePlaceholders;
      if (!settings) {
        return;
      }
      once('psp-service-placeholder', 'form.psp-request-service-form textarea[name="comments"]', context).forEach((textarea) => {
        const form = textarea.form;
        const sync = () => {
          const chosen = form.querySelector(`input[name="${settings.name}"]:checked`);
          const trade = chosen ? settings.map[chosen.value] : null;
          textarea.placeholder = (trade && settings.text[trade]) || settings.default;
        };
        form.addEventListener('change', (e) => {
          if (e.target.name === settings.name) {
            sync();
          }
        });
        sync();
      });
    },
  };
})(Drupal, drupalSettings, once);
