/**
 * @file
 * "Choose Your Location" dropdown: toggle, outside-click and Escape to close.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.pspLocationSelector = {
    attach: function (context) {
      const widgets = context.querySelectorAll ? context.querySelectorAll('.psp-location-selector') : [];
      widgets.forEach(function (widget) {
        if (widget.dataset.pspProcessed) { return; }
        widget.dataset.pspProcessed = '1';
        const toggle = widget.querySelector('.js-psp-location-toggle');
        const panel = widget.querySelector('.psp-location-selector__panel');

        const close = function () {
          panel.hidden = true;
          toggle.setAttribute('aria-expanded', 'false');
        };

        toggle.addEventListener('click', function () {
          const open = panel.hidden;
          panel.hidden = !open;
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
          if (!panel.hidden && !widget.contains(event.target)) { close(); }
        });

        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !panel.hidden) {
            close();
            toggle.focus();
          }
        });
      });
    }
  };
})(Drupal);
