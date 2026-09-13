<script setup>
import { ref, watch } from 'vue';
import { useEafStore, useMediaStore } from '../stores';

const eafStore = useEafStore();
const mediaStore = useMediaStore();
const playheadRef = ref(null);

const updatePlayhead = () => {
  if (!playheadRef.value) return;
  const left = (mediaStore.currentTime / 1000) * eafStore.pixelsPerSecond;
  playheadRef.value.style.transform = `translate3d(${left}px, 0, 0) translateX(-50%)`;
};

watch(
  [() => mediaStore.currentTime, () => eafStore.pixelsPerSecond],
  updatePlayhead,
  { flush: 'sync' }
);
</script>

<template>
  <div ref="playheadRef" class="playhead"></div>
</template>

<style scoped>
.playhead {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 1px;
  background: rgba(220, 50, 50, 0.9);
  z-index: 20;
  pointer-events: none;
  will-change: transform;
}
</style>
