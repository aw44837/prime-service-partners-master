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
   *
   * @return {object}
   *   { value() } returning the selected YYYY-MM-DD, or '' when none.
   */
  Drupal.pspBookingDatePicker = (container, { monthsAhead = 5, value = '', onChange = () => {} } = {}) => {
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

    container.classList.add('psp-booking-picker');
    container.innerHTML = `
      <div class="psp-booking-card__days" role="group" aria-label="${Drupal.t('Day')}"></div>
      <button type="button" class="psp-booking-card__link" aria-expanded="false">${icons.calendar}<span></span></button>
      <div class="psp-booking-card__cal" hidden>
        <div class="psp-booking-card__calhead">
          <button type="button" class="psp-booking-card__nav" data-dir="-1" aria-label="${Drupal.t('Previous month')}">${icons.prev}</button>
          <span class="psp-booking-card__month" aria-live="polite"></span>
          <button type="button" class="psp-booking-card__nav" data-dir="1" aria-label="${Drupal.t('Next month')}">${icons.next}</button>
        </div>
        <div class="psp-booking-card__wk" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
        <div class="psp-booking-card__dates"></div>
      </div>`;
    const $ = (s) => container.querySelector(s);

    function render() {
      $('.psp-booking-card__days').innerHTML = quick.map((d, i) => `
        <button type="button" class="psp-booking-card__day" data-i="${i}" aria-pressed="${!state.fromCalendar && sameDay(d, state.selected)}">
          <span class="psp-booking-card__dow">${i ? d.toLocaleDateString('en-US', { weekday: 'short' }) : Drupal.t('Today')}</span>
          <span class="psp-booking-card__date">${short(d)}</span>
        </button>`).join('');

      const link = $('.psp-booking-card__link');
      link.querySelector('span').textContent = state.showCal
        ? Drupal.t('Hide calendar')
        : state.fromCalendar
          ? Drupal.t('@date selected · Change date', { '@date': short(state.selected) })
          : Drupal.t('Select a different date');
      link.setAttribute('aria-expanded', String(state.showCal));
      $('.psp-booking-card__cal').hidden = !state.showCal;

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
        state.showCal = false;
      }
      else if (button.matches('.psp-booking-card__link')) {
        state.showCal = !state.showCal;
      }
      else if (button.matches('.psp-booking-card__nav')) {
        state.monthOffset = Math.min(monthsAhead, Math.max(0, state.monthOffset + Number(button.dataset.dir)));
      }
      else if (button.matches('.psp-booking-card__cell')) {
        select(parse(button.dataset.date), true);
        state.showCal = false;
      }
      else {
        return;
      }
      render();
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

    open(url, title, opener) {
      if (!this.el) {
        this.build();
      }
      this.opener = opener;
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
    const error = $('.psp-booking-card__error');
    // Same-day follow-up ("Do you need emergency service?"), when the
    // webform has one: shown only while today is the picked day.
    const sameDay = $('.psp-booking-card__sameday');
    const answers = sameDay ? [...sameDay.querySelectorAll('.psp-booking-card__answer')] : [];
    const now = new Date();
    const todayValue = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    // Nothing is selected until the visitor picks.
    let choice = -1;
    let answer = -1;

    function setAnswer(index) {
      answer = index;
      answers.forEach((button, i) => button.setAttribute('aria-pressed', String(i === index)));
    }

    const picker = Drupal.pspBookingDatePicker($('.psp-booking-card__picker'), {
      monthsAhead: parseInt(root.dataset.monthsAhead, 10) || 0,
      onChange: (value) => {
        if (sameDay) {
          sameDay.hidden = value !== todayValue;
          if (sameDay.hidden) {
            setAnswer(-1);
          }
        }
        validate(false);
      },
    });

    // Shows what's still missing once the visitor has tried to continue.
    let tried = false;
    function validate(show) {
      tried = tried || show;
      const missing = [];
      if (choice < 0) {
        missing.push(Drupal.t('a service'));
      }
      if (!picker.value()) {
        missing.push(Drupal.t('a day'));
      }
      else if (sameDay && !sameDay.hidden && answer < 0) {
        missing.push(Drupal.t('whether you need emergency service'));
      }
      error.hidden = !tried || !missing.length;
      error.textContent = missing.length ? Drupal.t('Choose @missing to continue.', { '@missing': missing.join(Drupal.t(' and ')) }) : '';
      return !missing.length;
    }

    function formUrl() {
      const params = new URLSearchParams();
      params.set(root.dataset.choiceName, chips[choice].dataset.value);
      params.set(root.dataset.dateName, picker.value());
      if (sameDay && !sameDay.hidden && answer >= 0) {
        params.set(sameDay.dataset.name, answers[answer].dataset.value);
      }
      params.set('psp_card', '1');
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
      panel.open(url, root.dataset.formTitle, button);
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
      else if (button.matches('.psp-booking-card__answer')) {
        setAnswer(answers.indexOf(button));
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
        $('.psp-booking-card__cta').focus();
      }
    });
  }

  Drupal.behaviors.pspBookingCard = {
    attach(context) {
      once('psp-booking-card', '.psp-booking-card', context).forEach(init);
    },
  };
})(Drupal, once);
