<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick, computed } from 'vue';
import WaveSurfer from 'wavesurfer.js';
import SpectrogramPlugin from 'wavesurfer.js/dist/plugins/spectrogram.esm.js';
import { useMediaStore, useEafStore } from '../stores';
import { Visualization } from '../../../shared/visualization.mjs';

const props = defineProps({
  audioUrl: { type: String, required: true },
  visualizationUrl: { type: String, default: null },
  peaksUrl: { type: String, default: null },
  isFullScreen: { type: Boolean, default: false },
});
const mediaStore = useMediaStore();
const eafStore = useEafStore();
const containerRef = ref(null);
const isLoading = ref(true);
const isReady = ref(false);
const error = ref('');
const loadingMessage = ref('Loading spectrogram…');
const timelineWidth = computed(() => (mediaStore.duration / 1000) * eafStore.pixelsPerSecond);
const visualization = new Visualization(WaveSurfer, SpectrogramPlugin);
let mounted = false;

const initialize = async () => {
  if (!mounted) return;
  await nextTick();
  if (!mounted || !containerRef.value) return;
  error.value = ''; isLoading.value = true; isReady.value = false;
  await visualization.load({
    container: containerRef.value, url: props.audioUrl,
    statusUrl: props.visualizationUrl, peaksUrl: props.peaksUrl,
    duration: mediaStore.duration / 1000, currentTime: mediaStore.currentTime,
    wave: {
      waveColor: "transparent",
      progressColor: "transparent",
      cursorColor: "transparent", cursorWidth: 0,
      height: 0,
      interact: false,
      fillParent: false,
      minPxPerSec: eafStore.pixelsPerSecond,

    },
    spectrogram: { labels: false, height: eafStore.spectrogramHeight, splitChannels: false },
    onLoading: message => { loadingMessage.value = message; },
    onError: message => { error.value = message; isLoading.value = false; },
    onReady: () => {
      isLoading.value = false; isReady.value = true;
      visualization.ws?.updateProgress(mediaStore.currentTime / 1000);
    },

  });
};
onMounted(() => { mounted = true; initialize(); });
onUnmounted(() => { mounted = false; visualization.destroy(); });
watch(() => [props.audioUrl, props.visualizationUrl, props.peaksUrl], initialize);
watch(() => mediaStore.currentTime, ms => {
  if (visualization.ws?.getDecodedData()) visualization.ws.updateProgress(ms / 1000);
});
watch(() => eafStore.pixelsPerSecond, value => { if (visualization.ws?.getDecodedData()) visualization.ws.zoom(value); });
watch(() => eafStore.spectrogramHeight, initialize);
</script>

<template>
  <div 
    class="spectrogram-wrapper" 
    :style="{ 
      width: timelineWidth + 'px', 
      height: props.isFullScreen ? 'auto' : (eafStore.spectrogramHeight + 'px') 
    }"
  >
    <div v-if="error" class="spectrogram-loading" role="alert">{{ error }} <button type="button" @click.stop="initialize">Retry</button></div>
    <div v-else-if="isLoading" class="spectrogram-loading"><span>{{ loadingMessage }}</span></div>
    <div
      ref="containerRef"
      class="spectrogram-container"
      :style="{ width: '100%', height: props.isFullScreen ? '100%' : (eafStore.spectrogramHeight + 'px') }"
      :class="{ 'spectrogram-hidden': isLoading || !!error }"
    ></div>
  </div>
</template>

<style scoped>
.spectrogram-wrapper {
  background: var(--fav-bg-alt, #f5f5f5);
  border-bottom: 1px solid var(--fav-border, #ddd);
  box-sizing: border-box;
  overflow: visible;
  position: relative;
  flex-shrink: 0;
  cursor: crosshair;
}
.spectrogram-container {
  cursor: crosshair;
}
.spectrogram-hidden {
  visibility: hidden;
  height: 0 !important;
}
.spectrogram-loading {
  /* The row spans the entire recording. Anchor status to the visible viewport. */
  position: sticky;
  left: 12px;
  width: max-content;
  max-width: min(560px, 75vw);
  box-sizing: border-box;
  padding: 12px 0;
  font-size: 12px;
  color: var(--fav-text-muted, #888);
  font-family: sans-serif;
  white-space: normal;
  overflow-wrap: anywhere;
}
</style>
