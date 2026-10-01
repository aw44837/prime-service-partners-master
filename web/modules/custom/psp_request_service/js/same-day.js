/**
 * @file
 * Same-day follow-up question: show it only while the picked date is today.
 *
 * Any choice element whose wrapper has psp-same-day-question (Book Online's
 * "Do you need emergency service?") follows the form's first date input.
 * Hidden again when the date moves off today, with its answer cleared. The
 * server enforces the same rule (psp_request_service_same_day_validate()).
 *
 * While the question is showing and unanswered, the sections after it (zip
 * and everything it reveals) wait: they appear once any answer is picked.
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
        // Everything after the question's own section: the zip section and
        // the sections it reveals.
        const own = question.closest('.webform-section') || question;
        const later = [...form.querySelectorAll('.webform-section')].filter((section) => !own.contains(section) && !section.contains(own) && (own.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING));
        let waiting = false;
        const sync = () => {
          const now = new Date();
          const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
          question.hidden = date.value !== today;
          if (question.hidden) {
            question.querySelectorAll('input:checked').forEach((input) => {
              input.checked = false;
            });
          }
          if (!later.length) {
            return;
          }
          const wasWaiting = waiting;
          waiting = !question.hidden && !question.querySelector('input:checked');
          later.forEach((section) => section.classList.toggle('psp-awaiting-same-day', waiting));
          // Answered: bring the zip question into view.
          if (wasWaiting && !waiting) {
            later[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        };
        date.addEventListener('change', sync);
        date.addEventListener('input', sync);
        question.addEventListener('change', sync);
        sync();
      });
    },
  };
})(Drupal, once);
