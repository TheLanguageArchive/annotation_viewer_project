<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import WaveSurfer from 'wavesurfer.js';
import SpectrogramPlugin from 'wavesurfer.js/dist/plugins/spectrogram.esm.js';
import { useMediaStore } from '../stores';
import { Visualization } from '../../../shared/visualization.mjs';

const props = defineProps({
  audioUrl: { type: String, required: true },
  visualizationUrl: { type: String, default: null },
  peaksUrl: { type: String, default: null },
  isFullScreen: { type: Boolean, default: false },
});
const mediaStore = useMediaStore();

const waveformRef = ref(null);
const isLoading = ref(true);
const isReady = ref(false);
const error = ref('');
const loadingMessage = ref('Loading waveform…');
const visualization = new Visualization(WaveSurfer, SpectrogramPlugin);
let mounted = false;

const initialize = async () => {
  if (!mounted) return;
  await nextTick();
  if (!mounted || !waveformRef.value) return;
  error.value = ''; isLoading.value = true; isReady.value = false;
  await visualization.load({
    container: waveformRef.value, url: props.audioUrl,
    statusUrl: props.visualizationUrl, peaksUrl: props.peaksUrl,
    duration: mediaStore.duration / 1000, currentTime: mediaStore.currentTime,
    wave: {
      waveColor: mediaStore.darkMode ? '#555555' : '#c0cfe0',
      progressColor: mediaStore.darkMode ? '#3facf7' : '#007bff',
      cursorColor: '#007bff', cursorWidth: 2,
      height: 'auto',
      interact: true,
      fillParent: true,

      barWidth: 2, barGap: 1, barRadius: 2, normalize: true,
    },

    onLoading: message => { loadingMessage.value = message; },
    onError: message => { error.value = message; isLoading.value = false; },
    onReady: () => {
      isLoading.value = false; isReady.value = true;
      visualization.ws?.updateProgress(mediaStore.currentTime / 1000);
    },
    onSeek: seconds => mediaStore.updateTime(seconds * 1000),
  });
};
onMounted(() => { mounted = true; initialize(); });
onUnmounted(() => { mounted = false; visualization.destroy(); });
watch(() => [props.audioUrl, props.visualizationUrl, props.peaksUrl], initialize);
watch(() => mediaStore.currentTime, ms => {
  if (visualization.ws?.getDecodedData()) visualization.ws.updateProgress(ms / 1000);
});

watch(() => mediaStore.waveformHeight, height => visualization.ws?.setOptions({ height }));
watch(() => mediaStore.darkMode, dark => visualization.ws?.setOptions({
  waveColor: dark ? '#555555' : '#c0cfe0', progressColor: dark ? '#3facf7' : '#007bff',
}));
</script>

<template>
  <div class="waveform-wrapper" :style="{ height: mediaStore.waveformHeight + 'px' }">
    <div v-if="error" class="waveform-loading" role="alert">{{ error }} <button type="button" @click.stop="initialize">Retry</button></div>
    <div v-else-if="isLoading" class="waveform-loading">
      <span>{{ loadingMessage }}</span>
    </div>
    <div
      ref="waveformRef"
      class="waveform-container"
      :style="{ height: mediaStore.waveformHeight + 'px' }"
      :class="{ 'waveform-hidden': isLoading || !!error }"
    ></div>
  </div>
</template>

<style scoped>
.waveform-wrapper {
  width: 100%;
  background: var(--fav-bg-alt, #f5f5f5);
  border-bottom: 1px solid var(--fav-border, #ddd);
  padding: 8px 0;
  box-sizing: border-box;
  min-height: 80px;
  display: flex;
  align-items: center;
}

.waveform-container {
  width: 100%;
  cursor: pointer;
}

.waveform-hidden {
  visibility: hidden;
  height: 0 !important;
}

.waveform-loading {
  width: 100%;
  text-align: center;
  font-size: 12px;
  color: var(--fav-text-muted, #888);
  font-family: sans-serif;
}
</style>

<!-- Fullscreen overrides: non-scoped so they apply regardless of shadow scoping.
     The ::part(scroll) rule is the KEY:
      - Wavesurfer exposes its inner scroll container via part="scroll"
      - Forcing height:100% on it makes it grow to fill .waveform-container (952px)
      - Wavesurfer's own internal ResizeObserver detects the size change
      - onContainerResize() fires → reRender() → getHeight() reads parent.clientHeight
      - Canvas redraws at 952px automatically (with its built-in ~100ms debounce)
     No JS polling or setOptions() needed! -->
<style>
:fullscreen .waveform-wrapper {
  align-items: stretch !important;
  padding: 0 !important;
  min-height: 0 !important;
}

/* The outer container fills the wrapper */
:fullscreen .waveform-container {
  height: 100% !important;
}

/* The Wavesurfer shadow host div (direct child of .waveform-container) */
:fullscreen .waveform-container > div {
  height: 100% !important;
  display: block !important;
}

/* The shadow DOM scroll container — exposed via part="scroll". */
:fullscreen .waveform-container > div::part(scroll) {
  height: 100% !important;
  overflow-y: hidden !important;
}

:-webkit-full-screen .waveform-wrapper {
  align-items: stretch !important;
  padding: 0 !important;
  min-height: 0 !important;
}

:-webkit-full-screen .waveform-container {
  height: 100% !important;
}

:-webkit-full-screen .waveform-container > div {
  height: 100% !important;
  display: block !important;
}

:-webkit-full-screen .waveform-container > div::part(scroll) {
  height: 100% !important;
  overflow-y: hidden !important;
}
</style>
