/**
 * @file
 * Service-address field: suggestions as you type, zip from the chosen address.
 *
 * Suggestions come from this site's /psp-address/* endpoints (Google Places,
 * server-side). Choosing an address fills the hidden street/city/state/place
 * fields and the form's zip field, which stays the serviceability check (the
 * zip script validates it and reveals the rest of the form). The zip field is
 * hidden unless it's needed:
 * - a 5-digit zip typed in the address field is used directly;
 * - a city shows its service-area zips to choose from;
 * - nothing found, or an address without a zip: the not-found message and
 *   the zip field;
 * - lookup unavailable: the zip field, so a booking is never blocked.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  const NOT_FOUND = Drupal.t('We cannot find your service address. If this is a new address or not found, please enter the service zip code to continue.');
  const UNAVAILABLE = Drupal.t('Address lookup is not available right now. Please enter the service zip code to continue.');
  const uuid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16)));

  function init(input) {
    const settings = drupalSettings.pspAddress || {};
    const form = input.form;
    const zip = form && form.querySelector('input[name="zip"]');
    const zipWrap = zip && zip.closest('.psp-address-zip');
    if (!settings.available || !zip || !zipWrap) {
      // No lookup configured: the address and zip fields work as plain fields.
      return;
    }
    const field = (name) => form.querySelector(`input[name="${name}"]`);
    const hidden = {
      street: field('service_street'),
      city: field('service_city'),
      state: field('service_state'),
      place: field('service_place_id'),
    };
    const wrapper = input.closest('.psp-address-lookup');
    const id = `psp-address-${Math.random().toString(36).slice(2, 9)}`;

    // Suggestions list, status line, and city zip chooser.
    const list = document.createElement('ul');
    list.id = `${id}-list`;
    list.className = 'psp-address-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', Drupal.t('Address suggestions'));
    list.hidden = true;
    const status = document.createElement('div');
    status.className = 'psp-address-status';
    status.setAttribute('aria-live', 'polite');
    const chooser = document.createElement('div');
    chooser.className = 'psp-address-zips';
    chooser.hidden = true;
    input.insertAdjacentElement('afterend', list);
    list.insertAdjacentElement('afterend', status);
    status.insertAdjacentElement('afterend', chooser);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('autocomplete', 'off');

    let session = uuid();
    let suggestions = [];
    let active = -1;
    let seq = 0;
    let timer;
    let lastEmpty = false;
    let resolved = !!(hidden.place && hidden.place.value) || /^\d{5}$/.test(input.value.trim());
    // Fallback = the zip field is showing (not found / lookup down / no-JS
    // style entry after a server-side error).
    let fallback = !resolved && zip.value !== '';

    const allowed = () => new RegExp(`^(?:${zip.getAttribute('pattern') || '[0-9]{5}'})$`);
    const outOfArea = () => zip.getAttribute('data-webform-pattern-error') || Drupal.t('It appears that you have entered a zip code outside of our service area.');

    function showZip(show) {
      fallback = show;
      zipWrap.classList.toggle('psp-address-zip--hidden', !show);
    }

    function say(message, kind) {
      status.textContent = message;
      status.className = `psp-address-status${kind ? ` psp-address-status--${kind}` : ''}`;
      wrapper.classList.toggle('psp-address-valid', kind === 'success');
    }

    function setZip(value) {
      zip.value = value;
      // The zip script validates it and reveals the rest of the form.
      zip.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setHidden(place) {
      hidden.street && (hidden.street.value = place.street || '');
      hidden.city && (hidden.city.value = place.city || '');
      hidden.state && (hidden.state.value = place.state || '');
      hidden.place && (hidden.place.value = place.id || '');
    }

    // A zip is known: serviceable or not. `what` is 'address' or 'zip'.
    function finish(value, what = 'zip') {
      setZip(value);
      resolved = true;
      showZip(false);
      chooser.hidden = true;
      if (allowed().test(value)) {
        say(what === 'address' ? Drupal.t('We service this address!') : Drupal.t('We service this zip code!'), 'success');
      }
      else {
        say(outOfArea(), 'error');
      }
    }

    function reset() {
      resolved = false;
      setHidden({});
      chooser.hidden = true;
      say('');
      if (!fallback && zip.value !== '') {
        setZip('');
      }
    }

    function notFound(message) {
      close();
      resolved = false;
      setHidden({});
      chooser.hidden = true;
      showZip(true);
      say(message || NOT_FOUND, 'error');
    }

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    }

    function render() {
      list.innerHTML = '';
      suggestions.forEach((s, i) => {
        const li = document.createElement('li');
        li.id = `${id}-opt-${i}`;
        li.className = 'psp-address-option';
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', String(i === active));
        const main = document.createElement('span');
        main.className = 'psp-address-option__main';
        main.textContent = s.main;
        const secondary = document.createElement('span');
        secondary.className = 'psp-address-option__secondary';
        secondary.textContent = s.secondary;
        li.append(main, secondary);
        // mousedown keeps focus in the input (no blur before the choice).
        li.addEventListener('mousedown', (e) => {
          e.preventDefault();
          choose(i);
        });
        list.append(li);
      });
      if (suggestions.length && settings.attribution) {
        const credit = document.createElement('li');
        credit.className = 'psp-address-credit';
        credit.setAttribute('role', 'presentation');
        credit.textContent = settings.attribution;
        list.append(credit);
      }
      list.hidden = !suggestions.length;
      input.setAttribute('aria-expanded', String(!!suggestions.length));
      if (active >= 0) {
        input.setAttribute('aria-activedescendant', `${id}-opt-${active}`);
        list.querySelector(`#${id}-opt-${active}`).scrollIntoView({ block: 'nearest' });
      }
      else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    async function getJson(url) {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error(String(response.status));
      }
      return response.json();
    }

    async function search(value) {
      const mine = ++seq;
      try {
        const data = await getJson(`${settings.suggest}?q=${encodeURIComponent(value)}&session=${session}`);
        if (mine !== seq) {
          return null;
        }
        suggestions = data.suggestions || [];
        lastEmpty = !suggestions.length;
        active = -1;
        render();
        return suggestions;
      }
      catch (e) {
        if (mine === seq) {
          notFound(UNAVAILABLE);
        }
        return null;
      }
    }

    async function choose(index) {
      const s = suggestions[index];
      if (!s) {
        return;
      }
      close();
      input.value = s.secondary ? `${s.main}, ${s.secondary}` : s.main;
      say(Drupal.t('Checking…'));
      let place;
      try {
        const data = await getJson(`${settings.place}?id=${encodeURIComponent(s.id)}&session=${session}`);
        place = data.place;
      }
      catch (e) {
        notFound(UNAVAILABLE);
        return;
      }
      // A finished lookup ends the billing session.
      session = uuid();
      place.id = s.id;
      if (place.kind === 'city' && !place.zip) {
        setHidden(place);
        input.value = [place.city, place.state].filter(Boolean).join(', ');
        cityZips(place);
        return;
      }
      if (!place.zip) {
        notFound();
        return;
      }
      setHidden(place);
      if (place.formatted) {
        input.value = place.formatted;
      }
      finish(place.zip, place.street ? 'address' : 'zip');
    }

    // A city: pick one of its service-area zips.
    function cityZips(place) {
      resolved = false;
      showZip(false);
      if (zip.value !== '') {
        setZip('');
      }
      if (!place.zips || !place.zips.length) {
        chooser.hidden = true;
        say(outOfArea(), 'error');
        return;
      }
      say('');
      chooser.innerHTML = '';
      const label = document.createElement('p');
      label.className = 'psp-address-zips__label';
      label.id = `${id}-zips`;
      label.textContent = Drupal.t('Which zip code is the service address in?');
      const group = document.createElement('div');
      group.className = 'psp-address-zips__options';
      group.setAttribute('role', 'group');
      group.setAttribute('aria-labelledby', label.id);
      place.zips.forEach((value) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'psp-address-zips__option';
        button.textContent = value;
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => {
          group.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
          input.value = `${[place.city, place.state].filter(Boolean).join(', ')} ${value}`;
          finish(value);
          chooser.hidden = false;
        });
        group.append(button);
      });
      chooser.append(label, group);
      chooser.hidden = false;
    }

    // Enter / leaving the field with typed text that wasn't chosen.
    async function resolveTyped() {
      const value = input.value.trim();
      if (resolved || value.length < 3) {
        return;
      }
      if (/^\d{5}$/.test(value)) {
        close();
        setHidden({});
        finish(value);
        return;
      }
      clearTimeout(timer);
      const found = await search(value);
      if (found && found.length) {
        choose(0);
      }
      else if (found) {
        notFound();
      }
    }

    input.addEventListener('input', () => {
      const value = input.value.trim();
      if (resolved || chooser.hidden === false) {
        reset();
      }
      clearTimeout(timer);
      if (/^\d{5}$/.test(value)) {
        // A zip on its own: no lookup needed.
        ++seq;
        close();
        setHidden({});
        finish(value);
        return;
      }
      if (value.length < 3) {
        ++seq;
        close();
        return;
      }
      timer = setTimeout(async () => {
        const found = await search(value);
        if (found && !found.length && value.length >= 8) {
          notFound();
        }
        else if (found && found.length && fallback) {
          // Found it after all: the zip field isn't needed.
          say('');
          showZip(false);
        }
      }, 250);
    });

    input.addEventListener('keydown', (e) => {
      const open = !list.hidden && suggestions.length;
      if (e.key === 'ArrowDown' && open) {
        e.preventDefault();
        active = (active + 1) % suggestions.length;
        render();
      }
      else if (e.key === 'ArrowUp' && open) {
        e.preventDefault();
        active = (active - 1 + suggestions.length) % suggestions.length;
        render();
      }
      else if (e.key === 'Enter') {
        // Never submit from here (the Enter guard also blocks it).
        e.preventDefault();
        if (open) {
          choose(active >= 0 ? active : 0);
        }
        else {
          resolveTyped();
        }
      }
      else if (e.key === 'Escape' && open) {
        e.preventDefault();
        close();
      }
    });

    input.addEventListener('blur', () => {
      setTimeout(() => {
        close();
        const value = input.value.trim();
        if (!resolved && !fallback && chooser.hidden && value.length >= 3) {
          if (lastEmpty) {
            notFound();
          }
          else {
            say(Drupal.t('Please choose your address from the list.'), 'hint');
          }
        }
      }, 150);
    });

    // Fallback zip entered: its own check (the zip script) takes over, so
    // drop the not-found / unavailable message once the zip is complete.
    zip.addEventListener('input', () => {
      if (fallback && /^\d{5}$/.test(zip.value)) {
        say('');
      }
    });

    // Start: hide the zip field unless it's already in use.
    showZip(fallback);
    if (resolved && zip.value) {
      const ok = allowed().test(zip.value);
      say(ok ? (hidden.street && hidden.street.value ? Drupal.t('We service this address!') : Drupal.t('We service this zip code!')) : outOfArea(), ok ? 'success' : 'error');
    }
  }

  Drupal.behaviors.pspAddressLookup = {
    attach(context) {
      once('psp-address', '.psp-address-lookup input.psp-address-input', context).forEach(init);
    },
  };
})(Drupal, drupalSettings, once);
