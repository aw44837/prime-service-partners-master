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

  // Answer styling: the emergency answer red with a warning icon, the other
  // (it says "not … emergency") green with a clock.
  const ICONS = {
    urgent: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 3.5 2.5 20h19L12 3.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v4.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="17.2" r="1.25" fill="currentColor"/></svg>',
    standard: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  };
  function decorate(question) {
    question.querySelectorAll('input[type="radio"]').forEach((input) => {
      const label = input.id && question.querySelector(`label[for="${input.id}"]`);
      if (!label || label.querySelector('.psp-same-day-option__icon')) {
        return;
      }
      const kind = /\bnot\b/i.test(input.value) ? 'standard' : 'urgent';
      label.classList.add('psp-same-day-option', `psp-same-day-option--${kind}`);
      // Text in its own column so wrapped lines indent past the icon.
      const text = document.createElement('span');
      text.className = 'psp-same-day-option__text';
      text.append(...label.childNodes);
      label.append(text);
      label.insertAdjacentHTML('afterbegin', `<span class="psp-same-day-option__icon">${ICONS[kind]}</span>`);
    });
  }

  Drupal.behaviors.pspSameDayQuestion = {
    attach(context) {
      once('psp-same-day', '.psp-same-day-question', context).forEach((question) => {
        decorate(question);
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
