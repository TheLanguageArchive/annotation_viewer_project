<script setup>
import { onMounted, onUnmounted, ref, computed, watch } from 'vue';
import { useEafStore, useMediaStore } from './stores';
import MediaPlayer from './components/MediaPlayer.vue';
import Controls from './components/Controls.vue';
import Timeline from './components/Timeline.vue';

const props = defineProps({
  url: String,
  width: { type: String, default: '100%' },
  height: { type: String, default: '500px' }
});

const version = "1.1.193";
const eafStore = useEafStore();
const mediaStore = useMediaStore();
const isFullScreen = ref(false);
const appRef = ref(null);
const showControls = ref(true);
const showWaveform = ref(false);
const showSpectrogram = ref(false);
let controlsTimeout = null;

const handleMouseMove = () => {
  // Only apply fade-out logic in Subtitle view during Fullscreen
  if (eafStore.viewMode === 'subtitle' && isFullScreen.value && mediaStore.isPlaying) {
    showControls.value = true;
    clearTimeout(controlsTimeout);
    controlsTimeout = setTimeout(() => {
      showControls.value = false;
    }, 3000);
  } else {
    // Always show UI in all other modes/views
    showControls.value = true;
    clearTimeout(controlsTimeout);
  }
};

const toggleFullScreen = () => {
  const elem = appRef.value;
  if (!elem) return;
  
  if (!document.fullscreenElement) {
    elem.requestFullscreen().then(() => {
      isFullScreen.value = true;
      handleMouseMove();
    }).catch(err => {
      // console.error removed: avoided console noise on fullscreen errors
    });
  } else {
    document.exitFullscreen();
    isFullScreen.value = false;
    showControls.value = true;
  }
};

const handleKeyDown = (e) => {
  // Ignore shortcuts if the user is typing in an input or select
  const activeTag = document.activeElement?.tagName;
  if (['INPUT', 'SELECT', 'TEXTAREA'].includes(activeTag)) {
    return;
  }

  switch(e.code) {
    case 'Space':
      e.preventDefault();
      if (mediaStore.isPlaying) {
        mediaStore.pause();
      } else {
        mediaStore.play();
      }
      break;
    case 'ArrowRight':
      e.preventDefault();
      // Move forward by one frame
      mediaStore.updateTime(mediaStore.currentTime + mediaStore.frameDuration);
      break;
    case 'ArrowLeft':
      e.preventDefault();
      // Move backward by one frame
      mediaStore.updateTime(Math.max(0, mediaStore.currentTime - mediaStore.frameDuration));
      break;
    case 'KeyM':
      mediaStore.muted = !mediaStore.muted;
      break;
    case 'KeyF':
      toggleFullScreen();
      break;
  }
};

onMounted(() => {
  document.addEventListener('fullscreenchange', () => {
    isFullScreen.value = !!document.fullscreenElement;
    if (!isFullScreen.value) {
      eafStore.resetVizHeights();
    }
  });
  window.addEventListener('keydown', handleKeyDown);

  // Sync dark mode exactly to body class on mount (overriding any stale LocalStorage state)
  const isBodyDark = document.body.classList.contains('dark-mode');
  eafStore.setDarkMode(isBodyDark);

  // MutationObserver to watch for dark-mode class on body (external toggle)
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.attributeName === 'class') {
        const isDark = document.body.classList.contains('dark-mode');
        if (isDark !== eafStore.darkMode) {
          eafStore.setDarkMode(isDark);
        }
      }
    });
  });
  observer.observe(document.body, { attributes: true });
  onUnmounted(() => observer.disconnect());
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
});

const getTierColor = (tierId, opacity = 1) => {
  const palette = [
    '#2980B9', '#D35400', '#27AE60', '#8E44AD', '#F39C12',
    '#1ABC9C', '#C0392B', '#7F8C8D', '#C71585', '#6B8E23',
    '#4682B4', '#D2691E', '#008080', '#DC143C', '#1E90FF',
    '#B8860B', '#3CB371', '#9932CC', '#FF6347', '#5F9EA0',
    '#A0522D', '#32CD32', '#6A5ACD', '#CD5C5C', '#34495E'
  ];

  // ALWAYS use the original order for colors so they stay stable during reordering
  const idToFind = String(tierId);
  const tierIndex = eafStore.tierOrder.indexOf(idToFind);
  
  const colorIndex = tierIndex !== -1 ? (tierIndex % palette.length) : 0;
  const hex = palette[colorIndex];
  
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  
  return `rgba(${r}, ${g}, ${b}, ${opacity})`;
};

const getActiveSubtitleForTier = (tierId) => {
  const tier = eafStore.eaf?.tiers[tierId] || 
               Object.values(eafStore.eaf?.tiers || {}).find(t => t.id === tierId);
  
  if (!tier) return '';
  const currentTime = mediaStore.currentTime;
  const minDurationMs = 1500; // Minimum display time for a subtitle
  
  const annotations = Object.values(tier.annotations);
  
  // 1. Find the annotation that is CURRENTLY active based on EAF timing
  const activeIndex = annotations.findIndex(ann => {
    const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.start ?? 0;
    const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0;
    return currentTime >= start && currentTime < end;
  });

  if (activeIndex !== -1) return annotations[activeIndex].value;

  // 2. If nothing is active, check if the RECENTLY active one should still be shown (Persistence)
  // We look for the last annotation that finished within the last minDurationMs
  const lastActive = annotations.findLast((ann, idx) => {
    const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0;
    const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.start ?? 0;
    
    // Condition A: It ended recently
    const endedRecently = currentTime > end && (currentTime - end) < minDurationMs;
    
    // Condition B: Precedence - Is there a NEW one starting already?
    // We check if the NEXT annotation starts before the current time
    const nextAnn = annotations[idx + 1];
    let nextStartsSoon = false;
    if (nextAnn) {
      const nextStart = nextAnn.custom_start ?? nextAnn.start ?? nextAnn.referenced_annotation?.start ?? 0;
      if (currentTime >= nextStart) nextStartsSoon = true;
    }

    return endedRecently && !nextStartsSoon;
  });

  return lastActive ? lastActive.value : '';
};

// Simple Levenshtein distance component for fuzzy matching
const levenshtein = (a, b) => {
  if (a.length === 0) return b.length;
  if (b.length === 0) return a.length;
  const matrix = [];
  for (let i = 0; i <= b.length; i++) { matrix[i] = [i]; }
  for (let j = 0; j <= a.length; j++) { matrix[0][j] = j; }
  for (let i = 1; i <= b.length; i++) {
    for (let j = 1; j <= a.length; j++) {
      if (b.charAt(i - 1) === a.charAt(j - 1)) {
        matrix[i][j] = matrix[i - 1][j - 1];
      } else {
        matrix[i][j] = Math.min(
          matrix[i - 1][j - 1] + 1,
          matrix[i][j - 1] + 1,
          matrix[i - 1][j] + 1
        );
      }
    }
  }
  return matrix[b.length][a.length];
};

let viewerLoadRequest = 0;

const loadViewer = async (url) => {
  const request = ++viewerLoadRequest;
  // Removing the current source before loading prevents the previous audio/video
  // element from remaining visible while Flat Browser switches records.
  mediaStore.currentMedia = null;
  mediaStore.currentTime = 0;
  mediaStore.duration = 0;
  mediaStore.isPlaying = false;
  eafStore.eaf = null;
  eafStore.error = false;

  if (url) {
    await eafStore.fetchEaf(url);
    if (request !== viewerLoadRequest) return;
    
    // Set locations from the root of the API response
    if (eafStore.apiData?.locations) {
      mediaStore.setLocations(eafStore.apiData.locations);
      
      // Intelligent Media Selection
      let bestMatch = null;
      let minScore = Infinity;

      // 1. Identify Target Name from EAF Header
      const eafMedia = eafStore.eaf?.header?.media?.media?.[0];
      const targetUrl = eafMedia?.url || '';
      // Decode URI to handle spaces/special chars in filename and take basename
      const targetName = decodeURIComponent(targetUrl.split('/').pop() || '');

      // 2. Score candidates
      Object.entries(eafStore.apiData.locations).forEach(([filename, loc]) => {
        const isVideo = loc.mimetype?.startsWith('video/') || filename.toLowerCase().endsWith('.mp4');
        
        // Base Score: Levenshtein distance
        // If no target name in EAF, all have distance 0 (fallback to video preference)
        let score = targetName ? levenshtein(targetName, filename) : 0;
        
        // Preference: Video is preferred over Audio
        // We add a penalty to Audio so that if filenames are reasonably close, Video wins.
        if (!isVideo) score += 5;

        if (score < minScore) {
          minScore = score;
          bestMatch = { id: filename, ...loc };
        }
      });

      if (bestMatch) {
         mediaStore.currentMedia = bestMatch;
      } else if (eafStore.eaf?.header?.media?.media?.length > 0) {
        mediaStore.currentMedia = eafStore.eaf.header.media.media[0];
      }
      
      // Fallback: If no media is found, calculate max duration from annotations
      // so the timeline is still usable.
      if (!mediaStore.currentMedia) {
        let maxTime = 0;
        if (eafStore.eaf?.tiers) {
            Object.values(eafStore.eaf.tiers).forEach(tier => {
                Object.values(tier.annotations).forEach(ann => {
                      const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0;
                      if (end > maxTime) maxTime = end;
                });
            });
        }
        if (maxTime > 0) {
            mediaStore.duration = maxTime;
        }
      }
    } else if (eafStore.eaf?.header?.media?.media?.length > 0) {
      mediaStore.currentMedia = eafStore.eaf.header.media.media[0];
    }
    
    if (eafStore.eaf?.tiers && Object.keys(eafStore.eaf.tiers).length > 0) {
      const tierIds = Object.keys(eafStore.eaf.tiers);
      eafStore.setTier(tierIds[0]);
    }
  }
};

watch(() => props.url, loadViewer, { immediate: true });

const isVideoMedia = computed(() => {
  if (!mediaStore.currentMedia) return false;
  // If no media (null), it returns false -> White background (Correct for "No streaming media")
  
  const m = mediaStore.currentMedia;
  const mime = m.mimetype || '';
  const id = m.id || ''; // id is the filename
  
  // It is video if:
  // 1. Mime starts with video/
  // 2. OR filename/id implies video (mp4, mov, webm, ogv) AND NOT audio
  // Check explicit audio types to be safe
  const isAudio = mime.startsWith('audio/') || id.toLowerCase().endsWith('.mp3') || id.toLowerCase().endsWith('.wav');
  
  if (isAudio) return false;
  
  return mime.startsWith('video/') || 
         id.toLowerCase().endsWith('.mp4') || 
         id.toLowerCase().endsWith('.mov') ||
         id.toLowerCase().endsWith('.webm');
});
const fullscreenPlayerHeight = ref(65); // Percentage of VH
const playerHeight = ref(null);
const isResizing = ref(false);

const handleResizeStart = (e) => {
  isResizing.value = true;
  document.addEventListener('mousemove', handleResizeMove);
  document.addEventListener('mouseup', handleResizeEnd);
  // Prevent selection during drag
  document.body.style.userSelect = 'none';
};

const handleResizeMove = (e) => {
  if (!isResizing.value) return;
  
  if (isFullScreen.value) {
    const rawPercentage = (e.clientY / window.innerHeight) * 100;
    fullscreenPlayerHeight.value = Math.min(Math.max(rawPercentage, 20), 85);
    return;
  }
  const appTop = appRef.value?.getBoundingClientRect().top ?? 0;
  const availableHeight = Math.max(240, window.innerHeight - appTop);
  playerHeight.value = Math.min(Math.max(e.clientY - appTop, 120), availableHeight - 120);
};

const handleResizeEnd = () => {
  isResizing.value = false;
  document.removeEventListener('mousemove', handleResizeMove);
  document.removeEventListener('mouseup', handleResizeEnd);
  document.body.style.userSelect = '';
};

const fullscreenStyle = computed(() => {
  return {
    '--fav-fs-player-height': `${fullscreenPlayerHeight.value}vh`,
    '--fav-grid-font-size': `${eafStore.gridTierFontSize}px`,
    '--fav-text-font-size': `${eafStore.textTierFontSize}px`,
    '--fav-subtitle-font-size': `${eafStore.subtitleTierFontSize}px`,
    ...(playerHeight.value ? { '--fav-player-height': `${playerHeight.value}px` } : {})
  };
});
</script>

<template>
  <div 
    class="annotation-viewer-app" 
    :class="{ 
      'subtitle-active-view': eafStore.viewMode === 'subtitle',
      'is-resizing': isResizing,
      'has-custom-player-height': playerHeight !== null,
      'dark-mode': eafStore.darkMode
    }"
    ref="appRef"
    @mousemove="handleMouseMove"
    :style="fullscreenStyle"
    data-build-version="1.1.189"
  >
    <div v-if="eafStore.loading" class="overlay">Loading Annotation Data...</div>
    <div v-else-if="eafStore.apiData?.accessible === false" class="overlay access-denied">You do not have access to this file.</div>
    <div v-else-if="eafStore.error" class="overlay error">Error loading data.</div>
    
    <template v-else-if="eafStore.eaf">
      <div class="player-controls-section" :class="{ 'video-background': isVideoMedia }">
        <MediaPlayer />
        
        <!-- Resize Handle -->
        <div 
          class="resize-handle"
          @mousedown.prevent="handleResizeStart"
          title="Drag to resize media section"
        >
          <div class="resize-handle-bar"></div>
        </div>
        
        <!-- Subtitle View (Moved directly below player) -->
        <div v-if="eafStore.viewMode === 'subtitle'" class="subtitle-view-container">
          <div v-if="eafStore.subtitleTiers.length === 0" class="no-selection">
            Select up to 3 tiers from the controls above to display as subtitles.
          </div>
          <div v-else class="subtitle-stack">
            <div 
              v-for="tierId in eafStore.subtitleTiers" 
              :key="tierId" 
              class="subtitle-line"
            >
              <div class="subtitle-tier-name">{{ tierId }}</div>
              <div class="subtitle-wrapper">
                <div class="subtitle-content">
                  {{ getActiveSubtitleForTier(tierId) }}
                </div>
              </div>
              <div class="subtitle-spacer-right"></div>
            </div>
          </div>
        </div>

        <!-- Waveform + Spectrogram are now inside Timeline (timeline view only) -->

        <div class="controls-wrapper">
          <Controls
          @toggle-fullscreen="toggleFullScreen"
          @toggle-waveform="showWaveform = !showWaveform"
          @toggle-spectrogram="showSpectrogram = !showSpectrogram"
          :show-waveform="showWaveform"
          :show-spectrogram="showSpectrogram"
          :dark-mode="eafStore.darkMode"
        />
          <!-- v{{ version }} -->
          <!-- Black overlay for cinema mode fade-out -->
          <div 
            v-if="eafStore.viewMode === 'subtitle' && isFullScreen" 
            class="cinema-overlay" 
            :class="{ 'hidden': showControls }"
          ></div>
        </div>
      </div>
      
      <div class="viewer-section" :class="{ 'hide-ui': !showControls && isFullScreen }">
        <!-- Timeline View -->
        <div v-if="eafStore.viewMode === 'timeline'" class="timeline-wrapper">
          <Timeline
            :show-waveform="showWaveform"
            :show-spectrogram="showSpectrogram"
            :audio-url="mediaStore.currentMedia?.url || ''"
            :is-full-screen="isFullScreen"
          />
        </div>
        
        <!-- Grid View -->
        <div v-else-if="eafStore.viewMode === 'grid'" class="annotation-table-container">
          <table class="annotation-table">
            <thead>
              <tr>
                <th>#</th>
                <th style="width: auto;">Annotation</th>
                <th v-if="eafStore.showGridTimestamps" class="time-col">Start</th>
                <th v-if="eafStore.showGridTimestamps" class="time-col">End</th>
                <th v-if="eafStore.showGridTimestamps" class="time-col">Duration</th>
              </tr>
            </thead>
            <tbody>
              <tr 
                v-for="(ann, index) in Object.values(eafStore.currentTier?.annotations || {})" 
                :key="ann.id"
                class="table-row"
                :class="{ active: eafStore.activeAnnotationIds.includes(ann.id) }"
                @click="mediaStore.updateTime(ann.start ?? ann.referenced_annotation?.start)"
              >
                <td class="time">{{ index + 1 }}</td>
                <td class="text" style="width: auto;">{{ ann.value }}</td>
                <td v-if="eafStore.showGridTimestamps" class="time time-col">{{ ((ann.custom_start ?? ann.start ?? ann.referenced_annotation?.start ?? 0) / 1000).toFixed(3) }}</td>
                <td v-if="eafStore.showGridTimestamps" class="time time-col">{{ ((ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0) / 1000).toFixed(3) }}</td>
                <td v-if="eafStore.showGridTimestamps" class="time time-col">{{ (((ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0) - (ann.custom_start ?? ann.start ?? ann.referenced_annotation?.start ?? 0)) / 1000).toFixed(3) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Text View -->
        <div v-else-if="eafStore.viewMode === 'text'" class="text-view-container">
          <div :class="'columns-' + (eafStore.subtitleTiers?.length || 0)" style="display: flex; gap: 20px;">
            <div 
              v-for="(tierId, index) in eafStore.subtitleTiers.filter(t => t)" 
              :key="tierId"
              class="text-column"
              style="flex: 1; min-width: 0;"
            >
              <h4 class="column-title" :style="{ borderLeft: '4px solid ' + getTierColor(tierId), paddingLeft: '8px' }">{{ tierId }}</h4>
              <div class="text-flow">
                <template v-for="tier in (eafStore.eaf?.tiers ? Object.values(eafStore.eaf.tiers) : [])" :key="tier.id">
                  <template v-if="String(tier.id) === String(tierId)">
                    <span 
                      v-for="ann in Object.values(tier.annotations)" 
                      :key="ann.id"
                      class="text-token"
                      :class="{ active: eafStore.activeAnnotationIds.includes(ann.id) }"
                      :style="{ 
                        backgroundColor: eafStore.activeAnnotationIds.includes(ann.id) ? getTierColor(tierId, 0.2) : 'transparent',
                        borderColor: eafStore.activeAnnotationIds.includes(ann.id) ? getTierColor(tierId) : 'transparent'
                      }"
                      @click="mediaStore.updateTime(ann.start ?? ann.referenced_annotation?.start ?? 0)"
                    >
                      {{ ann.value }}
                    </span>
                  </template>
                </template>
              </div>
            </div>
          </div>
          <div v-if="!eafStore.subtitleTiers?.length" class="no-selection" style="color: #666; padding: 20px; text-align: center; width: 100%;">
            Select up to 3 tiers from the columns selectors above.
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
@media (max-width: 768px) {
  .time-col {
    display: none;
  }
}

:host {
  display: block;
  max-width: 100%;
  width: 100%;
  box-sizing: border-box;
}

.annotation-viewer-app {
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

.annotation-viewer-app.dark-mode {
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

/* Remove Title */
/* viewer-title removed: hidden by default in CSS */
/* display: none; */



.annotation-viewer-app:fullscreen {
  background: #000; /* Fully black background */
  width: 100vw;
  height: 100vh;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  color: #333;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  /* Pass CSS Variables to Child Components */
  --fav-player-max-height: 65vh; /* Reduced to leave space for 3 subtitle lines */
  --fav-player-max-width: 100%;
}

.annotation-viewer-app.subtitle-active-view:fullscreen .player-controls-section {
  background: #000; /* Ensure container is black */
  width: 100%;
  max-width: 100%; /* Truly full screen width */
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  justify-content: center;
  border: none; /* Remove white border */
}

/* Remove old :deep selector attempts */

.annotation-viewer-app.subtitle-active-view:fullscreen .subtitle-view-container {
  padding: 20px 40px;
}

.annotation-viewer-app.subtitle-active-view:fullscreen .subtitle-line {
  font-size: var(--fav-subtitle-font-size);
  height: 100px; /* Ultra-compact height for 2 lines */
  padding-top: 15px; /* Minimal top spacing (label at 4px) */
}

.annotation-viewer-app.subtitle-active-view:fullscreen .subtitle-tier-name {
  font-size: 12px;
}

.controls-wrapper {
  background: var(--fav-control-bg);
  position: relative;
  min-height: 40px;
}

.cinema-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 200px; /* Oversized to ensure bottom coverage */
  background: #000;
  opacity: 1;
  transition: opacity 0.5s ease;
  pointer-events: none;
  z-index: 100;
}

.app-version {
  position: absolute;
  bottom: 1px;
  right: 2px;
  font-size: 8px;
  color: #ddd;
  pointer-events: none;
  z-index: 5;
}

.cinema-overlay.hidden {
  opacity: 0;
}

/* Subtitle-specific Fullscreen adjustments */
.annotation-viewer-app.subtitle-active-view:fullscreen .player-controls-section,
.annotation-viewer-app.subtitle-active-view:fullscreen .controls-wrapper {
  background: var(--fav-bg);
  border-color: var(--fav-border-light);
}

/* The actual "fading" part of the player section should only happen via the overlay */
.annotation-viewer-app.subtitle-active-view:fullscreen .player-controls-section {
  background: #000;
}

.timeline-wrapper {
  width: 100%;
  max-width: 100%;
  min-width: 0;
}

/* Ensure data views keep white background column in fullscreen */
/* Viewer Section (Tables/Grid) - Keep constrained for readability */
.annotation-viewer-app:fullscreen:not(.subtitle-active-view) .viewer-section {
  background: var(--fav-bg);
  width: 100%;
  max-width: 100%; /* Allow full width background */
  margin: 0;
  flex: 1; /* Fill remaining vertical space naturally */
  display: flex; /* Ensure child centering works well */
  flex-direction: column;
  min-height: min-content; /* Allow expansion beyond flex:1 */
}

/* Constrain content within the white viewer section (except timeline) */
.annotation-viewer-app:fullscreen:not(.subtitle-active-view) .viewer-section > *:not(.timeline-wrapper) {
  max-width: 1200px;
  margin-left: auto;
  margin-right: auto;
}

.annotation-viewer-app:fullscreen:not(.subtitle-active-view) .timeline-wrapper {
  max-width: none;
  width: 100%;
}

.annotation-table-container {
  width: 100%;
  max-width: 100%;
  overflow-x: auto;
}

/* Player Section - ALWAYS full width and black in fullscreen */
.annotation-viewer-app:fullscreen .player-controls-section {
  width: 100% !important;
  background: #000 !important;
  border: none;
}

/* Force Player Container to fill the black void, ignoring aspect ratio constraints */
.annotation-viewer-app:fullscreen .player-container {
  aspect-ratio: auto !important;
  width: 100% !important;
  max-width: none !important;
  height: var(--fav-fs-player-height, 65vh) !important;
  max-height: none !important;
}

/* Reset any previous opacity hacks */
.hide-ui {
  opacity: 1 !important;
  pointer-events: auto;
}

.player-controls-section {
  background: var(--fav-player-bg); /* Default to theme for Audio / No Media */
  border-bottom: 2px solid var(--fav-border-light);
  width: 100%;
}

.player-controls-section.video-background {
  background: #000; /* Black only for Video */
}

.annotation-viewer-app:not(:fullscreen) .hide-ui {
  opacity: 1 !important;
  pointer-events: auto;
}

.annotation-table {
  width: 100%;
  border-collapse: collapse;
}

.annotation-table th {
  text-align: left;
  background: var(--fav-bg-header);
  color: var(--fav-text);
  padding: 8px 12px;
  border-bottom: 2px solid var(--fav-border);
  position: sticky;
  top: 0;
}

.annotation-table th:first-child,
.annotation-table td:first-child {
  width: 50px;
  min-width: 50px;
  text-align: center;
}

.annotation-table th.time-col,
.annotation-table td.time-col {
  width: 100px;
  min-width: 100px;
  text-align: right;
  white-space: nowrap;
}

.table-row {
  cursor: pointer;
  border-bottom: 1px solid var(--fav-border-light);
  color: var(--fav-text);
}

.table-row:hover { background: var(--fav-bg-alt); }

.table-row.active {
  background: var(--fav-bg-active);
  font-weight: bold;
}

.error, .access-denied {
  color: #d9534f;
  font-weight: bold;
}

.table-row td {
  padding: 11px 12px 9px 12px;
  vertical-align: middle;
  font-size: var(--fav-grid-font-size);
}

.time { color: var(--fav-text-muted); font-family: monospace; font-size: 12px; }

.text-view-container {
  padding: 20px;
  background: var(--fav-bg);
  min-height: 200px;
}

.text-view-container.columns-1 {
  display: block;
}

.text-view-container.columns-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40px;
}

.text-view-container.columns-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 30px;
}

.column-title {
  font-size: 12px;
  color: var(--fav-text-muted);
  text-transform: uppercase;
  margin-top: 0;
  margin-bottom: 10px;
  border-bottom: 1px solid var(--fav-border-light);
  padding-bottom: 5px;
}

.text-flow {
  line-height: 1.8;
  font-size: var(--fav-text-font-size);
  color: var(--fav-text);
}

.text-token {
  cursor: pointer;
  padding: 0 4px;
  border-radius: 3px;
  transition: background 0.2s, color 0.2s;
  margin-right: 0px;
  display: inline-block;
  border: 1px solid transparent; /* Reserve space */
}

.text-token:hover {
  background: var(--fav-bg-alt);
}

.text-token.active {
  background: var(--fav-bg-active);
  color: inherit;
  border-color: var(--fav-primary);
}

.subtitle-view-container {
  padding: 10px 15px;
  box-sizing: border-box;
  background: #000;
  min-height: auto; /* Height adapts to content */
  display: flex;
  justify-content: center;
  align-items: center;
  text-align: left;
}

.subtitle-stack {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.subtitle-line {
  color: #fff;
  font-size: var(--fav-subtitle-font-size);
  font-weight: 500;
  text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  height: 86px;
  overflow: hidden;
  position: relative;
  padding-top: 18px; /* Fixed header space */
  box-sizing: border-box;
}

.subtitle-tier-name {
  position: absolute;
  top: 4px;
  left: 0;
  font-size: 10px;
  color: #bbb;
  text-transform: uppercase;
  letter-spacing: 1px;
  text-align: left;
  width: 100%;
  padding-left: 10px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  z-index: 1;
}

.subtitle-wrapper {
  flex: 1; /* Fill remaining height */
  width: 100%;
  display: flex;
  align-items: center; /* Center child vertically */
  justify-content: center; /* Center child horizontally */
  overflow: hidden;
  min-height: 0; /* Allow flex shrinking */
}

.subtitle-content {
  width: 95%;
  text-align: center;
  white-space: normal;
  word-wrap: break-word;
  line-height: 1.25;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  /* margin removed, let wrapper handle centering */
}

.subtitle-spacer-right {
  display: none;
}

.no-selection {
  color: #666;
  font-style: italic;
}
</style>


<style>
/* RESTORED: Styles for Web Component Shadow DOM */
/* RESTORED: Styles for Web Component Shadow DOM */
.resize-handle {
  width: 100%;
  height: 12px; /* Maintain decent hit area */
  background: transparent; /* Invisible container */
  border: none; /* Remove heavy borders */
  cursor: row-resize;
  display: none; /* Hidden by default */
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
  z-index: 1000;
  position: relative;
  flex-shrink: 0;
}

/* Ensure handle is visible and consumes space in fullscreen */
.resize-handle {
  display: flex !important;
}

.annotation-viewer-app:not(:fullscreen).has-custom-player-height .player-container {
  height: var(--fav-player-height) !important;
  max-height: none !important;
  aspect-ratio: auto;
}

.resize-handle:hover,
.annotation-viewer-app.is-resizing .resize-handle {
  background: rgba(255, 255, 255, 0.1); /* Subtle highlight on interact */
}

.resize-handle-bar {
  width: 60px; /* Slightly wider grip */
  height: 2px; /* Very thin visual indicator */
  background: #aaa; /* Lighter for visibility on black */
  border-radius: 1px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.5); /* Add depth since we removed borders */
}

/* 
  Global overrides within Shadow DOM 
  These apply to elements inside the shadow root matching :fullscreen 
*/
.annotation-viewer-app:fullscreen .player-container {
  /* Use CSS variable linked to state, default to 65vh if not set */
  max-height: var(--fav-fs-player-height, 65vh) !important;
  height: var(--fav-fs-player-height, 65vh) !important; /* Force height to match max-height */
  width: 100% !important;
  background: #000 !important;
  transition: height 0.05s linear, max-height 0.05s linear;
}

/* Disable transition during drag for responsiveness */
.annotation-viewer-app.is-resizing:fullscreen .player-container {
  transition: none;
}
</style>
