/**
 * @file
 * FLAT Media Player behavior.
 */

(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.flatMediaPlayer = {
        attach: function (context, settings) {
            if (!settings.flat_media_player) return;

            const viewerContainer = document.getElementById("flat-media-player");
            if (viewerContainer && !viewerContainer.hasAttribute("data-player-initialized")) {
                viewerContainer.setAttribute("data-player-initialized", "true");
                viewerContainer.innerHTML = "";

                const player = document.createElement("flat-media-player");
                player.setAttribute("url", settings.flat_media_player.apiUrl);
                viewerContainer.appendChild(player);
            }
        }
    };
})(jQuery, Drupal, drupalSettings);
