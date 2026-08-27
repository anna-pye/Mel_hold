/**
 * @file
 * Reveals the selected holding-page path without moving the visitor.
 */
((Drupal, once) => {
  Drupal.behaviors.melPathSwitcher = {
    attach(context) {
      const switchers = once('mel-path-switcher', '[data-mel-path-switcher]', context);

      switchers.forEach((switcher) => {
        const triggers = Array.from(switcher.querySelectorAll('[aria-controls]'));
        const paths = triggers
          .map((trigger) => document.getElementById(trigger.getAttribute('aria-controls')))
          .filter(Boolean);

        if (triggers.length === 0 || paths.length !== triggers.length) {
          return;
        }

        const reveal = (selectedTrigger) => {
          const selectedId = selectedTrigger.getAttribute('aria-controls');
          const scrollPosition = {
            left: window.scrollX,
            top: window.scrollY,
          };

          triggers.forEach((trigger) => {
            trigger.setAttribute('aria-expanded', String(trigger === selectedTrigger));
          });

          paths.forEach((path) => {
            path.hidden = path.id !== selectedId;
          });

          if (window.history?.replaceState) {
            window.history.replaceState(null, '', `#${selectedId}`);
          }

          window.requestAnimationFrame(() => {
            window.scrollTo(scrollPosition);
          });
        };

        paths.forEach((path) => {
          path.hidden = true;
        });

        triggers.forEach((trigger) => {
          trigger.addEventListener('click', () => reveal(trigger));
        });

        const hashId = window.location.hash.slice(1);
        const hashTrigger = triggers.find(
          (trigger) => trigger.getAttribute('aria-controls') === hashId,
        );
        if (hashTrigger) {
          reveal(hashTrigger);
        }
      });
    },
  };
})(Drupal, once);
