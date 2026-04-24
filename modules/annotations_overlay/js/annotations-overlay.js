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
  let lastTrigger = NULL;

  // Open the dialog that matches the given field key.
  function openDialog(fieldKey, trigger) {
    const dialog = document.querySelector(
      '.annotations-overlay-dialog[data-annotations-field="' + CSS.escape(fieldKey) + '"]'
    );
    if (!dialog) {
      return;
    }

    lastTrigger = trigger;
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    dialog.showModal();
    // showModal() can scroll the page to the dialog's natural DOM position before
    // top-layer promotion. rAF runs after the promotion so the dialog is already
    // position:fixed and the scroll restoration does not affect its visual position.
    requestAnimationFrame(function () {
      window.scrollTo(scrollX, scrollY);
    });

    // Belt-and-braces live region announcement for AT that does not pick up
    // focus movement into the dialog. aria-label is already set server-side.
    const announcer = dialog.querySelector('.annotations-overlay-announcer');
    if (announcer) {
      const label = dialog.getAttribute('aria-label') || '';
      announcer.textContent = '';
      setTimeout(function () {
        announcer.textContent = label; }, 50);
    }
  }

  document.addEventListener('click', function (e) {
    // Close button.
    const closeBtn = e.target.closest('.annotations-overlay-close');
    if (closeBtn) {
      const dialog = closeBtn.closest('dialog');
      if (dialog) {
        dialog.close();
      }
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
    if (!trigger) {
      return;
    }
    const fieldKey = trigger.dataset.annotationsField;
    if (!fieldKey) {
      return;
    }
    openDialog(fieldKey, trigger);
  });

  // Return focus to the trigger that opened the dialog.
  // The 'close' event does not bubble, so use a capturing listener.
  // setTimeout(0) defers until after the browser moves focus to <body> on Escape
  // (which would otherwise scroll the page to the top). preventScroll stops the
  // browser scrolling to bring the trigger into view — it was already visible.
  document.addEventListener('close', function (e) {
    if (e.target.classList?.contains('annotations-overlay-dialog')) {
      const trigger = lastTrigger;
      lastTrigger = NULL;
      if (trigger) {
        setTimeout(function () {
          trigger.focus({ preventScroll: TRUE }); }, 0);
      }
    }
  }, TRUE);

  // On view pages, entityViewAlter injects triggers as build-array siblings of
  // their fields because field.html.twig discards arbitrary children. Move each
  // trigger into its field wrapper so the CSS child-combinator rule
  // [data-annotations-field]>.annotations-overlay-trigger takes effect.
  // On form pages the trigger is already a child, so the guard is a no-op.
  document.querySelectorAll('.js-annotations-overlay-trigger[data-annotations-field]').forEach(function (trigger) {
    const fieldKey = trigger.dataset.annotationsField;
    if (fieldKey === '_bundle' || fieldKey === '_preview') {
      return;
    }
    const wrapper = document.querySelector('[data-annotations-field="' + CSS.escape(fieldKey) + '"]:not(button)');
    if (wrapper && !wrapper.contains(trigger)) {
      wrapper.appendChild(trigger);
    }
  });

}());
