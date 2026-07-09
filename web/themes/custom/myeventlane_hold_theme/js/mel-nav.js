/**
 * @file
 * Accessible mobile navigation toggle for the inner-page header.
 */
((Drupal) => {
  Drupal.behaviors.melNavToggle = {
    attach(context) {
      const toggles = once('mel-nav-toggle', '[data-mel-nav-toggle]', context);
      toggles.forEach((toggle) => {
        const panel = document.getElementById(
          toggle.getAttribute('aria-controls'),
        );
        if (!panel) {
          return;
        }
        toggle.addEventListener('click', () => {
          const open = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!open));
          panel.classList.toggle('is-open', !open);
        });
      });
    },
  };
})(Drupal);
