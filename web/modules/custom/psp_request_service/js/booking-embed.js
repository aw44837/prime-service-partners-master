/**
 * @file
 * The full webform opened from the booking card: show the card's day picker.
 *
 * Each date input in the form gets the same quick days + calendar as the
 * card, for continuity. The input stays in the form (visually hidden) and
 * holds the value, so submission and validation are unchanged.
 */
((Drupal, once) => {
  'use strict';

  // Months between the start of this month and an input's max date.
  function monthsAhead(max) {
    const m = /^(\d{4})-(\d{2})/.exec(max || '');
    if (!m) {
      return 5;
    }
    const now = new Date();
    return Math.max(0, (Number(m[1]) - now.getFullYear()) * 12 + Number(m[2]) - 1 - now.getMonth());
  }

  Drupal.behaviors.pspBookingEmbed = {
    attach(context) {
      once('psp-booking-embed', 'form.psp-booking-embed input[type="date"]', context).forEach((input) => {
        const container = document.createElement('div');
        input.insertAdjacentElement('afterend', container);
        input.classList.add('psp-booking-date-input');
        input.setAttribute('tabindex', '-1');
        input.setAttribute('aria-hidden', 'true');
        Drupal.pspBookingDatePicker(container, {
          monthsAhead: monthsAhead(input.getAttribute('max')),
          value: input.value,
          onChange: (value) => {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
          },
        });
      });
    },
  };
})(Drupal, once);
