/**
 * @file
 * Lazy Google Tag Manager loader (replaces google_tag's js/gtm.js when
 * psp_seo.settings:lazy_gtm is TRUE).
 *
 * Same container setup as google_tag's loader, but the container script is
 * only requested on the visitor's first interaction (scroll, tap, click,
 * keypress) or 4 seconds after the page has loaded, whichever comes first.
 * The dataLayer exists from the start, so anything pushed earlier (consent
 * defaults, Drupal events) is queued and processed when GTM starts. GTM fires
 * its own DOM Ready / Window Loaded triggers when it initializes late.
 */
(function (drupalSettings) {
  const dl = drupalSettings.gtm ? drupalSettings.gtm.settings.data_layer : 'dataLayer';
  window[dl] = window[dl] || [];
  if (!drupalSettings.gtm) {
    return;
  }
  const config = drupalSettings.gtm;
  const events = ['scroll', 'pointerdown', 'keydown', 'touchstart'];
  const options = { passive: true, capture: true };
  let started = false;

  function start() {
    if (started) {
      return;
    }
    started = true;
    events.forEach((name) => window.removeEventListener(name, start, options));

    window[dl].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    const gtmSettings = config.settings;
    if (gtmSettings.include_classes === true) {
      window[dl].push({
        'gtm.allowlist': gtmSettings.allowlist_classes ?? [],
        'gtm.blocklist': gtmSettings.blocklist_classes ?? [],
      });
    }
    let environment = '';
    if (gtmSettings.include_environment === true) {
      environment = `&gtm_auth=${gtmSettings.environment_token ?? ''}&gtm_preview=${gtmSettings.environment_id ?? ''}&gtm_cookies_win=x`;
    }
    config.tagIds.forEach((tagId) => {
      const script = document.createElement('script');
      script.async = true;
      const dLink = dl !== 'dataLayer' ? `&l=${dl}` : '';
      script.src = `https://www.googletagmanager.com/gtm.js?id=${tagId}${environment}${dLink}`;
      document.head.appendChild(script);
    });
  }

  events.forEach((name) => window.addEventListener(name, start, options));
  const later = () => setTimeout(start, 4000);
  if (document.readyState === 'complete') {
    later();
  }
  else {
    window.addEventListener('load', later, { once: true });
  }
})(drupalSettings);
