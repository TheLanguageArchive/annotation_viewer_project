<script setup>
import { ref, onMounted, onUnmounted, watch, computed, provide } from 'vue';
import { useMediaStore } from '../stores';
import AudioWaveform from './AudioWaveform.vue';
import Spectrogram from './Spectrogram.vue';

const showSpectrogram = ref(false);
const toggleSpectrogram = () => { showSpectrogram.value = !showSpectrogram.value; };

const props = defineProps({
  isFullScreen: Boolean
});
const emit = defineEmits(['toggle-fullscreen']);

const mediaStore = useMediaStore();
const videoRef = ref(null);
let animationFrame;
const isHovering = ref(false);

// Share the audio element with AudioWaveform via provide/inject
provide('audioRef', videoRef);

const updateSmoothTime = () => {
  if (videoRef.value && !videoRef.value.paused) {
    const timeMs = videoRef.value.currentTime * 1000;
    mediaStore.updateTime(timeMs);
  }
  // Throttled to ~25fps (1000ms / 40ms = 25)
  setTimeout(() => {
    animationFrame = requestAnimationFrame(updateSmoothTime);
  }, 40);
};

const onTimeUpdate = () => {
  if (videoRef.value) {
    const timeMs = videoRef.value.currentTime * 1000;
    mediaStore.updateTime(timeMs);
    
    // Aggressive fallback to catch duration if the loadedmetadata event was missed (Safari/cached media issue)
    if (!mediaStore.duration && videoRef.value.duration && !isNaN(videoRef.value.duration) && videoRef.value.duration !== Infinity) {
      mediaStore.duration = videoRef.value.duration * 1000;
    }
  }
};

const onLoadedMetadata = () => {
  if (videoRef.value && videoRef.value.duration && !isNaN(videoRef.value.duration) && videoRef.value.duration !== Infinity) {
    mediaStore.duration = videoRef.value.duration * 1000;
  }
};

// Check immediately on mount in case it is completely cached in Safari
onMounted(() => {
  animationFrame = requestAnimationFrame(updateSmoothTime);
  if (videoRef.value && videoRef.value.readyState >= 1) { // HAVE_METADATA
    onLoadedMetadata();
  }
});

const onPlay = () => mediaStore.isPlaying = true;
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

// Handle seeking from external components
watch(() => mediaStore.currentTime, (newTimeMs) => {
  if (videoRef.value) {
    const newTimeS = newTimeMs / 1000;
    // Only update if difference is significant (avoid fighting the update loop)
    // AND if we are not currently dragging the slider (handled by setTime)
    if (Math.abs(videoRef.value.currentTime - newTimeS) > 0.1) {
      videoRef.value.currentTime = newTimeS;
    }
  }
});

const isAudio = computed(() => {
  return mediaStore.currentMedia?.mimetype?.startsWith('audio/') || 
         mediaStore.currentMedia?.filename?.toLowerCase().endsWith('.wav') ||
         mediaStore.currentMedia?.filename?.toLowerCase().endsWith('.mp3');
});



const togglePlay = () => {
  if (!videoRef.value) return;
  if (videoRef.value.paused) {
    videoRef.value.play();
  } else {
    videoRef.value.pause();
  }
};

// Auto-close spectrogram if switching to video
watch(isAudio, (audio) => {
  if (!audio) showSpectrogram.value = false;
});

const formatTime = (ms) => {
  if (!ms && ms !== 0) return '00:00';
  const totalSeconds = Math.floor(ms / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
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

// Resizing logic removed - 50/50 split enforced in CSS

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
      ref="videoRef"
      :src="mediaStore.currentMedia.url"
      :type="mediaStore.currentMedia.mimetype"
      class="video-element"
      preload="metadata"
      playsinline
      @timeupdate="onTimeUpdate"
      @loadedmetadata="onLoadedMetadata"
      @play="onPlay"
      @pause="onPause"
      @click="togglePlay"
    ></video>
    <audio
      v-else-if="mediaStore.currentMedia && isAudio"
      ref="videoRef"
      :src="mediaStore.currentMedia.url"
      class="audio-element"
      @timeupdate="onTimeUpdate"
      @loadedmetadata="onLoadedMetadata"
      @play="onPlay"
      @pause="onPause"
    ></audio>

    <!-- Audio Waveform: rendered above the controls bar -->
    <AudioWaveform
      v-if="mediaStore.currentMedia && isAudio"
      :audio-url="mediaStore.currentMedia.url"
      :visualization-url="mediaStore.currentMedia.visualization_url"
      :peaks-url="mediaStore.peaksUrl"
      :style="props.isFullScreen ? { flex: '1 1 0%', height: 'auto' } : {}"
    />

    <!-- Spectrogram: optional, toggled by button in controls -->
    <Spectrogram
      v-if="showSpectrogram && mediaStore.currentMedia && isAudio"
      :audio-url="mediaStore.currentMedia.url"
      :visualization-url="mediaStore.currentMedia.visualization_url"
      :style="props.isFullScreen ? { flex: '0 0 auto', height: 'auto' } : {}"
    />
    <div v-if="!mediaStore.currentMedia" class="no-media">
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

      <span class="time-display">{{ formatTime(mediaStore.currentTime) }} / {{ formatTime(mediaStore.duration) }}</span>

      <input 
        type="range" 
        class="progress-bar" 
        min="0" 
        :max="mediaStore.duration || 100" 
        :value="mediaStore.currentTime" 
        :style="{ '--progress': `${(mediaStore.currentTime / (mediaStore.duration || 100)) * 100}%` }"
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

      <!-- Spectrogram toggle: only for audio -->
      <button
        v-if="isAudio"
        class="control-btn spectrogram-btn boxed"
        :class="{ active: showSpectrogram }"
        @click.stop="toggleSpectrogram"
        title="Toggle Spectrogram"
      >
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
          <!-- Row 1 -->
          <rect x="2" y="4" width="4" height="2" rx="0.5" fill-opacity="0.3"/>
          <rect x="7" y="4" width="6" height="2" rx="0.5" fill-opacity="0.6"/>
          <rect x="14" y="4" width="3" height="2" rx="0.5" fill-opacity="0.2"/>
          <rect x="18" y="4" width="4" height="2" rx="0.5" fill-opacity="0.4"/>
          <!-- Row 2 (Formant) -->
          <rect x="2" y="8" width="8" height="3" rx="1" fill-opacity="0.8"/>
          <rect x="11" y="8" width="5" height="3" rx="1" fill-opacity="1"/>
          <rect x="17" y="8" width="5" height="3" rx="1" fill-opacity="0.7"/>
          <!-- Row 3 -->
          <rect x="2" y="13" width="3" height="2" rx="0.5" fill-opacity="0.5"/>
          <rect x="6" y="13" width="7" height="2" rx="0.5" fill-opacity="0.9"/>
          <rect x="14" y="13" width="8" height="2" rx="0.5" fill-opacity="0.4"/>
          <!-- Row 4 (Formant) -->
          <rect x="2" y="17" width="5" height="3" rx="1" fill-opacity="0.6"/>
          <rect x="8" y="17" width="10" height="3" rx="1" fill-opacity="0.95"/>
          <rect x="19" y="17" width="3" height="3" rx="1" fill-opacity="0.5"/>
        </svg>
      </button>

      <button class="control-btn boxed" @click.stop="emit('toggle-fullscreen')" title="Toggle Fullscreen" style="margin-left: auto;">
        <svg v-if="!props.isFullScreen" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
          <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
        </svg>
        <svg v-else viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
          <path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/>
        </svg>
      </button>

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
  transition: opacity 0.3s ease;
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

.control-btn.boxed {
  border: 1px solid rgba(255, 255, 255, 0.5);
  background: rgba(255, 255, 255, 0.05);
  border-radius: 4px;
  width: 26px;
  height: 26px;
  box-sizing: border-box;
  margin-left: 5px;
}

.control-btn.boxed:hover {
  background: rgba(255, 255, 255, 0.2);
  border-color: rgba(255, 255, 255, 0.8);
}

.spectrogram-btn.active {
  color: #3facf7;
  opacity: 1;
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
  position: relative; /* Added for absolute child stabilization */
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
  height: auto;
  min-height: 54px;
  background: var(--fav-bg-alt);
  overflow: visible;
}

/* The wrapper div around <AudioWaveform> — grows to fill in fullscreen */
.waveform-area {
  width: 100%;
  /* Height in normal view is driven by the waveform content */
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
  background: var(--fav-border-light, #eee); /* light pill to match gray bg */
}

.player-container.audio-only .volume-controls:hover {
  background: var(--fav-bg-alt, #f5f5f5);
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
}

.player-container.audio-only .mute-btn {
  color: var(--fav-text) !important; /* override always-white icon */
}

.player-container.audio-only .volume-slider-container {
  background: var(--fav-bg-alt); /* expanded popup bg matches audio area */
}

.player-container.audio-only .control-btn.boxed {
  border-color: #999;
  color: var(--fav-text);
  background: rgba(0, 0, 0, 0.03);
}

.player-container.audio-only .control-btn.boxed:hover {
  background: var(--fav-bg-header);
  color: var(--fav-primary);
  border-color: #666;
}

.player-container.audio-only .control-btn.boxed.active {
  background: var(--fav-bg-active);
  color: var(--fav-primary);
  border-color: var(--fav-primary);
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

<style>
/* Global styles to reach into WaveSurfer's Shadow DOM */
.player-container ::part(cursor) {
  height: 100% !important;
  opacity: 1 !important;
  /* Use a variable or fallback to the primary blue */
  background-color: #3facf7 !important;
}
</style>
