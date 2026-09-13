<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useMediaStore } from './stores';
import MediaPlayer from './components/MediaPlayer.vue';

const props = defineProps({
  url: String,
  width: { type: String, default: '100%' },
  height: { type: String, default: '500px' }
});

const version = "1.0.165";
const mediaStore = useMediaStore();
const isFullScreen = ref(false);
const appRef = ref(null);

const handleMouseMove = () => {
  // Pass down to player layer if needed, or remove.
};

const toggleFullScreen = () => {
  const elem = appRef.value;
  if (!elem) return;
  
  if (!document.fullscreenElement) {
    elem.requestFullscreen().then(() => {
      isFullScreen.value = true;
    }).catch(err => {});
  } else {
    document.exitFullscreen();
    isFullScreen.value = false;
  }
};

const handleKeyDown = (e) => {
  const activeTag = document.activeElement?.tagName;
  if (['INPUT', 'SELECT', 'TEXTAREA'].includes(activeTag)) return;

  switch(e.code) {
    case 'Space':
      e.preventDefault();
      if (mediaStore.isPlaying) mediaStore.pause();
      else mediaStore.play();
      break;
    case 'ArrowRight':
      e.preventDefault();
      mediaStore.updateTime(mediaStore.currentTime + 40);
      break;
    case 'ArrowLeft':
      e.preventDefault();
      mediaStore.updateTime(Math.max(0, mediaStore.currentTime - 40));
      break;
    case 'KeyM':
      mediaStore.muted = !mediaStore.muted;
      break;
    case 'KeyF':
      toggleFullScreen();
      break;
  }
};

onMounted(async () => {
  document.addEventListener('fullscreenchange', () => {
    isFullScreen.value = !!document.fullscreenElement;
    if (!isFullScreen.value) {
      mediaStore.resetVizHeights();
    }
  });
  window.addEventListener('keydown', handleKeyDown);

  const isBodyDark = document.body.classList.contains('dark-mode');
  mediaStore.setDarkMode(isBodyDark);

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.attributeName === 'class') {
        const isDark = document.body.classList.contains('dark-mode');
        if (isDark !== mediaStore.darkMode) {
          mediaStore.setDarkMode(isDark);
        }
      }
    });
  });
  observer.observe(document.body, { attributes: true });
  onUnmounted(() => observer.disconnect());

  if (props.url) {
    await mediaStore.fetchMedia(props.url);
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
});

const isVideoMedia = computed(() => {
  if (!mediaStore.currentMedia) return false;
  
  const m = mediaStore.currentMedia;
  const mime = m.mimetype || '';
  const id = m.id || ''; 
  
  const isAudio = mime.startsWith('audio/') || id.toLowerCase().endsWith('.mp3') || id.toLowerCase().endsWith('.wav');
  if (isAudio) return false;
  
  return mime.startsWith('video/') || 
         id.toLowerCase().endsWith('.mp4') || 
         id.toLowerCase().endsWith('.mov') ||
         id.toLowerCase().endsWith('.webm');
});

const fullscreenPlayerHeight = ref(85);
const isResizing = ref(false);

const handleResizeStart = (e) => {
  isResizing.value = true;
  document.addEventListener('mousemove', handleResizeMove);
  document.addEventListener('mouseup', handleResizeEnd);
  document.body.style.userSelect = 'none';
};

const handleResizeMove = (e) => {
  if (!isResizing.value) return;
  const rawPercentage = (e.clientY / window.innerHeight) * 100;
  fullscreenPlayerHeight.value = Math.min(Math.max(rawPercentage, 20), 100);
};

const handleResizeEnd = () => {
  isResizing.value = false;
  document.removeEventListener('mousemove', handleResizeMove);
  document.removeEventListener('mouseup', handleResizeEnd);
  document.body.style.userSelect = '';
};

const fullscreenStyle = computed(() => {
  return {
    '--fav-fs-player-height': `${fullscreenPlayerHeight.value}vh`
  };
});

</script>

<template>
  <div 
    class="media-player-app" 
    :class="{ 
      'is-resizing': isResizing,
      'dark-mode': mediaStore.darkMode
    }"
    ref="appRef"
    @mousemove="handleMouseMove"
    :style="fullscreenStyle"
    :data-build-version="version"
  >
    <div v-if="mediaStore.loading" class="overlay">Loading Media Data...</div>
    <div v-else-if="mediaStore.error" class="overlay error">Error loading media.</div>
    
    <template v-else>
      <div class="player-controls-section" :class="{ 'video-background': isVideoMedia }">
        <MediaPlayer @toggle-fullscreen="toggleFullScreen" :is-full-screen="isFullScreen" />
        
        <div 
          class="resize-handle"
          @mousedown.prevent="handleResizeStart"
          title="Drag to resize video"
        >
          <div class="resize-handle-bar"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
:host {
  display: block;
  max-width: 100%;
  width: 100%;
  box-sizing: border-box;
}

.media-player-app {
  display: flex;
  flex-direction: column;
  background: var(--fav-bg);
  color: var(--fav-text);
  border: 1px solid var(--fav-border);
  box-sizing: border-box;
  font-family: sans-serif;
  width: 100%;
  max-width: 100%;
  overflow-x: clip;
  overflow-y: visible;
  margin-bottom: 0;
  clear: both;

  /* Theme Variables */
  --fav-bg: #ffffff;
  --fav-text: #333333;
  --fav-text-muted: #888888;
  --fav-border: #dddddd;
  --fav-border-light: #eeeeee;
  --fav-bg-alt: #f5f5f5;
  --fav-bg-header: #f0f0f0;
  --fav-bg-active: #e6f0ff;
  --fav-primary: #007bff;
  --fav-player-bg: #ffffff;
  --fav-control-bg: #ffffff;
  --fav-range-track: #e8e8e8;
}

.media-player-app.dark-mode {
  --fav-bg: #1a1a1a;
  --fav-text: #e0e0e0;
  --fav-text-muted: #a0a0a0;
  --fav-border: #333333;
  --fav-border-light: #2a2a2a;
  --fav-bg-alt: #242424;
  --fav-bg-header: #2d2d2d;
  --fav-bg-active: #2c3e50;
  --fav-primary: #3facf7;
  --fav-player-bg: #111111;
  --fav-control-bg: #222222;
  --fav-range-track: #666666;
}

.media-player-app:fullscreen {
  margin-bottom: 0;
  border: none;
  background: #000;
  width: 100vw;
  height: 100vh;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  color: #333;
  overflow: hidden; /* No scrolling in dedicated player fullscreen */
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  --fav-player-max-height: 85vh;
  --fav-player-max-width: 100%;
}

.media-player-app:fullscreen .player-controls-section {
  max-width: 100% !important;
  width: 100% !important;
  background: #000 !important;
  border: none;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

/* Audio in fullscreen: light background via section (player-controls-section is in App.vue template) */
.media-player-app:fullscreen .player-controls-section:not(.video-background) {
  background: var(--fav-bg-alt) !important;
  flex: 1 !important;           /* Fill the full 100vh height */
  height: 100% !important;
  justify-content: flex-start !important; /* Don't center vertically */
}

/* Hide the resize handle in audio fullscreen */
.media-player-app:fullscreen .player-controls-section:not(.video-background) .resize-handle {
  display: none !important;
}

/* ── Audio fullscreen layout via :deep() so scoped CSS doesn't break ───────
   :deep() strips [data-v-xxx] from descendants, letting us target child
   component elements that have their own scoped hash.                    */

/* 1. Player container becomes a flex column so children can fill height */
.media-player-app:fullscreen :deep(.player-container.audio-only) {
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  max-height: none !important;
  background: var(--fav-bg-alt) !important;
}

/* 2. Controls bar stays its natural height, anchored to the bottom */
.media-player-app:fullscreen :deep(.player-container.audio-only .custom-controls) {
  flex-shrink: 0 !important;
  background: transparent !important;
}

/* 3. Control icons + time use theme colours in audio fullscreen */
.media-player-app:fullscreen :deep(.player-container.audio-only .control-btn),
.media-player-app:fullscreen :deep(.player-container.audio-only .time-display) {
  color: var(--fav-text) !important;
}

/* 4. Waveform and Spectrogram wrappers behavior in fullscreen */
.media-player-app:fullscreen :deep(.waveform-wrapper) {
  flex: 1 !important;
  min-height: 0 !important;
  border-bottom: none;
  border-top: 1px solid var(--fav-border, #ddd);
  overflow: hidden;
}

.media-player-app:fullscreen :deep(.spectrogram-wrapper) {
  flex: 0 0 auto !important;
  min-height: 0 !important;
  border-bottom: none;
  border-top: 1px solid var(--fav-border, #ddd);
  overflow: hidden;
}

.player-controls-section {
  background: var(--fav-player-bg);
  width: 100%;
  position: relative;
}

.player-controls-section.video-background {
  background: #000;
}

.error, .overlay {
  padding: 20px;
  text-align: center;
  font-weight: bold;
}
.error {
  color: #d9534f;
}

.resize-handle {
  width: 100%;
  height: 12px; 
  background: transparent; 
  border: none; 
  cursor: row-resize;
  display: none; 
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
  z-index: 1000;
  position: relative;
  flex-shrink: 0;
}

.media-player-app:fullscreen .resize-handle {
  display: flex !important;
}

.resize-handle:hover,
.media-player-app.is-resizing .resize-handle {
  background: rgba(255, 255, 255, 0.1);
}

.resize-handle-bar {
  width: 60px;
  height: 2px;
  background: #aaa;
  border-radius: 1px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.5);
}

.media-player-app:fullscreen .player-container {
  max-height: var(--fav-fs-player-height, 85vh) !important;
  height: var(--fav-fs-player-height, 85vh) !important;
  width: 100% !important;
  background: #000 !important;
  transition: height 0.05s linear, max-height 0.05s linear;
}

.media-player-app.is-resizing:fullscreen .player-container {
  transition: none;
}
</style>
