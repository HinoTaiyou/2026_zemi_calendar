/*
 * Chat page progressive enhancement:
 *  - Resolve the server-provided scroll intent to a real scroll action.
 *  - Keep the message conversation box pinned to its newest message.
 *  - Prevent double-submit and show a "sending" state (form still works w/o JS).
 *
 * The conversation lives inside #chat-messages, which is an independent
 * overflow:auto container. For the newest reply we scroll THAT container to the
 * bottom rather than calling scrollIntoView on the element (which would also
 * yank the window to the top of the box and land on the oldest messages).
 */
(function () {
  'use strict';

  var prefersReducedMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;
  var scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';

  function scrollMessagesToBottom() {
    var box = document.getElementById('chat-messages');
    if (box) {
      box.scrollTop = box.scrollHeight;
    }
  }

  function revealInWindow(id) {
    var el = document.getElementById(id);
    if (el && typeof el.scrollIntoView === 'function') {
      el.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
    }
  }

  function applyScrollIntent() {
    var intent = (document.body && document.body.dataset)
      ? document.body.dataset.scrollIntent || ''
      : '';

    // Always keep the conversation box showing its latest message; this never
    // moves the page itself.
    scrollMessagesToBottom();

    switch (intent) {
      case 'feedback':
        revealInWindow('chat-feedback');
        break;
      case 'plans':
        revealInWindow('chat-plans');
        break;
      case 'events':
        revealInWindow('chat-proposed-events');
        break;
      case 'latest-reply':
        // Newest reply is inside #chat-messages; bottom-scrolling the box above
        // already revealed it. Do not move the window.
        break;
      default:
        // '' (initial GET / reload / reset): no window scroll.
        break;
    }
  }

  function runWhenStable(fn) {
    // Two rAFs so layout (fonts, cards, images) has settled before we measure.
    requestAnimationFrame(function () {
      requestAnimationFrame(fn);
    });
  }

  function setupSendingState() {
    var form = document.querySelector('.chat-form');
    if (!form) {
      return;
    }
    var button = form.querySelector('button[type="submit"]');
    if (!button) {
      return;
    }

    form.addEventListener('submit', function () {
      if (button.dataset.originalText === undefined) {
        button.dataset.originalText = button.textContent;
      }
      button.disabled = true;
      button.textContent = '送信中…';
    });

    // Restore the button if the page is shown again from bfcache (back button).
    window.addEventListener('pageshow', function () {
      button.disabled = false;
      if (button.dataset.originalText !== undefined) {
        button.textContent = button.dataset.originalText;
      }
    });
  }

  function init() {
    setupSendingState();
    runWhenStable(applyScrollIntent);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
