/**
 * @file
 * Booking card: pick a service and a day, then open the full webform.
 *
 * Continue loads the webform's share page (no header/footer) in an iframe,
 * prefilled through the query string with the two answers, either inside
 * the card (data-display="inline") or in a side panel over the page
 * (data-display="panel"). psp_card=1 tells the form to use the card styling.
 *
 * The day picker (three quick days, "Select a different date", month
 * calendar) is shared as Drupal.pspBookingDatePicker so the full form can
 * show the same picker in place of its date input (booking-embed.js).
 */
((Drupal, once) => {
  'use strict';

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const short = (d) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  const sameDay = (a, b) => !!a && !!b && iso(a) === iso(b);
  const parse = (value) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
  };

  const icons = {
    calendar: '<svg class="psp-booking-card__icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 10h17M8 3v4M16 3v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    prev: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="m15 5-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    next: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  };

  /**
   * Renders the day picker into a container.
   *
   * @param {HTMLElement} container
   *   Gets the psp-booking-picker class and the picker markup.
   * @param {object} options
   *   - monthsAhead: how many months past this one the calendar reaches.
   *   - value: initial date (YYYY-MM-DD) or empty for no selection.
   *   - onChange: called with the new YYYY-MM-DD value.
   *   - popover: show the month calendar as a floating popover (browser top
   *     layer, so no parent's overflow clips it) instead of expanding the
   *     picker; the page around it doesn't move.
   *
   * @return {object}
   *   { value() } returning the selected YYYY-MM-DD, or '' when none.
   */
  Drupal.pspBookingDatePicker = (container, { monthsAhead = 5, value = '', onChange = () => {}, popover = false } = {}) => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const quick = [0, 1, 2].map((i) => new Date(today.getFullYear(), today.getMonth(), today.getDate() + i));
    const initial = parse(value);
    const state = {
      // A quick day stays highlighted only when it was picked as a quick day.
      selected: initial,
      fromCalendar: !!initial && !quick.some((d) => sameDay(d, initial)),
      showCal: false,
      monthOffset: 0,
    };
    if (state.fromCalendar) {
      state.monthOffset = Math.max(0, Math.min(monthsAhead, (initial.getFullYear() - today.getFullYear()) * 12 + initial.getMonth() - today.getMonth()));
    }

    const calendarId = `psp-booking-cal-${Math.random().toString(36).slice(2, 9)}`;
    // Popover needs browser support (all current browsers); otherwise expand.
    const usePopover = popover && typeof HTMLElement.prototype.showPopover === 'function';
    container.classList.add('psp-booking-picker');
    container.innerHTML = `
      <div class="psp-booking-card__days" role="group" aria-label="${Drupal.t('Day')}"></div>
      <button type="button" class="psp-booking-card__link" aria-expanded="false">${icons.calendar}<span></span></button>
      <div class="psp-booking-card__cal" id="${calendarId}" role="dialog" aria-label="${Drupal.t('Choose a date')}" hidden>
        <div class="psp-booking-card__calhead">
          <button type="button" class="psp-booking-card__nav" data-dir="-1" aria-label="${Drupal.t('Previous month')}">${icons.prev}</button>
          <span class="psp-booking-card__month" aria-live="polite"></span>
          <button type="button" class="psp-booking-card__nav" data-dir="1" aria-label="${Drupal.t('Next month')}">${icons.next}</button>
        </div>
        <div class="psp-booking-card__wk" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
        <div class="psp-booking-card__dates"></div>
      </div>`;
    const $ = (s) => container.querySelector(s);
    const link = $('.psp-booking-card__link');
    const cal = $('.psp-booking-card__cal');
    link.setAttribute('aria-controls', calendarId);

    // Popover: float the calendar under the link (or above it when there's
    // no room below), within the viewport, as wide as the picker (max 360px).
    function position() {
      const anchor = link.getBoundingClientRect();
      const box = container.getBoundingClientRect();
      const gap = 6;
      const edge = 8;
      const width = Math.min(box.width, 360, window.innerWidth - edge * 2);
      cal.style.width = `${width}px`;
      const height = cal.offsetHeight;
      let top = anchor.bottom + gap;
      if (top + height > window.innerHeight - edge && anchor.top - gap - height >= edge) {
        top = anchor.top - gap - height;
      }
      top = Math.max(edge, Math.min(top, window.innerHeight - height - edge));
      const left = Math.max(edge, Math.min(box.left, window.innerWidth - width - edge));
      cal.style.top = `${top}px`;
      cal.style.left = `${left}px`;
    }
    if (usePopover) {
      cal.hidden = false;
      cal.setAttribute('popover', 'auto');
      // The link is the popover's native invoker, so clicking it while open
      // closes it (rather than light-dismiss closing and the click reopening).
      link.setAttribute('popovertarget', calendarId);
      cal.classList.add('psp-booking-card__cal--popover');
      const follow = () => position();
      cal.addEventListener('toggle', (e) => {
        state.showCal = e.newState === 'open';
        if (state.showCal) {
          position();
          window.addEventListener('scroll', follow, true);
          window.addEventListener('resize', follow);
          (cal.querySelector('.psp-booking-card__cell[aria-pressed="true"]') || cal.querySelector('.psp-booking-card__cell:not(:disabled)')).focus();
        }
        else {
          window.removeEventListener('scroll', follow, true);
          window.removeEventListener('resize', follow);
          // Esc / outside click: give focus back to the link.
          if (cal.contains(document.activeElement) || document.activeElement === document.body) {
            link.focus();
          }
        }
        renderLink();
      });
    }

    function renderLink() {
      link.querySelector('span').textContent = state.showCal
        ? Drupal.t('Hide calendar')
        : state.fromCalendar
          ? Drupal.t('@date selected · Change date', { '@date': short(state.selected) })
          : Drupal.t('Select a different date');
      link.setAttribute('aria-expanded', String(state.showCal));
    }

    function render() {
      $('.psp-booking-card__days').innerHTML = quick.map((d, i) => `
        <button type="button" class="psp-booking-card__day" data-i="${i}" aria-pressed="${!state.fromCalendar && sameDay(d, state.selected)}">
          <span class="psp-booking-card__dow">${i ? d.toLocaleDateString('en-US', { weekday: 'short' }) : Drupal.t('Today')}</span>
          <span class="psp-booking-card__date">${short(d)}</span>
        </button>`).join('');

      renderLink();
      if (!usePopover) {
        cal.hidden = !state.showCal;
      }

      const month = new Date(today.getFullYear(), today.getMonth() + state.monthOffset, 1);
      $('.psp-booking-card__month').textContent = month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      container.querySelector('[data-dir="-1"]').disabled = state.monthOffset === 0;
      container.querySelector('[data-dir="1"]').disabled = state.monthOffset >= monthsAhead;

      const days = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
      let cells = '<span></span>'.repeat(month.getDay());
      for (let n = 1; n <= days; n++) {
        const d = new Date(month.getFullYear(), month.getMonth(), n);
        const label = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        cells += `<button type="button" class="psp-booking-card__cell${sameDay(d, today) ? ' psp-booking-card__cell--today' : ''}" data-date="${iso(d)}" aria-label="${label}" aria-pressed="${state.fromCalendar && sameDay(d, state.selected)}"${d < today ? ' disabled' : ''}>${n}</button>`;
      }
      $('.psp-booking-card__dates').innerHTML = cells;
    }

    function closeCalendar() {
      if (usePopover && cal.matches(':popover-open')) {
        cal.hidePopover();
      }
      state.showCal = false;
    }

    function select(date, fromCalendar) {
      state.selected = date;
      state.fromCalendar = fromCalendar;
      onChange(iso(date));
    }

    container.addEventListener('click', (e) => {
      const button = e.target.closest('button');
      if (!button || !container.contains(button)) {
        return;
      }
      if (button.matches('.psp-booking-card__day')) {
        select(quick[Number(button.dataset.i)], false);
        closeCalendar();
      }
      else if (button.matches('.psp-booking-card__link')) {
        if (usePopover) {
          // popovertarget toggles it; the toggle event updates the state.
          return;
        }
        state.showCal = !state.showCal;
      }
      else if (button.matches('.psp-booking-card__nav')) {
        state.monthOffset = Math.min(monthsAhead, Math.max(0, state.monthOffset + Number(button.dataset.dir)));
      }
      else if (button.matches('.psp-booking-card__cell')) {
        select(parse(button.dataset.date), true);
        closeCalendar();
        if (usePopover) {
          link.focus();
        }
      }
      else {
        return;
      }
      render();
      if (usePopover && state.showCal) {
        position();
      }
    });

    render();
    return {
      value: () => (state.selected ? iso(state.selected) : ''),
    };
  };

  /**
   * Keeps the inline form's same-origin iframe as tall as its document.
   *
   * After the first load (a submit: errors or the confirmation), scrolls the
   * card back into view so the result isn't off-screen.
   */
  function autoHeight(frame, card) {
    let observer;
    let loads = 0;
    frame.addEventListener('load', () => {
      if (observer) {
        observer.disconnect();
      }
      let doc;
      try {
        doc = frame.contentDocument;
      }
      catch (e) {
        return;
      }
      if (!doc || !doc.body || frame.src === 'about:blank') {
        return;
      }
      doc.documentElement.classList.add('psp-booking-inline');
      if (loads++) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      // Measure the body, not the document: the document is never shorter
      // than the iframe, so the frame could grow but never shrink.
      const fit = () => {
        const style = doc.defaultView.getComputedStyle(doc.body);
        const height = doc.body.getBoundingClientRect().height + parseFloat(style.marginTop) + parseFloat(style.marginBottom);
        frame.style.height = `${Math.ceil(height)}px`;
      };
      fit();
      observer = new ResizeObserver(fit);
      observer.observe(doc.body);
    });
  }

  /**
   * The page-wide side panel, built on first use.
   */
  const panel = {
    el: null,
    backdrop: null,
    frame: null,
    opener: null,

    build() {
      this.backdrop = document.createElement('div');
      this.backdrop.className = 'psp-booking-panel-backdrop';
      this.el = document.createElement('div');
      this.el.className = 'psp-booking-panel';
      this.el.setAttribute('role', 'dialog');
      this.el.setAttribute('aria-modal', 'true');
      this.el.setAttribute('aria-labelledby', 'psp-booking-panel-title');
      this.el.innerHTML = `
        <div class="psp-booking-panel__head">
          <h2 class="psp-booking-panel__title" id="psp-booking-panel-title"></h2>
          <button type="button" class="psp-booking-panel__close" aria-label="${Drupal.t('Close')}">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
          </button>
        </div>
        <p class="psp-booking-panel__loading">${Drupal.t('Loading…')}</p>
        <iframe class="psp-booking-panel__frame" title=""></iframe>`;
      this.frame = this.el.querySelector('iframe');
      this.frame.addEventListener('load', () => this.el.classList.add('is-loaded'));
      this.el.querySelector('.psp-booking-panel__close').addEventListener('click', () => this.close());
      this.backdrop.addEventListener('click', () => this.close());
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.el.classList.contains('is-open')) {
          this.close();
        }
      });
      document.body.append(this.backdrop, this.el);
    },

    open(url, title, opener, theme) {
      if (!this.el) {
        this.build();
      }
      this.opener = opener;
      // Same Dripyard background as the card that opened it.
      [...this.el.classList].filter((c) => c.startsWith('theme--')).forEach((c) => this.el.classList.remove(c));
      if (theme && theme !== 'inherit') {
        this.el.classList.add(`theme--${theme}`);
      }
      this.el.querySelector('.psp-booking-panel__title').textContent = title;
      this.frame.title = title;
      this.el.classList.remove('is-loaded');
      this.frame.src = url;
      document.documentElement.style.overflow = 'hidden';
      requestAnimationFrame(() => {
        this.backdrop.classList.add('is-open');
        this.el.classList.add('is-open');
        this.el.querySelector('.psp-booking-panel__close').focus();
      });
    },

    close() {
      this.backdrop.classList.remove('is-open');
      this.el.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      if (this.opener) {
        this.opener.focus();
      }
      // Drop the form once the slide-out finishes, so reopening starts fresh.
      setTimeout(() => {
        if (!this.el.classList.contains('is-open')) {
          this.frame.src = 'about:blank';
        }
      }, 400);
    },
  };

  function init(root) {
    const $ = (s) => root.querySelector(s);
    const chips = [...root.querySelectorAll('.psp-booking-card__chip')];
    const cta = $('.psp-booking-card__cta');
    const ctaLabel = cta.innerHTML;
    const status = $('.psp-booking-card__status');
    // Nothing is selected until the visitor picks, unless the page decided
    // the service (data-preselect; the card then shows no service step).
    const preset = root.dataset.preselect || '';
    let choice = -1;
    let resetTimer;

    const picker = Drupal.pspBookingDatePicker($('.psp-booking-card__picker'), {
      monthsAhead: parseInt(root.dataset.monthsAhead, 10) || 0,
      popover: true,
      onChange: () => validate(false),
    });

    // What's still missing, shown without changing the card's height: the
    // missing group gets an outline and Continue briefly says what to pick
    // (announced to screen readers through the status region).
    let tried = false;
    function validate(show) {
      tried = tried || show;
      const needService = choice < 0 && !preset;
      const needDay = !picker.value();
      root.querySelector('.psp-booking-card__chips')?.classList.toggle('is-missing', tried && needService);
      root.querySelector('.psp-booking-card__days').classList.toggle('is-missing', tried && needDay);
      const message = needService && needDay
        ? Drupal.t('Pick a service and day')
        : needService ? Drupal.t('Pick a service') : needDay ? Drupal.t('Pick a day') : '';
      clearTimeout(resetTimer);
      if (show && message) {
        cta.textContent = message;
        cta.classList.add('is-missing');
        status.textContent = message;
        resetTimer = setTimeout(restoreCta, 2500);
      }
      else if (!message) {
        restoreCta();
      }
      return !message;
    }
    function restoreCta() {
      cta.innerHTML = ctaLabel;
      cta.classList.remove('is-missing');
    }

    function formUrl() {
      const params = new URLSearchParams();
      params.set(root.dataset.choiceName, choice >= 0 ? chips[choice].dataset.value : preset);
      params.set(root.dataset.dateName, picker.value());
      if (root.dataset.market) {
        params.set('service_market', root.dataset.market);
      }
      if (root.dataset.topic) {
        params.set('page_topic', root.dataset.topic);
      }
      params.set('psp_card', '1');
      if (root.dataset.choiceStyle === 'icons') {
        params.set('psp_icons', '1');
      }
      if (root.dataset.theme && root.dataset.theme !== 'inherit') {
        params.set('psp_theme', root.dataset.theme);
      }
      return `${root.dataset.formUrl}?${params}`;
    }

    function openForm(button) {
      const url = formUrl();
      if (root.dataset.display === 'inline') {
        const frame = $('.psp-booking-card__frame');
        if (!frame.dataset.autoHeight) {
          frame.dataset.autoHeight = '1';
          autoHeight(frame, root);
        }
        frame.src = url;
        $('[data-step="pick"]').hidden = true;
        $('[data-step="form"]').hidden = false;
        $('.psp-booking-card__back').focus();
        return;
      }
      panel.open(url, root.dataset.formTitle, button, root.dataset.theme);
    }

    root.addEventListener('click', (e) => {
      const button = e.target.closest('button');
      if (!button || !root.contains(button)) {
        return;
      }
      if (button.matches('.psp-booking-card__chip')) {
        choice = chips.indexOf(button);
        chips.forEach((chip, i) => chip.setAttribute('aria-pressed', String(i === choice)));
        validate(false);
      }
      else if (button.matches('.psp-booking-card__cta')) {
        if (validate(true)) {
          openForm(button);
        }
      }
      else if (button.matches('.psp-booking-card__back')) {
        $('[data-step="form"]').hidden = true;
        $('[data-step="pick"]').hidden = false;
        cta.focus();
      }
    });
  }

  Drupal.behaviors.pspBookingCard = {
    attach(context) {
      once('psp-booking-card', '.psp-booking-card', context).forEach(init);
    },
  };
})(Drupal, once);
