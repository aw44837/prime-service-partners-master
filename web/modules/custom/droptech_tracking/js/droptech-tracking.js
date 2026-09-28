/**
 * @file
 * Droptech Tracking: capture tracked URL parameters into a first-party cookie
 * and fill matching hidden form fields.
 *
 * - Parameter names match case-insensitively (UTM_Source = utm_source);
 *   values are kept as given.
 * - Last touch: a visit carrying any tracked parameter replaces the whole
 *   stored set, so values from two campaigns never mix. Visits without
 *   tracked parameters leave the cookie (and its expiry) untouched.
 * - Hidden inputs whose name matches a parameter (case-insensitively) are
 *   filled when empty, including forms loaded later via AJAX or in the
 *   same-origin Book Online panel iframe.
 */
((Drupal, drupalSettings, once) => {
  const settings = drupalSettings.droptechTracking || {};
  const parameters = (settings.parameters || []).map((p) => p.toLowerCase());
  const cookieName = settings.cookieName || 'dt_utm';
  const lifetimeDays = settings.lifetimeDays || 90;

  function readCookie() {
    const match = document.cookie.match(new RegExp(`(?:^|; )${cookieName}=([^;]*)`));
    if (!match) {
      return {};
    }
    try {
      const data = JSON.parse(decodeURIComponent(match[1]));
      return data && typeof data === 'object' ? data : {};
    }
    catch (e) {
      return {};
    }
  }

  function writeCookie(values) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${cookieName}=${encodeURIComponent(JSON.stringify(values))}; Max-Age=${lifetimeDays * 86400}; Path=/; SameSite=Lax${secure}`;
  }

  function capture() {
    const found = {};
    new URLSearchParams(window.location.search).forEach((value, key) => {
      const name = key.toLowerCase();
      const clean = value.trim().slice(0, 255);
      if (parameters.includes(name) && clean !== '' && !(name in found)) {
        found[name] = clean;
      }
    });
    if (Object.keys(found).length) {
      writeCookie(found);
      return found;
    }
    return readCookie();
  }

  const stored = parameters.length ? capture() : {};

  Drupal.behaviors.droptechTracking = {
    attach(context) {
      if (!Object.keys(stored).length) {
        return;
      }
      once('droptech-tracking', 'input[type="hidden"][name]', context).forEach((input) => {
        const name = input.name.toLowerCase();
        if (parameters.includes(name) && stored[name] && !input.value) {
          input.value = stored[name];
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
