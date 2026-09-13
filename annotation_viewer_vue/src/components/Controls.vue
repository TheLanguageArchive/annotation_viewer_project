<script setup>
import { computed, ref, onMounted } from 'vue';
import { useEafStore, useMediaStore } from '../stores';

const eafStore = useEafStore();
const mediaStore = useMediaStore();
const isTierSelectorOpen = ref(false);

// Tiers in original order
const tiersList = computed(() => {
  if (!eafStore.eaf?.tiers) return [];
  return Object.values(eafStore.eaf.tiers);
});

// Media sources (Audio/Video children from Drupal)
const mediaSources = computed(() => {
  if (!eafStore.apiData?.locations) return [];
  return Object.entries(eafStore.apiData.locations).map(([name, data]) => ({
    id: name, // Using name (filename) as ID
    filename: data.label || name,
    url: data.url
  }));
});

const onTierChange = (event) => {
  eafStore.setTier(event.target.value);
};

const onMediaChange = (event) => {
  const selectedMediaId = event.target.value;
  const selectedMedia = mediaSources.value.find(m => String(m.filename) === String(selectedMediaId));
  if (selectedMedia) {
    mediaStore.currentMedia = selectedMedia;
  }
};

const onZoomChange = (event) => {
  eafStore.setZoom(parseInt(event.target.value));
};

const fontSizeOptions = computed(() => {
  return eafStore.viewMode === 'subtitle'
    ? [16, 18, 20, 22, 24, 28, 32, 36, 40, 48]
    : [9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32];
});

const currentViewFontSize = computed(() => {
  return eafStore[`${eafStore.viewMode}TierFontSize`];
});

const onViewFontSizeChange = (event) => {
  eafStore.setViewFontSize(eafStore.viewMode, parseInt(event.target.value));
};

const onTimelineTierToggle = (tierId) => {
  eafStore.toggleTimelineTier(tierId);
};

const onToggleAllTiers = () => {
  eafStore.toggleAllTimelineTiers();
};

const onSubtitleTierSelect = (index, tierId) => {
  // Clone current list
  const currentTiers = [...eafStore.subtitleTiers];
  
  // Specific check for empty string or "undefined" string
  if (!tierId || tierId === "" || tierId === "undefined" || tierId === "null") {
    // If it was the last item, remove it. 
    // If it was in the middle, we might want to remove it and shift others, 
    // OR just remove it from the set.
    // currentTiers[index] = null; 
    
    // Actually, let's just nullify it at that index
    currentTiers[index] = null;
  } else {
    currentTiers[index] = tierId;
  }

  // Final list: unique, non-null IDs only
  const cleaned = currentTiers.filter((val, i, self) => val && self.indexOf(val) === i);
  
  eafStore.setSubtitleTiers(cleaned);
};

const getAvailableTiers = (currentIndex) => {
  // Get currently selected tiers in OTHER slots
  const currentId = eafStore.subtitleTiers[currentIndex];
  const otherSelected = eafStore.subtitleTiers.filter((id, idx) => idx !== currentIndex && id);
  
  // Return tiers that are EITHER:
  // 1. Not selected anywhere else
  // 2. OR the one currently selected in this slot (so it stays in the list)
  return tiersList.value.filter(tier => 
    !otherSelected.includes(tier.id) || tier.id === currentId
  );
};

// Close dropdown on click outside
onMounted(() => {
  window.addEventListener('click', () => {
    isTierSelectorOpen.value = false;
  });
});

defineEmits(['toggle-fullscreen', 'toggle-waveform', 'toggle-spectrogram']);

const props2 = defineProps({
  showWaveform: { type: Boolean, default: false },
  showSpectrogram: { type: Boolean, default: false },
  darkMode: { type: Boolean, default: false },
});
</script>

<template>
  <div class="controls-container" :class="{ 'is-dark': props2.darkMode }">
    <!-- Left Group: Media Selection -->
    <div class="control-group left">
      <label for="media-select">Source:</label>
      <select 
        id="media-select" 
        :value="mediaStore.currentMedia?.id || mediaStore.currentMedia?.filename"
        @change="onMediaChange"
      >
        <option v-for="media in mediaSources" :key="media.id" :value="media.id">
          {{ media.filename }}
        </option>
      </select>
    </div>

    <!-- Middle Group: View Mode Selection -->
    <div class="control-group center">
      <label for="view-mode-select">View:</label>
      <select 
        id="view-mode-select" 
        v-model="eafStore.viewMode"
      >
        <option value="timeline">Timeline</option>
        <option value="grid">Grid</option>
        <option value="text">Text</option>
        <option value="subtitle">Subtitle</option>
      </select>
      <button class="fullscreen-btn" @click="$emit('toggle-fullscreen')" title="Toggle Fullscreen">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
          <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
        </svg>
      </button>

      <!-- Waveform toggle (timeline view only) -->
      <button
        v-if="eafStore.viewMode === 'timeline'"
        class="fullscreen-btn waveform-toggle-btn"
        :class="{ active: props2.showWaveform }"
        :disabled="!mediaStore.currentMedia"
        :title="mediaStore.currentMedia ? 'Toggle Waveform' : 'No streaming media file available or accessible'"
        @click="$emit('toggle-waveform')"
      >
        <svg viewBox="0 0 24 24" width="24" height="18" fill="currentColor">
          <path d="M1 12 C2 12 2.5 9 4.5 9 C6.5 9 6.5 12 8.5 12 C9.5 12 9.5 3 11.5 3 C13.5 3 13.5 10 15.5 10 C16.5 10 16.5 7 18.5 7 C20.5 7 21.5 12 23 12 L23 12 C21.5 12 20.5 17 18.5 17 C16.5 17 16.5 14 15.5 14 C13.5 14 13.5 21 11.5 21 C9.5 21 9.5 13 8.5 13 C6.5 13 6.5 16 4.5 16 C2.5 16 2 12 1 12 Z" />
        </svg>
      </button>

      <!-- Spectrogram toggle (timeline view only) -->
      <button
        v-if="eafStore.viewMode === 'timeline'"
        class="fullscreen-btn spectrogram-toggle-btn"
        :class="{ active: props2.showSpectrogram }"
        :disabled="!mediaStore.currentMedia"
        :title="mediaStore.currentMedia ? 'Toggle Spectrogram' : 'No streaming media file available or accessible'"
        @click="$emit('toggle-spectrogram')"
      >
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
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

      <!-- Play Segment button (timeline view only) -->
      <button
        v-if="eafStore.viewMode === 'timeline' && mediaStore.selectionStart !== null && mediaStore.selectionStart !== mediaStore.selectionEnd"
        class="fullscreen-btn segment-play-btn active"
        @click="mediaStore.playSegment()"
        title="Play Selected Segment"
      >
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
          <path d="M8 5v14l11-7z"/>
          <rect x="3" y="4" width="2" height="16" rx="1" fill="currentColor" fill-opacity="0.6"/>
          <rect x="20" y="4" width="2" height="16" rx="1" fill="currentColor" fill-opacity="0.6"/>
        </svg>
      </button>
      
      <!-- Playback Speed -->
      <div class="control-group speed-group">
        <label for="speed-select" class="speed-label">Speed:</label>
        <select 
          id="speed-select" 
          :value="mediaStore.playbackRate" 
          @change="(e) => mediaStore.setPlaybackRate(parseFloat(e.target.value))"
          class="speed-select"
        >
          <option value="0.25">0.25x</option>
          <option value="0.5">0.5x</option>
          <option value="0.75">0.75x</option>
          <option value="1">1x</option>
          <option value="1.25">1.25x</option>
          <option value="1.5">1.5x</option>
          <option value="2">2x</option>
        </select>
      </div>
    </div>

    <!-- Contextual Controls -->
    <div class="control-group right">
      <!-- Zoom & Tier Selection (For Timeline View) -->
      <template v-if="eafStore.viewMode === 'timeline'">
        <div class="timeline-controls">
          <div class="tier-multiselect" @click.stop>
            <label>Tiers:</label>
            <div class="multiselect-trigger" @click="isTierSelectorOpen = !isTierSelectorOpen">
              {{ tiersList.length - eafStore.hiddenTimelineTiers.length }} / {{ tiersList.length }}
            </div>
            <div v-if="isTierSelectorOpen" class="multiselect-dropdown">
              <div 
                class="multiselect-item all-option"
                @click="onToggleAllTiers"
                style="border-bottom: 1px solid #eee; font-weight: bold;"
              >
                <input type="checkbox" :checked="eafStore.hiddenTimelineTiers.length === 0" readonly>
                <span>- All -</span>
              </div>
              <div 
                v-for="tier in tiersList" 
                :key="tier.id" 
                class="multiselect-item"
                @click="onTimelineTierToggle(tier.id)"
              >
                <input type="checkbox" :checked="!eafStore.hiddenTimelineTiers.includes(String(tier.id))" readonly>
                <span>{{ tier.id }}</span>
              </div>
            </div>
          </div>
          <div class="zoom-wrap">
            <label>Zoom:</label>
            <input 
              type="range" 
              min="10" 
              max="500" 
              step="10" 
              :value="eafStore.pixelsPerSecond" 
              :style="{ '--progress': `${((eafStore.pixelsPerSecond - 10) / (500 - 10)) * 100}%` }"
              @input="onZoomChange"
            >
          </div>
        </div>
      </template>

      <!-- Tier Selection (For Grid View) -->
      <template v-else-if="eafStore.viewMode === 'grid'">
        <div class="grid-controls">
          <label class="checkbox-label timestamp-toggle">
            <input 
              type="checkbox" 
              :checked="eafStore.showGridTimestamps" 
              @change="eafStore.toggleGridTimestamps"
            >
            <span>Timestamps</span>
          </label>
          <label for="tier-select">Tier:</label>
          <select 
            id="tier-select" 
            :value="eafStore.currentTier?.id" 
            @change="onTierChange"
          >
            <option v-for="tier in tiersList" :key="tier.id" :value="tier.id">
              {{ tier.id }}
            </option>
          </select>
        </div>
      </template>

      <!-- Multi-Tier Selection (For Subtitle or Text View) -->
      <template v-else-if="eafStore.viewMode === 'subtitle' || eafStore.viewMode === 'text'">
        <div class="subtitle-selectors">
          <label>{{ eafStore.viewMode === 'text' ? 'Columns:' : 'Subtitles:' }}</label>
          <div class="subtitle-dropdowns">
            <select 
              v-for="n in 3" 
              :key="n"
              :value="eafStore.subtitleTiers[n-1] || ''" 
              @change="(e) => onSubtitleTierSelect(n-1, e.target.value)"
            >
              <option value="">- {{ eafStore.subtitleTiers[n-1] ? 'Remove' : 'Select' }} -</option>
              <option v-for="tier in getAvailableTiers(n-1)" :key="tier.id" :value="tier.id">
                {{ tier.id }}
              </option>
            </select>
          </div>
        </div>
      </template>

      <div class="tier-font-size-wrap">
        <label for="timeline-tier-font-size">Font size:</label>
        <select id="timeline-tier-font-size" :value="currentViewFontSize" @change="onViewFontSizeChange">
          <option v-for="size in fontSizeOptions" :key="size" :value="size">{{ size }}</option>
        </select>
      </div>
    </div>
  </div>
</template>

<style scoped>
@media (max-width: 768px) {
  .timestamp-toggle {
    display: none !important;
  }
}

.controls-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 15px;
  box-sizing: border-box;
  background: var(--fav-bg);
  color: var(--fav-text);
  flex-wrap: wrap; /* Dynamic wrapping based on content size */
  gap: 15px;
}
.control-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 0 1 auto; /* Grow/shrink based on content, no forced width */
}
.control-group.left { justify-content: flex-start; }
.control-group.center { justify-content: center; }
.control-group.right { justify-content: flex-end; }

/* Remove fixed breakpoints that force layout changes */
@media (max-width: 480px) {
  .control-group {
    width: 100%;
    justify-content: space-between;
  }
}

.grid-controls {
  display: flex;
  align-items: center;
  gap: 15px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
}

.checkbox-label input {
  margin: 0;
  cursor: pointer;
}

.subtitle-selectors {
  display: flex;
  align-items: center;
  gap: 10px;
}

.tier-selections {
  display: flex;
  gap: 15px;
}

.tier-select-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}

.subtitle-dropdowns {
  display: flex;
  gap: 5px;
}

.subtitle-dropdowns select {
  max-width: 120px;
}

.timeline-controls {
  display: flex;
  align-items: center;
  gap: 20px;
}

.tier-font-size-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tier-multiselect {
  display: flex;
  align-items: center;
  gap: 8px;
  position: relative;
}

.multiselect-trigger {
  border: 1px solid var(--fav-border);
  border-radius: 4px;
  padding: 0 24px 0 10px;
  font-size: 11px;
  background: var(--fav-bg) no-repeat right 8px center;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(0,0,0,0.5)' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-size: 12px;
  cursor: pointer;
  min-width: 100px;
  display: flex;
  justify-content: flex-start;
  align-items: center;
  color: var(--fav-text);
  height: 26px;
  box-sizing: border-box;
}

.controls-container.is-dark .multiselect-trigger,
.controls-container.is-dark select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.6)' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
}

.multiselect-dropdown {
  position: absolute;
  bottom: 100%;
  right: 0;
  margin-bottom: 5px;
  max-height: 250px;
  overflow-y: auto;
  border: 1px solid var(--fav-border);
  border-radius: 4px;
  background: var(--fav-bg);
  padding: 4px;
  min-width: 150px;
  z-index: 1000;
  box-shadow: 0 -4px 10px rgba(0,0,0,0.3);
  color: var(--fav-text);
}

.multiselect-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
  cursor: pointer;
  font-size: 11px;
}

.multiselect-item:hover {
  background: #f5f5f5;
}

.zoom-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}

.controls-container select {
  appearance: none;
  background: var(--fav-bg) no-repeat right 8px center;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(0,0,0,0.5)' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-size: 12px;
  color: var(--fav-text);
  border: 1px solid var(--fav-border);
  padding: 0 28px 0 8px;
  border-radius: 4px;
  height: 26px;
  box-sizing: border-box;
  font-size: 12px;
  cursor: pointer;
}

label {
  font-size: 13px;
  font-weight: bold;
}
input[type="range"] {
  width: 80px;
  height: 20px;
  -webkit-appearance: none;
  appearance: none;
  background-color: transparent !important;
  outline: none;
}

input[type="range"]::-webkit-slider-runnable-track {
  height: 6px;
  border-radius: 3px;
  margin: 7px 9px;
  background-image: linear-gradient(to right, var(--fav-primary) 0%, var(--fav-primary) var(--progress, 0%), var(--fav-range-track) var(--progress, 0%), var(--fav-range-track) 100%) !important;
  background-size: 100% 100% !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
}

input[type="range"]::-webkit-slider-thumb {
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

input[type="range"]::-moz-range-track {
  height: 6px;
  border-radius: 3px;
  margin: 7px 9px;
  background-image: linear-gradient(to right, var(--fav-primary) 0%, var(--fav-primary) var(--progress, 0%), var(--fav-range-track) var(--progress, 0%), var(--fav-range-track) 100%) !important;
  background-size: 100% 100% !important;
}

input[type="range"]::-moz-range-thumb {
  width: 18px;
  height: 18px;
  background: #fff;
  border: 1px solid var(--fav-border);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
}

.fullscreen-btn {
  background: none;
  border: 1px solid var(--fav-border);
  border-radius: 4px;
  padding: 0;
  cursor: pointer;
  color: var(--fav-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  margin-left: 5px;
  height: 26px;
  width: 26px;
  box-sizing: border-box;
}

.fullscreen-btn:hover {
  background: var(--fav-bg-alt);
  color: var(--fav-text);
  border-color: var(--fav-text-muted);
}

.fullscreen-btn:disabled,
.fullscreen-btn:disabled:hover {
  opacity: 0.4;
  cursor: not-allowed;
  background: none;
  color: var(--fav-text-muted);
  border-color: var(--fav-border);
}

.waveform-toggle-btn.active,
.spectrogram-toggle-btn.active {
  background: var(--fav-bg-active);
  color: var(--fav-primary);
  border-color: var(--fav-primary);
}
</style>
