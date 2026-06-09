/**
 * @file
 * Sticky header.
 */
((Drupal, once) => {
  /**
   * The entire header element.
   */
  let header;

  /**
   * The sentinel element that Intersection Observer watches.
   */
  let sentinel;

  /**
   * The inner header element that gets the fixed treatment.
   */
  let innerHeader;

  /**
   * Removes all theme CSS classes from the passed in HTML element.
   *
   * @param {HTMLElement} el
   */
  function removeAllThemeClasses(el) {
    el.classList.remove('theme--white');
    el.classList.remove('theme--black');
    el.classList.remove('theme--dark');
    el.classList.remove('theme--primary');
    el.classList.remove('theme--light');
  }

  /**
   * Looks at the text color and theme of the hero, and returns a CSS class
   * that will be used within the header (in non-sticky mode).
   *
   * @returns string - a CSS class to apply to the header
   */
  function getHeroTheme() {
    const hero = document.querySelector('.is-behind-header');

    const evaluateTheme = (className) => hero?.classList.contains(className);

    switch (true) {
      case evaluateTheme('hero--text-color-white'):
        return 'theme--black';
      case evaluateTheme('hero--text-color-black'):
        return 'theme--white';
      case evaluateTheme('hero--text-color-primary'):
        return 'theme--white';
      case evaluateTheme('theme--dark'):
        return 'theme--dark';
      case evaluateTheme('theme--black'):
        return 'theme--black';
      case evaluateTheme('theme--primary'):
        return 'theme--primary';
      case evaluateTheme('theme--white'):
        return 'theme--white';
      case evaluateTheme('theme--light'):
        return 'theme--light';
      default:
        return '';
    }
  }

  /**
   * Initialize everything.
   *
   * @param {HTMLElement} el - the site header.
   */
  function init(el) {
    header = el;
    sentinel = document.querySelector('.site-header__sentinel');
    innerHeader = el.querySelector('.site-header__inner');

    const hasHero = innerHeader.matches('.site-header:has(~ .site-main .is-behind-header:not(.layout-builder *)) .site-header__inner');
    const headerStickyTheme = el.dataset.headerTheme || 'theme--white';
    const headerNonStickyTheme = el.dataset.siteTheme || 'theme--white';
    const headerNonStickyThemeWithHero = getHeroTheme();

    if (hasHero) {
      header.classList.add('is-superimposed-on-hero');
      header.classList.add(headerNonStickyThemeWithHero);
    }

    const options = {
      rootMargin: `-${(Drupal.displace?.().top || '0')}px 0px 0px 0px`,
      threshold: 1,
    }

    const observer = new IntersectionObserver((entries) => {
      if (!entries[0].isIntersecting) {
        innerHeader.classList.add('is-sticky');
        removeAllThemeClasses(header);

        header.classList.add(headerStickyTheme);
      }
      else {
        const newTheme = hasHero ? headerNonStickyThemeWithHero : headerNonStickyTheme;

        innerHeader.classList.remove('is-sticky');
        removeAllThemeClasses(header);
        header.classList.add(newTheme);
      }

    }, options);
    observer.observe(sentinel);
  }

  Drupal.behaviors.stickyHeader = {
    attach(context) {
      once('sticky-header', '.site-header', context).forEach(init);
    },
  };
})(Drupal, once);
