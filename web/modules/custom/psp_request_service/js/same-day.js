/**
 * @file
 * Same-day follow-up question: show it only while the picked date is today.
 *
 * Any choice element whose wrapper has psp-same-day-question (Book Online's
 * "Do you need emergency service?") follows the form's first date input.
 * Hidden again when the date moves off today, with its answer cleared. The
 * server enforces the same rule (psp_request_service_same_day_validate()).
 */
((Drupal, once) => {
  'use strict';

  const pad = (n) => String(n).padStart(2, '0');

  Drupal.behaviors.pspSameDayQuestion = {
    attach(context) {
      once('psp-same-day', '.psp-same-day-question', context).forEach((question) => {
        const form = question.closest('form');
        const date = form && form.querySelector('input[type="date"]');
        if (!date) {
          return;
        }
        const sync = () => {
          const now = new Date();
          const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
          question.hidden = date.value !== today;
          if (question.hidden) {
            question.querySelectorAll('input:checked').forEach((input) => {
              input.checked = false;
            });
          }
        };
        date.addEventListener('change', sync);
        date.addEventListener('input', sync);
        sync();
      });
    },
  };
})(Drupal, once);
