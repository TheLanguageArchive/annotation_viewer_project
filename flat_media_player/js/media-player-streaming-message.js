/**
 * @file
 * Presents a clear message when the player has no browser-playable stream.
 */

(function (Drupal) {
  'use strict';

  const MESSAGE = 'There is no streaming version of this media file available.';

  function replaceGenericError(player) {
    const root = player.shadowRoot;
    if (!root) {
      window.setTimeout(function () {
        replaceGenericError(player);
      }, 50);
      return;
    }
    if (player.dataset.streamingMessageInitialized) {
      return;
    }
    player.dataset.streamingMessageInitialized = 'true';

    function updateMessage() {
      root.querySelectorAll('.overlay.error').forEach(function (error) {
        if (error.textContent.trim() === 'Error loading media.') {
          error.textContent = MESSAGE;
        }
      });
    }

    new MutationObserver(updateMessage).observe(root, {childList: true, subtree: true});
    updateMessage();
  }

  Drupal.behaviors.flatMediaPlayerStreamingMessage = {
    attach: function (context) {
      const players = context.querySelectorAll ? context.querySelectorAll('flat-media-player') : [];
      players.forEach(replaceGenericError);
    },
  };
})(Drupal);
