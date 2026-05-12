(function (Drupal) {

  'use strict';

  Drupal.behaviors.annotationsExplorer = {
    attach: function (context) {
      const items = context.querySelectorAll
        ? context.querySelectorAll('.annotations-explorer__nav-item')
        : [];

      Array.prototype.forEach.call(items, function (item) {
        const summary = item.querySelector('details > summary');
        if (!summary) return;

        summary.addEventListener('click', function (e) {
          // Link clicks handle their own navigation.
          if (e.target.closest('a')) return;
          // Arrow click: prevent native toggle and delegate to the link.
          e.preventDefault();
          const link = summary.querySelector('a.use-ajax');
          if (link) link.click();
        });
      });
    }
  };

}(Drupal));
