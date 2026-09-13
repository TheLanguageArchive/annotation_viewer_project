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

const containerRef = ref(null);
const isLoading = ref(true);
const isReady = ref(false);
const error = ref('');
const loadingMessage = ref('Loading spectrogram…');
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
      cursorColor: '#007bff', cursorWidth: 2,
      height: 0,
      interact: true,
      fillParent: true,


    },
    spectrogram: { labels: true, height: mediaStore.spectrogramHeight, splitChannels: false },
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

watch(() => mediaStore.spectrogramHeight, initialize);
</script>

<template>
  <div class="spectrogram-wrapper" :style="{ height: mediaStore.spectrogramHeight + 'px' }">
    <div v-if="error" class="spectrogram-loading" role="alert">{{ error }} <button type="button" @click.stop="initialize">Retry</button></div>
    <div v-else-if="isLoading" class="spectrogram-loading">
      <span>{{ loadingMessage }}</span>
    </div>
    <div
      ref="containerRef"
      class="spectrogram-container"
      :style="{ height: mediaStore.spectrogramHeight + 'px' }"
      :class="{ 'spectrogram-hidden': isLoading || !!error }"
    ></div>
  </div>
</template>

<style scoped>
.spectrogram-wrapper {
  width: 100%;
  background: var(--fav-bg-alt, #f5f5f5);
  border-bottom: 1px solid var(--fav-border, #ddd);
  box-sizing: border-box;
  overflow: hidden;
  flex-shrink: 0;
}

.spectrogram-container {
  width: 100%;
  cursor: default;
}

.spectrogram-hidden {
  visibility: hidden;
  height: 0 !important;
}

.spectrogram-loading {
  width: 100%;
  text-align: center;
  font-size: 12px;
  color: var(--fav-text-muted, #888);
  font-family: sans-serif;
  padding: 8px 0;
}
</style>

<style>
:fullscreen .spectrogram-wrapper {
  flex: 1 !important;
  display: flex !important;
  flex-direction: column !important;
}
:fullscreen .spectrogram-container {
  flex: 1 !important;
  height: 100% !important;
}

:-webkit-full-screen .spectrogram-wrapper {
  flex: 1 !important;
  display: flex !important;
  flex-direction: column !important;
}
:-webkit-full-screen .spectrogram-container {
  flex: 1 !important;
  height: 100% !important;
}
</style>
