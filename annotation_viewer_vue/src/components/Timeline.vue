<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import draggable from 'vuedraggable';
import { useEafStore, useMediaStore } from '../stores';
import AudioWaveform from './AudioWaveform.vue';
import Spectrogram from './Spectrogram.vue';
import TimelinePlayhead from './TimelinePlayhead.vue';

const eafStore = useEafStore();
const mediaStore = useMediaStore();

const props = defineProps({
  showWaveform:    { type: Boolean, default: false },
  showSpectrogram: { type: Boolean, default: false },
  audioUrl:        { type: String, default: '' },
  isFullScreen:    { type: Boolean, default: false },
});

const containerRef = ref(null);
const tracksRef = ref(null);
const isUserScrolling = ref(false);
const hoveredAnnotation = ref(null);
const mouseX = ref(0);
const mouseY = ref(0);
let scrollTimeout = null;
const tierOverlayElements = new Map();
let activeAnnotationIdSet = new Set(eafStore.activeAnnotationIds.map(String));

const tierId = (tier) => String(tier?.id ?? tier);

const annotationById = computed(() => {
  const annotations = new Map();
  tiersList.value.forEach((tier) => {
    Object.values(tier.annotations || {}).forEach((annotation) => {
      annotations.set(String(annotation.id), { annotation, tierId: tierId(tier) });
    });
  });
  return annotations;
});

const setTierOverlayElement = (element, tier, slot) => {
  const id = tierId(tier);
  if (element) {
    const overlays = tierOverlayElements.get(id) || [];
    overlays[slot] = element;
    tierOverlayElements.set(id, overlays);
  } else {
    const overlays = tierOverlayElements.get(id);
    if (overlays) overlays[slot] = null;
  }
};

// Allocate only as many highlight layers as a tier can need concurrently.
// Keeping this small pool mounted avoids creating a compositing layer at the
// first annotation boundary during playback (noticeable in Safari), while
// still supporting overlapping annotations in the same tier.
const getTierOverlaySlotCount = (tier) => {
  const events = [];
  Object.values(tier?.annotations || {}).forEach((ann) => {
    const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.custom_start ?? ann.referenced_annotation?.start;
    const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.custom_end ?? ann.referenced_annotation?.end;
    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return;
    events.push({ time: start, delta: 1 });
    events.push({ time: end, delta: -1 });
  });
  events.sort((a, b) => a.time - b.time || a.delta - b.delta);

  let active = 0;
  let maximum = 1;
  events.forEach((event) => {
    active += event.delta;
    maximum = Math.max(maximum, active);
  });
  return maximum;
};

const syncActiveOverlays = (activeIds) => {
  tierOverlayElements.forEach((overlays) => {
    overlays.forEach((overlay) => {
      if (overlay) overlay.style.opacity = '0.001';
    });
  });

  const activeByTier = new Map();
  activeIds.forEach((annotationId) => {
    const metadata = annotationById.value.get(String(annotationId));
    if (!metadata) return;
    const annotations = activeByTier.get(metadata.tierId) || [];
    annotations.push(metadata.annotation);
    activeByTier.set(metadata.tierId, annotations);
  });

  activeByTier.forEach((annotations, id) => {
    const overlays = tierOverlayElements.get(id) || [];
    annotations.forEach((ann, slot) => {
      const overlay = overlays[slot];
      if (!overlay) return;

      const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.custom_start ?? ann.referenced_annotation?.start ?? 0;
      const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.custom_end ?? ann.referenced_annotation?.end ?? start;
      const left = (start / 1000) * eafStore.pixelsPerSecond;
      const width = Math.max(0, ((end - start) / 1000) * eafStore.pixelsPerSecond);
      overlay.style.transform = `translate3d(${left}px, 0, 0) scaleX(${width / 10})`;
      overlay.style.opacity = '1';
    });
  });
};

watch(() => eafStore.activeAnnotationIds, (activeIds) => {
  const nextActiveIds = new Set(activeIds.map(String));
  syncActiveOverlays(nextActiveIds);
  activeAnnotationIdSet = nextActiveIds;
});

const handleMouseMoveTooltip = (e) => {
  mouseX.value = e.clientX;
  mouseY.value = e.clientY;
};

// Tiers filtered by visibility and sorted by timelineTierOrder
const tiersList = computed(() => {
  const tiersMap = eafStore.eaf?.tiers || {};
  return eafStore.timelineTierOrder
    .filter(id => !eafStore.hiddenTimelineTiers.includes(String(id)))
    .map(id => {
      const tier = tiersMap[id];
      if (tier) return tier;
      return { id: String(id), annotations: {} };
    });
});

// A computed property specifically for the draggable labels
const draggableLabels = computed({
  get: () => {
    const tiersMap = eafStore.eaf?.tiers || {};
    return eafStore.timelineTierOrder
      .filter(id => !eafStore.hiddenTimelineTiers.includes(String(id)))
      .map(id => {
        const tier = tiersMap[id];
        return { 
          idStr: id, 
          label: tier?.id || id 
        };
      });
  },
  set: (newItems) => {
    const visibleIds = newItems.map(item => item.idStr);
    const hiddenIds = eafStore.timelineTierOrder.filter(id => eafStore.hiddenTimelineTiers.includes(String(id)));
    eafStore.setTimelineTierOrder([...visibleIds, ...hiddenIds]);
  }
});

const timelineWidth = computed(() => {
  const durationS = mediaStore.duration / 1000;
  return durationS * eafStore.pixelsPerSecond;
});

// Time markers for the ruler with adaptive subdivisions
const timeMarkers = computed(() => {
  const markers = [];
  const durationS = mediaStore.duration / 1000;
  
  let majorInterval = 10;
  if (eafStore.pixelsPerSecond > 200) majorInterval = 1;
  else if (eafStore.pixelsPerSecond > 100) majorInterval = 2;
  else if (eafStore.pixelsPerSecond > 50) majorInterval = 5;
  
  const minorInterval = majorInterval / 10;
  
  for (let s = 0; s <= durationS; s += minorInterval) {
    const roundedS = Math.round(s * 1000) / 1000;
    const isMajor = Math.abs(roundedS % majorInterval) < 0.001 || Math.abs((roundedS % majorInterval) - majorInterval) < 0.001;
    markers.push({
      time: roundedS,
      left: roundedS * eafStore.pixelsPerSecond,
      label: isMajor ? formatTime(roundedS) : null,
      type: isMajor ? 'major' : 'minor'
    });
  }
  return markers;
});

const formatTime = (seconds) => {
  const totalMs = Math.round(seconds * 1000);
  const h = Math.floor(totalMs / 3600000);
  const m = Math.floor((totalMs % 3600000) / 60000);
  const s = Math.floor((totalMs % 60000) / 1000);
  const ms = totalMs % 1000;
  
  const hStr = h.toString().padStart(2, '0');
  const mStr = m.toString().padStart(2, '0');
  const sStr = s.toString().padStart(2, '0');
  const msStr = ms.toString().padStart(3, '0');
  
  return `${hStr}:${mStr}:${sStr}.${msStr}`;
};

const playheadLeft = computed(() => {
  return (mediaStore.currentTime / 1000) * eafStore.pixelsPerSecond;
});

// Render annotations once when the data is loaded. Recalculating a virtualized
// subset on every programmatic scroll caused Safari to mount/unmount annotation
// nodes during playback, producing visible main-thread stalls.
const getVisibleAnnotations = (tier) => {
  const tierObj = typeof tier === 'string' ? (eafStore.eaf?.tiers?.[tier]) : tier;
  if (!tierObj || !tierObj.annotations) return [];
  return Object.values(tierObj.annotations);
};

const handleScroll = () => {
  if (tracksRef.value) {
    isUserScrolling.value = true;
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
      isUserScrolling.value = false;
    }, 2000);
  }
};

const scrollTimeline = (force = false) => {
  if (tracksRef.value && (!isUserScrolling.value || force)) {
    // If not playing and not forced, don't scroll
    if (!mediaStore.isPlaying && !force) return;

    const containerWidth = tracksRef.value.offsetWidth;
    const centerOffset = containerWidth / 2;
    const targetScroll = playheadLeft.value - centerOffset;
    const currentScroll = tracksRef.value.scrollLeft;

    // 1. Determine if playhead is off-screen (with a small 20px buffer)
    const isVisible = playheadLeft.value >= currentScroll - 20 && 
                      playheadLeft.value <= currentScroll + containerWidth + 20;

    // Only scroll if:
    // a) It is a "force" call AND the playhead is currently OFF-SCREEN
    // b) It is a regular call AND the playhead is OFF-SCREEN
    // c) It is a regular call AND it's already centered (smooth follow)
    
    if (force) {
      if (!isVisible) {
        tracksRef.value.scrollLeft = targetScroll;
      }
      return;
    }

    // Determine if it's already centered (within 2px)
    const isCentered = Math.abs(currentScroll - targetScroll) < 2;

    if (!isVisible || isCentered) {
      tracksRef.value.scrollLeft = targetScroll;
    }
    // Otherwise, do nothing - let the playhead move across the screen "from where it was"
  }
};

// Handle jumps from external components (e.g. video progress bar)
watch(() => mediaStore.currentTime, (newTime, oldTime) => {
  if (Math.abs(newTime - oldTime) > 200) {
    scrollTimeline(true);
  }
});

let animationFrame;
const watchTime = () => {
  scrollTimeline();
  animationFrame = requestAnimationFrame(watchTime);
};

onMounted(() => {
  syncActiveOverlays(activeAnnotationIdSet);
  // Force the tiny, transparent inactive overlays through layout while the
  // page is idle so Safari does not initialize them during playback.
  tierOverlayElements.forEach((overlays) => {
    overlays.forEach((overlay) => overlay?.getBoundingClientRect());
  });
  animationFrame = requestAnimationFrame(watchTime);
});

watch(() => eafStore.pixelsPerSecond, () => {
  syncActiveOverlays(activeAnnotationIdSet);
});

onUnmounted(() => {
  cancelAnimationFrame(animationFrame);
});

const getAnnotationStyle = (ann, tierId) => {
  const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.custom_start ?? ann.referenced_annotation?.start ?? 0;
  const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.custom_end ?? ann.referenced_annotation?.end ?? 0;
  const duration = end - start;
  
  return {
    left: `${(start / 1000) * eafStore.pixelsPerSecond}px`,
    width: `${((duration / 1000) * eafStore.pixelsPerSecond)}px`, // Removed 1px gap to match playhead stop precisely
    backgroundColor: getTierColor(tierId, 0.15),
    '--annotation-active-bg': getTierColor(tierId, 0.4),
    borderColor: getTierColor(tierId, 0.8),
    boxSizing: 'border-box'
  };
};

const getTierColor = (tierId, opacity = 1) => {
  const palette = [
    '#2980B9', '#D35400', '#27AE60', '#8E44AD', '#F39C12',
    '#1ABC9C', '#C0392B', '#7F8C8D', '#C71585', '#6B8E23',
    '#4682B4', '#D2691E', '#008080', '#DC143C', '#1E90FF',
    '#B8860B', '#3CB371', '#9932CC', '#FF6347', '#5F9EA0',
    '#A0522D', '#32CD32', '#6A5ACD', '#CD5C5C', '#34495E'
  ];
  // Robust ID extraction
  let idStr = '';
  if (tierId && typeof tierId === 'object' && tierId.id) {
    idStr = String(tierId.id);
  } else {
    // If it's a numeric key, try to resolve the tier object's ID
    const tier = eafStore.eaf?.tiers?.[tierId];
    idStr = tier?.id ? String(tier.id) : String(tierId);
  }
  
  const tierIndex = eafStore.tierOrder.indexOf(idStr);
  const colorIndex = tierIndex !== -1 ? (tierIndex % palette.length) : 0;
  const hex = palette[colorIndex];
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${opacity})`;
};

const isDraggingSelection = ref(false);
const dragStartX = ref(0);

const onTrackMouseDown = (event) => {
  if (event.target.closest('.annotation-block')) return;
  const rect = event.currentTarget.getBoundingClientRect();
  dragStartX.value = event.clientX - rect.left;
  isDraggingSelection.value = true;
  if (rect.width > 0) {
    const startMs = (dragStartX.value / rect.width) * mediaStore.duration;
    mediaStore.setSelection(startMs, startMs);
  }
};

const onTrackMouseMove = (event) => {
  if (!isDraggingSelection.value) return;
  const rect = event.currentTarget.getBoundingClientRect();
  let currentX = event.clientX - rect.left;
  currentX = Math.max(0, Math.min(rect.width, currentX));
  if (rect.width > 0) {
    const startMs = (dragStartX.value / rect.width) * mediaStore.duration;
    const currentMs = (currentX / rect.width) * mediaStore.duration;
    mediaStore.setSelection(startMs, currentMs);
  }
};

const onTrackMouseUp = (event) => {
  if (!isDraggingSelection.value) return;
  isDraggingSelection.value = false;
  
  const rect = event.currentTarget.getBoundingClientRect();
  let currentX = event.clientX - rect.left;
  if (currentX < 0) currentX = 0;
  if (currentX > rect.width) currentX = rect.width;
  
  if (Math.abs(currentX - dragStartX.value) < 5) {
    mediaStore.clearSelection();
    if (rect.width > 0) {
      const percentage = currentX / rect.width;
      const timeMs = Math.max(0, Math.min(percentage * mediaStore.duration, mediaStore.duration));
      mediaStore.updateTime(timeMs);
    }
    scrollTimeline(true);
  }
};

const jumpToAnnotation = (ann, event) => {
  event.stopPropagation();
  event.preventDefault();
  const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.start ?? 0;
  const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.end ?? 0;
  mediaStore.setSelection(start, end);
  mediaStore.updateTime(start);
  scrollTimeline(true);
};

const selectTier = (id) => {
  eafStore.setTier(id);
};

// Resizing logic removed - 50/50 split enforced in Fullscreen

</script>

<template>
  <div class="timeline-container" ref="containerRef" :style="{ '--timeline-tier-font-size': eafStore.timelineTierFontSize + 'px' }">
    <!-- Tier Labels Sidebar -->
    <div class="tier-labels">
      <div class="ruler-corner"></div>
      <div 
        v-if="props.showWaveform" 
        class="viz-label waveform-label" 
        :style="props.isFullScreen ? { flex: '1 1 0%', height: 'auto' } : { height: eafStore.waveformHeight + 'px' }"
      >Waveform</div>
      <div 
        v-if="props.showSpectrogram" 
        class="viz-label spectrogram-label" 
        :style="props.isFullScreen ? { flex: '0 0 auto', height: eafStore.spectrogramHeight + 'px' } : { height: eafStore.spectrogramHeight + 'px' }"
      >Spectrogram</div>
      <draggable 
        v-model="draggableLabels" 
        item-key="idStr"
        class="draggable-tier-list"
        handle=".tier-label"
      >
        <template #item="{element}">
          <div 
            class="tier-label"
            :class="{ active: eafStore.currentTier?.id === element.idStr }"
            :style="{ borderLeft: '4px solid ' + getTierColor(element.idStr) }"
            title="Click to select, Drag to reorder"
            @click="selectTier(element.idStr)"
          >
            {{ element.label }}
          </div>
        </template>
      </draggable>
    </div>

    <!-- Scrolling Area -->
    <div class="tracks-container" ref="tracksRef" @scroll="handleScroll">
      <!-- Time Ruler (Clickable) -->
      <div 
        class="time-ruler" 
        :style="{ width: timelineWidth + 'px' }" 
        @mousedown="onTrackMouseDown"
        @mousemove="onTrackMouseMove"
        @mouseup="onTrackMouseUp"
        @mouseleave="onTrackMouseUp"
      >
        <div 
          v-for="marker in timeMarkers" 
          :key="marker.time" 
          class="time-marker"
          :class="marker.type"
          :style="{ left: marker.left + 'px' }"
        >
          <span v-if="marker.label" class="marker-label">{{ marker.label }}</span>
        </div>
      </div>

      <!-- Waveform row (timeline view only) -->
      <AudioWaveform
        v-if="props.showWaveform && props.audioUrl"
        :audio-url="props.audioUrl"
        :visualization-url="mediaStore.currentMedia?.visualization_url"
        :is-full-screen="props.isFullScreen"
        @mousedown="onTrackMouseDown"
        @mousemove="onTrackMouseMove"
        @mouseup="onTrackMouseUp"
        @mouseleave="onTrackMouseUp"
        :style="props.isFullScreen ? { flex: '1 1 0%', height: 'auto', minHeight: '0' } : {}"
      />

      <Spectrogram
        v-if="props.showSpectrogram && props.audioUrl"
        :audio-url="props.audioUrl"
        :visualization-url="mediaStore.currentMedia?.visualization_url"
        :is-full-screen="props.isFullScreen"
        @mousedown="onTrackMouseDown"
        @mousemove="onTrackMouseMove"
        @mouseup="onTrackMouseUp"
        @mouseleave="onTrackMouseUp"
        :style="props.isFullScreen ? { flex: '1 1 0%', height: 'auto', minHeight: '0' } : {}"
      />

      <!-- Tracks -->
      <div 
        class="timeline-track" 
        :style="[
          { width: timelineWidth + 'px' },
          props.isFullScreen ? { flex: '4 4 0%', minHeight: '30vh' } : {}
        ]"
        @mousedown="onTrackMouseDown"
        @mousemove="onTrackMouseMove"
        @mouseup="onTrackMouseUp"
        @mouseleave="onTrackMouseUp"
      >
        <div v-for="tier in tiersList" :key="'track-'+(tier.id || tier)" class="tier-track">
          <span
            v-for="slot in getTierOverlaySlotCount(tier)"
            :key="'active-overlay-'+tierId(tier)+'-'+slot"
            class="tier-active-overlay"
            :ref="element => setTierOverlayElement(element, tier, slot - 1)"
            :style="{ backgroundColor: getTierColor(tier, 0.4) }"
            aria-hidden="true"
          ></span>
           <div 
            v-for="ann in getVisibleAnnotations(tier)" 
            :key="ann.id"
            class="annotation-block"
            :style="getAnnotationStyle(ann, tier)"
            @mouseenter="hoveredAnnotation = ann.value"
            @mousemove="handleMouseMoveTooltip"
            @mouseleave="hoveredAnnotation = null"
            @click="jumpToAnnotation(ann, $event)"
          >
            <span class="ann-text" v-if="ann.value">{{ ann.value }}</span>
          </div>
        </div>
      </div>

      <!-- Global Playhead — spans full height of tracks-container including viz rows -->
      <TimelinePlayhead />

      <!-- Selection overlays -->
      <div 
        v-if="mediaStore.selectionStart !== null && mediaStore.selectionEnd !== null && mediaStore.selectionStart !== mediaStore.selectionEnd"
        class="selection-region"
        :style="{
          left: (mediaStore.selectionStart / 1000) * eafStore.pixelsPerSecond + 'px',
          width: Math.max(0, ((mediaStore.selectionEnd - mediaStore.selectionStart) / 1000) * eafStore.pixelsPerSecond) + 'px'
        }"
      ></div>

      <!-- Custom Tooltip -->
      <div 
        v-if="hoveredAnnotation" 
        class="custom-tooltip"
        :style="{ left: mouseX + 15 + 'px', top: mouseY + 15 + 'px' }"
      >
        {{ hoveredAnnotation }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.custom-tooltip {
  position: fixed;
  background: #333;
  color: white;
  padding: 6px 12px;
  border-radius: 4px;
  font-size: 13px;
  pointer-events: none;
  z-index: 9999;
  max-width: 400px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.4);
  word-wrap: break-word;
  line-height: 1.4;
  border: 1px solid rgba(255,255,255,0.1);
}
.timeline-container {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  display: flex;
  overflow: hidden;
  background: var(--fav-bg);
  position: relative;
  border-top: 1px solid var(--fav-border-light);
}

.tier-labels {
  width: 120px;
  background: var(--fav-bg-alt);
  border-right: 1px solid var(--fav-border);
  flex-shrink: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
}

.ruler-corner {
  height: 40px;
  background: var(--fav-bg-header);
  border-bottom: 1px solid var(--fav-border);
  box-sizing: border-box;
}

.tier-label {
  height: 40px;
  display: flex;
  align-items: center;
  padding: 0 10px;
  color: var(--fav-text);
  font-weight: bold;
  font-size: var(--timeline-tier-font-size);
  border-bottom: 1px solid var(--fav-border-light);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: grab;
  user-select: none;
  box-sizing: border-box;
}

.tier-label:active {
  cursor: grabbing;
}

.tier-label:hover {
  background: var(--fav-bg-alt);
}

.tier-label.active {
  background: var(--fav-bg-active);
  color: var(--fav-primary);
  box-shadow: inset 0 0 0 1px var(--fav-border-light);
}

.draggable-tier-list .sortable-ghost {
  opacity: 0.4;
  background: #e2e2e2;
}

.tracks-container {
  flex: 1 1 0%;
  min-width: 0;
  overflow-x: auto;
  position: relative;
  background: var(--fav-bg);
  scrollbar-width: thin;
  display: flex;
  flex-direction: column;
}

.time-ruler {
  height: 40px;
  background: var(--fav-bg-header);
  border-bottom: 1px solid var(--fav-border);
  position: relative;
  cursor: crosshair;
  max-width: none !important;
  min-width: max-content !important;
  box-sizing: border-box;
}

.time-marker {
  position: absolute;
  bottom: 0;
  width: 1px;
  background: var(--fav-text-muted);
}

.time-marker.major {
  height: 15px;
  background: var(--fav-text);
}

.time-marker.minor {
  height: 6px;
  background: var(--fav-text-muted);
}

.marker-label {
  position: absolute;
  top: -24px;
  left: 4px;
  font-size: 10px;
  color: var(--fav-text);
  white-space: nowrap;
  font-family: monospace;
}

.timeline-track {
  position: relative;
  background: var(--fav-bg);
  cursor: crosshair;
  max-width: none !important;
  min-width: max-content !important;
  overflow-y: auto;
}

.tier-track {
  height: 40px;
  position: relative;
  border-bottom: 1px solid var(--fav-border-light);
  display: flex;
  align-items: center;
  box-sizing: border-box;
}

.annotation-block {
  position: absolute;
  contain: paint;
  z-index: 1;
  height: 30px;
  border-radius: 3px;
  color: var(--fav-text);
  font-size: var(--timeline-tier-font-size);
  overflow: hidden;
  white-space: nowrap;
  padding: 2px 0;
  /* No background transition: animating the active highlight makes
     entry/exit look like flicker, especially on short annotations */
  display: flex;
  align-items: center;
  border: 1px solid;
  cursor: pointer;
  box-sizing: border-box;
}

.tier-active-overlay {
  position: absolute;
  top: 5px;
  left: 0;
  /* A wider base avoids Safari magnifying sub-pixel snapping errors when a
     one-pixel element is scaled to the full annotation width. */
  width: 10px;
  height: 30px;
  z-index: 0;
  opacity: 0.001;
  pointer-events: none;
  transform: translate3d(0, 0, 0);
  transform-origin: left top;
  will-change: transform, opacity;
}

.ann-text {
  padding: 0 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
  width: 100%;
}

.selection-region {
  position: absolute;
  top: 0;
  bottom: 0;
  background: rgba(59, 172, 247, 0.2);
  border-left: 1px dashed rgba(59, 172, 247, 0.8);
  border-right: 1px dashed rgba(59, 172, 247, 0.8);
  pointer-events: none;
  z-index: 15;
  box-sizing: border-box;
}

/* Sidebar labels for waveform / spectrogram rows */
.viz-label {
  border-bottom: 1px solid var(--fav-border);
  display: flex;
  align-items: center;
  padding: 0 10px;
  font-size: 11px;
  color: var(--fav-text-muted);
  background: var(--fav-bg-alt);
  font-style: italic;
  flex-shrink: 0;
  box-sizing: border-box;
}
.waveform-label    { }
.spectrogram-label { }

.viz-resize-handle {
  display: none !important;
}
</style>
