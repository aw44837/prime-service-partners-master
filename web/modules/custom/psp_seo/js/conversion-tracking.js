/**
 * @file
 * Pushes conversion signals to the GTM dataLayer.
 *
 * Pushed immediately, without waiting for GTM: the lazy loader
 * (gtm-lazy.js) starts GTM on the first tap, which is often the phone tap
 * itself, and GTM processes everything queued in the dataLayer when it
 * starts. GTM's own click triggers would miss that first tap.
 *
 * - psp_phone_click     any tel: link. The number is normalised to digits
 *                       because hrefs vary by block (tel:704-313-9855,
 *                       tel:(704)%20313-9855).
 * - psp_booking_started a ServiceTitan scheduler link. The scheduler is
 *                       cross-origin, so this marks a booking being started,
 *                       not completed.
 * - psp_form_submit     a completed webform submission, handed over by
 *                       ConversionTrackingHooks in drupalSettings with
 *                       form_type booking, contact, job_application or other.
 *
 * Inside a same-origin iframe (the booking card loads the booking form's
 * share page in one) events go to the parent page's dataLayer, so they are
 * counted against the page the visitor is actually on.
 */
((Drupal, drupalSettings) => {
  'use strict';

  function target() {
    try {
      if (window.parent !== window && window.parent.location.origin === window.location.origin) {
        return window.parent;
      }
    }
    catch (e) {
      // Cross-origin parent: report in this window.
    }
    return window;
  }

  function push(data) {
    const w = target();
    w.dataLayer = w.dataLayer || [];
    w.dataLayer.push(data);
  }

  function normalisePhone(href) {
    let number = href.replace(/^tel:/i, '');
    try {
      number = decodeURIComponent(number);
    }
    catch (e) {
      // Leave malformed escapes as they are.
    }
    return number.replace(/[^0-9+]/g, '');
  }

  /**
   * Best-effort label for where on the page the link sits.
   */
  function regionOf(link) {
    if (window.parent !== window) {
      return 'booking_form';
    }
    const region = link.closest('[class*="region--"], header, footer, dialog, .external-panel');
    if (!region) {
      return 'content';
    }
    if (region.tagName === 'HEADER' || region.tagName === 'FOOTER') {
      return region.tagName.toLowerCase();
    }
    const match = (region.className || '').toString().match(/region--([\w-]+)/);
    return match ? match[1] : 'content';
  }

  const linkText = (link) => (link.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100);

  const reported = new Set();

  /**
   * Reports completed forms, at most once per submission even if the page is
   * reloaded, restored from history or re-attached after an AJAX update.
   */
  function reportForms() {
    ((drupalSettings.pspConversion || {}).forms || []).forEach((form) => {
      const key = `psp_form_${form.form_id}_${form.sid}`;
      if (reported.has(key)) {
        return;
      }
      reported.add(key);
      try {
        if (window.sessionStorage.getItem(key)) {
          return;
        }
        window.sessionStorage.setItem(key, '1');
      }
      catch (e) {
        // Storage blocked: report anyway.
      }
      push({
        event: 'psp_form_submit',
        form_type: form.form_type,
        form_id: form.form_id,
        form_name: form.form_name,
      });
    });
  }

  Drupal.behaviors.pspConversionTracking = {
    attach() {
      // AJAX submissions merge their event into drupalSettings and then
      // attach behaviors to the new markup, so check on every attach.
      reportForms();

      if (document.documentElement.hasAttribute('data-psp-conversion')) {
        return;
      }
      document.documentElement.setAttribute('data-psp-conversion', '');

      // Capture phase, so links whose handlers stop propagation still count.
      document.addEventListener('click', (event) => {
        const link = event.target.closest && event.target.closest('a[href]');
        if (!link) {
          return;
        }
        const href = link.getAttribute('href') || '';
        if (/^tel:/i.test(href)) {
          push({
            event: 'psp_phone_click',
            phone_number: normalisePhone(href),
            link_text: linkText(link),
            page_region: regionOf(link),
          });
        }
        else if (href.indexOf('scheduler.servicetitan.com') !== -1) {
          push({
            event: 'psp_booking_started',
            booking_url: link.href,
            link_text: linkText(link),
            page_region: regionOf(link),
          });
        }
      }, true);
    },
  };

})(Drupal, drupalSettings);
