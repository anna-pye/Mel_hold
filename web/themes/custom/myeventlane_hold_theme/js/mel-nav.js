/**
 * @file
 * Accessible mobile navigation toggle for the MEL public header.
 */
((Drupal, once) => {
  Drupal.behaviors.melNavToggle = {
    attach(context) {
      const toggles = once('mel-nav-toggle', '[data-mel-nav-toggle]', context);

      toggles.forEach((toggle) => {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!panel) {
          return;
        }

        const close = () => {
          toggle.setAttribute('aria-expanded', 'false');
          panel.classList.remove('is-open');
        };

        toggle.addEventListener('click', () => {
          const open = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!open));
          panel.classList.toggle('is-open', !open);
        });

        panel.querySelectorAll('a').forEach((link) => {
          link.addEventListener('click', close);
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            close();
          }
        });

        document.addEventListener('click', (event) => {
          if (!panel.classList.contains('is-open')) {
            return;
          }
          if (panel.contains(event.target) || toggle.contains(event.target)) {
            return;
          }
          close();
        });
      });
    },
  };
})(Drupal, once);
