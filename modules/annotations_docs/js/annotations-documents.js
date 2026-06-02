(function (Drupal) {

  'use strict';

  Drupal.behaviors.annotationsDocuments = {
    attach: function (context) {
      // Keep the active nav item visible after AJAX panel replacement.
      const activeItem = context.querySelector
        ? context.querySelector('.annotations-documents__nav-item.is-active')
        : null;

      if (activeItem) {
        activeItem.scrollIntoView({ block: 'nearest' });
      }
    }
  };

}(Drupal));
