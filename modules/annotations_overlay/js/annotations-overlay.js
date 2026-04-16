/**
 * @file
 * Annotations Overlay — modal display.
 *
 * All annotation content is server-rendered inside <dialog> elements in the
 * DOM at page load. Clicking a trigger calls showModal() on the matching
 * dialog — no content manipulation needed.
 *
 * Fully event-delegated so dynamically injected dialogs (e.g. paragraph AJAX)
 * work without re-initialisation.
 */
(function () {
  'use strict';

  // Track the trigger that opened a dialog so focus can be returned on close.
  let lastTrigger = null;

  // Open the dialog that matches the given field key.
  function openDialog(fieldKey, trigger) {
    const dialog = document.querySelector(
      '.annotations-overlay-dialog[data-annotations-field="' + CSS.escape(fieldKey) + '"]'
    );
    if (!dialog) return;

    lastTrigger = trigger;
    dialog.showModal();

    // Belt-and-braces live region announcement for AT that does not pick up
    // focus movement into the dialog. aria-label is already set server-side.
    const announcer = dialog.querySelector('.annotations-overlay-announcer');
    if (announcer) {
      const label = dialog.getAttribute('aria-label') || '';
      announcer.textContent = '';
      setTimeout(function () { announcer.textContent = label; }, 50);
    }
  }

  document.addEventListener('click', function (e) {
    // Close button.
    const closeBtn = e.target.closest('.annotations-overlay-close');
    if (closeBtn) {
      const dialog = closeBtn.closest('dialog');
      if (dialog) dialog.close();
      return;
    }

    // Backdrop click — e.target is the <dialog> element itself when the user
    // clicks outside the dialog's rendered content area.
    if (e.target.matches('dialog.annotations-overlay-dialog')) {
      e.target.close();
      return;
    }

    // Overlay trigger.
    const trigger = e.target.closest('.js-annotations-overlay-trigger');
    if (!trigger) return;
    const fieldKey = trigger.dataset.annotationsField;
    if (!fieldKey) return;
    openDialog(fieldKey, trigger);
  });

  // Return focus to the trigger that opened the dialog.
  // The 'close' event does not bubble, so use a capturing listener.
  document.addEventListener('close', function (e) {
    if (e.target.classList?.contains('annotations-overlay-dialog')) {
      lastTrigger?.focus();
      lastTrigger = null;
    }
  }, true);

}());
