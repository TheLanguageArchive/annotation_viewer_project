import { defineStore } from 'pinia'

export const useMediaStore = defineStore('media', {
  state: () => ({
    currentTime: 0,
    duration: 0,
    isPlaying: false,
    volume: 1,
    muted: false,
    currentMedia: null,
    peaksUrl: null,
    locations: {},
    loading: false,
    error: false,
    darkMode: localStorage.getItem('fav-dark-mode') === 'true' || document.body.classList.contains('dark-mode'),
    waveformHeight: 200, // Slightly more for standalone
    spectrogramHeight: 180,
  }),
  actions: {
    setWaveformHeight(h) {
      this.waveformHeight = h;
    },
    setSpectrogramHeight(h) {
      this.spectrogramHeight = h;
    },
    resetVizHeights() {
      this.waveformHeight = 200;
      this.spectrogramHeight = 180;
    },
    setDarkMode(isDark) {
      this.darkMode = isDark;
      localStorage.setItem('fav-dark-mode', isDark);
      if (isDark) {
        document.body.classList.add('dark-mode');
      } else {
        document.body.classList.remove('dark-mode');
      }
    },
    updateTime(timeMs) {
      this.currentTime = timeMs
    },
    setLocations(locs) {
      this.locations = locs
    },
    play() {
      this.isPlaying = true;
    },
    pause() {
      this.isPlaying = false;
    },
    async fetchMedia(url) {
      this.loading = true;
      try {
        const fetchUrl = url.startsWith('/') ? window.location.origin + url : url;
        const cacheBuster = `v=${Date.now()}`;
        const finalUrl = fetchUrl.includes('?') ? `${fetchUrl}&${cacheBuster}` : `${fetchUrl}?${cacheBuster}`;
        const response = await fetch(finalUrl);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

        const data = await response.json();

        if (data.status === 'error' || !data.locations || Object.keys(data.locations).length === 0) {
          throw new Error('No valid media files accessible.');
        }

        this.setLocations(data.locations);
        this.peaksUrl = data.peaks_url || null;

        const keys = Object.keys(data.locations);
        if (keys.length > 0) {
          this.currentMedia = { id: keys[0], ...data.locations[keys[0]] };
        }

        this.loading = false;
      } catch (err) {
        this.error = true;
        this.loading = false;
      }
    }
  }
})
