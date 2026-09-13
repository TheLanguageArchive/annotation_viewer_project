/**
 * @file
 * Fades fullscreen video controls after a brief period of inactivity.
 */

(function (Drupal) {
  'use strict';

  const IDLE_DELAY_MS = 3000;

  function attachIdleControls(player) {
    if (player.dataset.idleControlsInitialized) return;
    const root = player.shadowRoot;
    if (!root) {
      window.setTimeout(function () { attachIdleControls(player); }, 50);
      return;
    }
    player.dataset.idleControlsInitialized = 'true';

    const style = document.createElement('style');
    style.textContent = [
      ':host([data-idle-controls-hidden="true"]) .custom-controls { opacity: 0 !important; pointer-events: none !important; }',
      '.flat-media-player-play-overlay { position: absolute; inset: 0; margin: auto; width: 72px; height: 72px; border: 2px solid rgba(255, 255, 255, .9); border-radius: 50%; background: rgba(0, 0, 0, .55); color: #fff; font-size: 30px; cursor: pointer; opacity: 0; pointer-events: none; transition: opacity 180ms ease; z-index: 11; }',
      '.flat-media-player-play-overlay.is-visible { opacity: 1; pointer-events: auto; }',
    ].join('\n');
    root.appendChild(style);

    let timer = null;
    let playButton = null;
    const isFullscreen = function () { return player.matches(':fullscreen') || Boolean(root.querySelector('.media-player-app:fullscreen')); };
    const videoIsPlaying = function () { const video = root.querySelector('video'); return Boolean(video && !video.paused && !video.ended); };
    const clearTimer = function () { if (timer !== null) { window.clearTimeout(timer); timer = null; } };
    const showControls = function () { player.removeAttribute('data-idle-controls-hidden'); };

    function syncPlayButton() {
      const video = root.querySelector('video');
      const container = video && video.closest('.player-container');
      if (!video || !container) return;
      if (!playButton || !playButton.isConnected) {
        playButton = document.createElement('button');
        playButton.type = 'button';
        playButton.className = 'flat-media-player-play-overlay';
        playButton.setAttribute('aria-label', 'Play video');
        playButton.textContent = '▶';
        playButton.addEventListener('click', function () { if (video.paused || video.ended) video.play().catch(function () {}); });
        container.appendChild(playButton);
      }
      playButton.classList.toggle('is-visible', video.paused || video.ended);
    }
    function hideWhenIdle() {
      clearTimer();
      if (!isFullscreen() || !videoIsPlaying()) return;
      timer = window.setTimeout(function () { if (isFullscreen() && videoIsPlaying()) player.setAttribute('data-idle-controls-hidden', 'true'); }, IDLE_DELAY_MS);
    }
    function activity() { showControls(); hideWhenIdle(); }
    root.addEventListener('mousemove', activity);
    root.addEventListener('pointerdown', activity);
    root.addEventListener('keydown', activity);
    root.addEventListener('play', function () { activity(); syncPlayButton(); }, true);
    root.addEventListener('pause', function () { showControls(); syncPlayButton(); }, true);
    root.addEventListener('ended', syncPlayButton, true);
    root.addEventListener('loadedmetadata', syncPlayButton, true);
    document.addEventListener('fullscreenchange', function () { showControls(); isFullscreen() ? hideWhenIdle() : clearTimer(); });
    new MutationObserver(syncPlayButton).observe(root, {childList: true, subtree: true});
    syncPlayButton();
  }
  Drupal.behaviors.flatMediaPlayerIdleControls = { attach: function (context) {
    const players = context.querySelectorAll ? context.querySelectorAll('flat-media-player') : [];
    players.forEach(attachIdleControls);
  }};
})(Drupal);
