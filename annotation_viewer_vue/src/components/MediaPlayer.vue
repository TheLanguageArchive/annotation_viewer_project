<script setup>
import { ref, onMounted, onUnmounted, watch, computed, provide } from 'vue';
import { useMediaStore, useEafStore } from '../stores';

const mediaStore = useMediaStore();
const eafStore = useEafStore();
const videoRef = ref(null);
const timeDisplayRef = ref(null);
const progressRef = ref(null);
let animationFrame;
const isHovering = ref(false);

// Share the audio/video element with AudioWaveform and Spectrogram via provide/inject
provide('audioRef', videoRef);

// Last time written to the store from the video's own progress; the
// currentTime watcher must not seek the video in response to these echoes.
let lastVideoSyncTime = -1;

const formatTime = (ms) => {
  if (!ms && ms !== 0) return '00:00';
  const totalSeconds = Math.floor(ms / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
};

const updateTimeIndicators = (timeMs) => {
  if (timeDisplayRef.value) {
    timeDisplayRef.value.textContent = `${formatTime(timeMs)} / ${formatTime(mediaStore.duration)}`;
  }
  if (progressRef.value) {
    progressRef.value.value = timeMs;
    const progress = (timeMs / (mediaStore.duration || 100)) * 100;
    progressRef.value.style.setProperty('--progress', `${progress}%`);
  }
};

const syncTimeFromVideo = (timeMs) => {
  lastVideoSyncTime = timeMs;
  mediaStore.updateTime(timeMs);
  eafStore.updateActiveAnnotations(timeMs);
};

const updateSmoothTime = () => {
  if (videoRef.value && !videoRef.value.paused) {
    let timeMs = videoRef.value.currentTime * 1000;

    // 1. Check segment boundary BEFORE updating store to avoid visual overshoot
    if (mediaStore.isPlayingSegment && mediaStore.selectionEnd !== null) {
      if (timeMs >= mediaStore.selectionEnd - 0.1) {
        timeMs = mediaStore.selectionEnd - 0.1;
        mediaStore.pause();
        mediaStore.isPlayingSegment = false;
        videoRef.value.currentTime = timeMs / 1000;
      }
    }

    // 2. Update store with potentially clamped time
    syncTimeFromVideo(timeMs);
  }
  // Throttled to ~50fps
  setTimeout(() => {
    animationFrame = requestAnimationFrame(updateSmoothTime);
  }, 20);
};

onMounted(() => {
  animationFrame = requestAnimationFrame(updateSmoothTime);
});

onUnmounted(() => {
  cancelAnimationFrame(animationFrame);
});

const onLoadedMetadata = () => {
  if (videoRef.value && videoRef.value.duration && !isNaN(videoRef.value.duration) && videoRef.value.duration !== Infinity) {
    mediaStore.duration = videoRef.value.duration * 1000;
  }
  if (videoRef.value) {
    videoRef.value.playbackRate = mediaStore.playbackRate;
  }
  if (progressRef.value) progressRef.value.max = mediaStore.duration || 0;
  updateTimeIndicators(mediaStore.currentTime);
};

const onPlay = () => {
  mediaStore.isPlaying = true;
};
const onPause = () => mediaStore.isPlaying = false;

// Handle play/pause from store
watch(() => mediaStore.isPlaying, (playing) => {
  if (!videoRef.value) return;
  if (playing && videoRef.value.paused) {
    videoRef.value.play();
  } else if (!playing && !videoRef.value.paused) {
    videoRef.value.pause();
  }
});

// Handle mute from store
watch(() => mediaStore.muted, (muted) => {
  if (videoRef.value) {
    videoRef.value.muted = muted;
  }
});

// Handle playback rate from store
watch(() => mediaStore.playbackRate, (rate) => {
  if (videoRef.value) {
    videoRef.value.playbackRate = rate;
  }
});

// Handle seeking from external components.
watch(
  () => mediaStore.currentTime,
  (newTimeMs) => {
    updateTimeIndicators(newTimeMs);
    if (newTimeMs === lastVideoSyncTime) return;
    if (videoRef.value) {
      const newTimeS = newTimeMs / 1000;
      if (Math.abs(videoRef.value.currentTime - newTimeS) > 0.015) {
        videoRef.value.currentTime = newTimeS;
      }
    }
  },
  { flush: 'sync' }
);

const isAudio = computed(() => {
  return mediaStore.currentMedia?.mimetype?.startsWith('audio/') || 
         mediaStore.currentMedia?.filename?.toLowerCase().endsWith('.wav') ||
         mediaStore.currentMedia?.filename?.toLowerCase().endsWith('.mp3');
});

// --- Custom Controls Logic ---

const togglePlay = () => {
  if (!videoRef.value) return;
  if (videoRef.value.paused) {
    videoRef.value.play();
  } else {
    videoRef.value.pause();
  }
};

const wasPlaying = ref(false);

const seek = (e) => {
  const newTimeMs = parseFloat(e.target.value);
  mediaStore.updateTime(newTimeMs);
  if (videoRef.value) {
    videoRef.value.currentTime = newTimeMs / 1000;
  }
};

const onSeekStart = () => {
  wasPlaying.value = mediaStore.isPlaying;
  mediaStore.pause();
};

const onSeekEnd = () => {
  if (wasPlaying.value) {
    mediaStore.play();
  }
};

const toggleMute = () => {
  mediaStore.muted = !mediaStore.muted;
};

const setVolume = (e) => {
  const vol = parseFloat(e.target.value);
  mediaStore.volume = vol;
  if (videoRef.value) videoRef.value.volume = vol;
  if (vol === 0) mediaStore.muted = true;
  else mediaStore.muted = false;
};

defineExpose({ togglePlay });
</script>

<template>
  <div 
    class="player-container" 
    :class="{ 'audio-only': isAudio, 'no-media-active': !mediaStore.currentMedia }"
    @mouseenter="isHovering = true"
    @mouseleave="isHovering = false"
  >
    <video 
      v-if="mediaStore.currentMedia && !isAudio"
      :key="mediaStore.currentMedia.url"
      ref="videoRef"
      :src="mediaStore.currentMedia.url"
      :type="mediaStore.currentMedia.mimetype"
      class="video-element"
      preload="auto"
      playsinline
      @loadedmetadata="onLoadedMetadata"
      @play="onPlay"
      @pause="onPause"
      @click="togglePlay"
    ></video>
    <audio
      v-else-if="mediaStore.currentMedia && isAudio"
      :key="mediaStore.currentMedia.url"
      ref="videoRef"
      :src="mediaStore.currentMedia.url"
      class="audio-element"
      preload="auto"
      @loadedmetadata="onLoadedMetadata"
      @play="onPlay"
      @pause="onPause"
    ></audio>
    <div v-else class="no-media">
      No streaming media file available or accessible
    </div>

    <!-- Custom Controls Overlay -->
    <div 
      class="custom-controls" 
      :class="{ 'visible': isHovering || !mediaStore.isPlaying || isAudio }"
      v-if="mediaStore.currentMedia"
    >
      <button class="control-btn play-btn" @click.stop="togglePlay">
        <svg v-if="mediaStore.isPlaying" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
        <svg v-else viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
      </button>

      <span ref="timeDisplayRef" class="time-display">00:00 / 00:00</span>

      <input 
        ref="progressRef"
        type="range" 
        class="progress-bar" 
        min="0" 
        :max="mediaStore.duration || 0" 
        value="0"
        @input="seek"
        @mousedown="onSeekStart" 
        @mouseup="onSeekEnd"
      >

      <div class="volume-container-wrapper">
        <div class="volume-controls">
          <div class="volume-slider-container">
            <input 
              type="range" 
              class="volume-slider" 
              min="0" 
              max="1" 
              step="0.05" 
              :value="mediaStore.muted ? 0 : mediaStore.volume"
              :style="{ '--progress': `${(mediaStore.muted ? 0 : mediaStore.volume) * 100}%` }"
              @input="setVolume"
            >
          </div>
          <button class="control-btn mute-btn" @click.stop="toggleMute">
            <svg v-if="mediaStore.muted || mediaStore.volume === 0" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-8-7-8v1.4c3.15 1.13 5.48 4.29 5.48 8.01zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73 4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
            <svg v-else viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.player-container {
  width: 100%;
  max-width: var(--fav-player-max-width, 100%);
  max-height: var(--fav-player-max-height, 500px);
  margin: 0 auto;
  background: #000;
  aspect-ratio: 16 / 9;
  position: relative;
  transition: aspect-ratio 0.3s ease;
  overflow: hidden;
  user-select: none;
}

.video-element {
  width: 100%;
  height: 100%;
  position: absolute;
  top: 0;
  left: 0;
  object-fit: contain;
  cursor: pointer;
}


/* Shared Accent Color */
input[type="range"] {
  accent-color: #3facf7; /* User Requested Blue */
}

.custom-controls {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
  padding: 10px 15px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  gap: 15px;
  opacity: 0;
  transform: translate3d(0, 0, 0);
  will-change: opacity;
  backface-visibility: hidden;
  z-index: 10;
}

.custom-controls.visible {
  opacity: 1;
}

.control-btn {
  background: none;
  border: none;
  color: #fff;
  cursor: pointer;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0.9;
  transition: color 0.2s, opacity 0.2s;
}

.control-btn:hover {
  opacity: 1;
  color: #3facf7; /* User Requested Blue Hover */
}

.time-display {
  color: #fff;
  font-size: 12px;
  font-family: monospace;
  min-width: 80px;
  text-align: center;
}

.progress-bar {
  flex: 1;
  cursor: pointer;
  height: 20px;
  width: 100%;
  -webkit-appearance: none;
  appearance: none;
  outline: none;
  background-color: transparent !important;
}

.progress-bar:hover {
  height: 20px;
}

/* Custom Thumb Styling */
.progress-bar::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 18px;
  height: 18px;
  background: #fff;
  border: 1px solid var(--fav-border);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
  margin-top: -6px; /* (6/2) - (18/2) = -6 */
}

/* Base track styles for webkit */
.progress-bar::-webkit-slider-runnable-track {
  height: 6px;
  border-radius: 3px;
  margin: 7px 9px;
  background-image: linear-gradient(to right, var(--fav-primary) 0%, var(--fav-primary) var(--progress, 0%), var(--fav-range-track) var(--progress, 0%), var(--fav-range-track) 100%) !important;
  background-size: 100% 100% !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
}

.progress-bar::-moz-range-track {
  height: 6px;
  border-radius: 3px;
  margin: 7px 9px;
  background-image: linear-gradient(to right, var(--fav-primary) 0%, var(--fav-primary) var(--progress, 0%), var(--fav-range-track) var(--progress, 0%), var(--fav-range-track) 100%) !important;
  background-size: 100% 100% !important;
}

.progress-bar::-moz-range-thumb {
  width: 18px;
  height: 18px;
  background: #fff;
  border: 1px solid var(--fav-border);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
}

/* Vertical Volume Control */
.volume-container-wrapper {
  position: relative;
  width: 34px;
  height: 34px;
}

.volume-controls {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  flex-direction: column; 
  align-items: center;
  justify-content: flex-end; 
  background: rgba(0, 0, 0, 0.6); /* Dark background even in light mode */
  border-radius: 17px;
  transition: height 0.3s ease, background 0.3s, border-radius 0.2s;
  overflow: hidden;
  height: 34px;
}

.volume-controls:hover {
  height: 140px;
  background: rgba(0, 0, 0, 0.85); /* Slightly darker on hover */
  box-shadow: 0 4px 15px rgba(0,0,0,0.4);
}

.volume-slider-container {
  width: 100%;
  height: 0;
  overflow: hidden;
  transition: height 0.3s ease;
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 0;
  position: relative;
}

.volume-controls:hover .volume-slider-container {
  height: 100px;
}

.volume-slider {
  width: 90px;
  height: 20px;
  cursor: pointer;
  -webkit-appearance: none;
  appearance: none;
  outline: none;
  background-color: transparent !important;
  transform: rotate(-90deg);
  transform-origin: center;
}

.volume-slider::-webkit-slider-runnable-track {
  height: 6px;
  border-radius: 3px;
  margin: 7px 9px;
  background-image: linear-gradient(to right, var(--fav-primary) 0%, var(--fav-primary) var(--progress, 100%), var(--fav-range-track) var(--progress, 100%), var(--fav-range-track) 100%) !important;
  background-size: 100% 100% !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
}

.volume-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 18px;
  height: 18px;
  background: #fff;
  border: 1px solid var(--fav-border);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
  margin-top: -6px;
}

.volume-slider::-moz-range-track {
  height: 6px;
  border-radius: 3px;
  background: var(--fav-range-track);
}

.volume-slider::-moz-range-thumb {
  width: 18px;
  height: 18px;
  background: #fff;
  border: 1px solid var(--fav-border);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
}

.mute-btn {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff !important; /* Always white for legibility on dark background */
}

/* Audio Only Styles */
.player-container.audio-only {
  aspect-ratio: auto;
  height: 54px;
  background: var(--fav-bg-alt);
  overflow: visible;
}

.player-container.audio-only .custom-controls {
  position: relative;
  background: transparent;
  opacity: 1;
  padding: 5px 15px;
}

.player-container.audio-only .control-btn,
.player-container.audio-only .time-display {
  color: var(--fav-text);
}

.player-container.audio-only .control-btn:hover {
  color: #7ca0d8; /* Slightly darker blue for light theme */
}

.player-container.audio-only .volume-controls {
  /* No special background inherited from body in audio-only anymore */
}

.player-container.audio-only .volume-slider-container {
  background: var(--fav-bg); /* Use page bg for audio only popup */
}

.no-media {
  color: var(--fav-text-muted);
  display: flex;
  justify-content: center;
  align-items: center;
  height: 54px;
  background: var(--fav-bg);
  font-size: 14px;
}
.player-container.no-media-active {
  aspect-ratio: auto;
  height: 54px;
  background: transparent;
}
</style>
