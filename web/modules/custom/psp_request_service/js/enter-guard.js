/**
 * @file
 * Stop the Enter key from submitting webforms from a text-type field.
 *
 * The estate's forms reveal sections progressively (states) and validate
 * inline; an implicit Enter submit posted the half-filled form and came back
 * with the Inline Form Errors summary. Enter now confirms the field (fires
 * change so states/validation run) instead of submitting. Enter on the submit
 * button, on textareas and on select/checkbox/radio elements is untouched.
 *
 * Same behavior name and once() key as the theme copy on sites that also
 * ship it (Paul Stein), so it only ever runs once per form.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.pspWebformEnterGuard = {
    attach: function (context) {
      once('psp-enter-guard', 'form.webform-submission-form', context).forEach(function (form) {
        form.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter' || e.isComposing) { return; }
          var t = e.target;
          if (!(t instanceof HTMLInputElement)) { return; }
          var type = (t.type || 'text').toLowerCase();
          if (['submit', 'button', 'reset', 'image', 'checkbox', 'radio', 'file'].indexOf(type) !== -1) { return; }
          e.preventDefault();
          t.dispatchEvent(new Event('change', { bubbles: true }));
          t.blur();
        });
      });
    }
  };
})(Drupal, once);
