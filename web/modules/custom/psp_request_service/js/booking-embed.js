/**
 * @file
 * The full webform opened from the booking card: show the card's day picker.
 *
 * Each date input in the form gets the same quick days + calendar as the
 * card, for continuity. The input stays in the form (visually hidden) and
 * holds the value, so submission and validation are unchanged.
 */
((Drupal, drupalSettings, once) => {
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

  // Trade icons on the service buttons, as on the card (psp_icons=1).
  function tradeIcons(context) {
    const settings = drupalSettings.pspBookingIcons;
    if (!settings) {
      return;
    }
    once('psp-booking-icons', `form.psp-booking-embed input[name="${settings.name}"]`, context).forEach((input) => {
      const label = input.parentElement.querySelector(`label[for="${input.id}"]`);
      const icon = settings.icons[input.value];
      if (!label || !icon) {
        return;
      }
      const text = label.textContent.trim();
      label.classList.add('psp-booking-card__trade');
      label.innerHTML = `<span class="psp-booking-card__badge psp-booking-card__badge--${icon.icon}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${icon.path}"/></svg></span><span class="psp-booking-card__trade-label"></span>`;
      label.querySelector('.psp-booking-card__trade-label').textContent = text;
      const group = input.closest('.webform-options-display-buttons');
      group && group.classList.add('psp-booking-picker-icons');
    });
  }

  Drupal.behaviors.pspBookingEmbed = {
    attach(context) {
      tradeIcons(context);
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
})(Drupal, drupalSettings, once);
