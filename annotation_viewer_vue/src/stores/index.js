import { defineStore } from 'pinia'

export const useEafStore = defineStore('eaf', {
  state: () => ({
    eaf: null,
    apiData: null,
    currentTier: null,
    secondaryTier: null, // For dual-column text view
    viewMode: 'timeline', // 'timeline', 'grid', 'text', or 'subtitle'
    pixelsPerSecond: 180, // Default zoom at 180px/s
    activeAnnotationIds: [],
    activeAnnotationTimeline: [],
    subtitleTiers: [], // Array of up to 3 tier IDs
    hiddenTimelineTiers: [], // Array of tier IDs to HIDE in timeline (empty = show all)
    tierOrder: [], // Array of tier IDs in original order for stable coloring
    timelineTierOrder: [], // Dynamic order for the timeline view
    showGridTimestamps: true,
    darkMode: localStorage.getItem('fav-dark-mode') === 'true' || document.body.classList.contains('dark-mode'),
    waveformHeight: 100,
    spectrogramHeight: 80,
    timelineTierFontSize: 14,
    gridTierFontSize: 16,
    textTierFontSize: 16,
    subtitleTierFontSize: 20,
    loading: false,
    error: false
  }),
  actions: {
    setWaveformHeight(h) {
      this.waveformHeight = h;
    },
    setSpectrogramHeight(h) {
      this.spectrogramHeight = h;
    },
    setTimelineTierFontSize(size) {
      this.timelineTierFontSize = size;
    },
    setViewFontSize(viewMode, size) {
      const property = `${viewMode}TierFontSize`;
      if (Object.hasOwn(this, property)) {
        this[property] = size;
      }
    },
    resetVizHeights() {
      this.waveformHeight = 100;
      this.spectrogramHeight = 80;
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
    toggleDarkMode() {
      this.setDarkMode(!this.darkMode);
    },
    setTimelineTierOrder(newOrder) {
      this.timelineTierOrder = newOrder;
    },
    toggleGridTimestamps() {
      this.showGridTimestamps = !this.showGridTimestamps;
    },
    toggleTimelineTier(tierId) {
      const id = String(tierId);
      const index = this.hiddenTimelineTiers.indexOf(id);
      if (index > -1) {
        this.hiddenTimelineTiers.splice(index, 1);
      } else {
        this.hiddenTimelineTiers.push(id);
      }
      // Force reactivity for array mutations just in case
      this.hiddenTimelineTiers = [...this.hiddenTimelineTiers];
    },
    toggleAllTimelineTiers() {
      const allTierIds = Object.values(this.eaf?.tiers || {}).map(t => String(t.id));

      // If none are hidden, hide all. Otherwise, show all.
      if (this.hiddenTimelineTiers.length === 0) {
        this.hiddenTimelineTiers = allTierIds;
      } else {
        this.hiddenTimelineTiers = [];
      }
    },
    toggleSubtitleTier(tierId) {
      const index = this.subtitleTiers.indexOf(tierId);
      if (index > -1) {
        this.subtitleTiers.splice(index, 1);
      } else if (this.subtitleTiers.length < 3) {
        this.subtitleTiers.push(tierId);
      }
    },
    async fetchEaf(url) {
      this.loading = true
      this.activeAnnotationIds = []
      this.activeAnnotationTimeline = []
      try {
        const fetchUrl = url.startsWith('/') ? window.location.origin + url : url;
        const cacheBuster = `v=${Date.now()}`;
        const finalUrl = fetchUrl.includes('?') ? `${fetchUrl}&${cacheBuster}` : `${fetchUrl}?${cacheBuster}`;
        const response = await fetch(finalUrl)
        if (response.status === 403) {
          const data = await response.json().catch(() => ({}));
          this.apiData = { ...data, accessible: false };
          this.loading = false;
          return;
        }
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

        const data = await response.json()

        if (data.status === 'error' || (data.accessible !== false && !data.annotation)) {
          throw new Error(data.message || 'API returned no data');
        }

        this.apiData = data
        this.eaf = data.annotation

        if (this.eaf.tiers) {
          // Filter out tiers that have zero annotations AND ensure they are keyed by ID
          const filteredTiers = {};
          Object.values(this.eaf.tiers).forEach((tier) => {
            if (tier.annotations && Object.keys(tier.annotations).length > 0) {
              const id = String(tier.id);
              filteredTiers[id] = tier;
            }
          });
          this.eaf.tiers = filteredTiers;

          // Stable order for colors/ordering
          this.tierOrder = Object.keys(filteredTiers);
          this.timelineTierOrder = [...this.tierOrder];
          this.buildActiveAnnotationTimeline();
          // Paint annotations that are active at the beginning while the viewer
          // is loading, rather than on the first playback clock update.
          this.updateActiveAnnotations(0);

          if (this.tierOrder.length > 0) {
            // Default to first tier, unless it's "@ref" and there's a second one
            let defaultId = this.tierOrder[0];
            if (defaultId === '@ref' && this.tierOrder.length > 1) {
              defaultId = this.tierOrder[1];
            }

            this.currentTier = this.eaf.tiers[defaultId];
            this.subtitleTiers = [defaultId];
          }
        }

        this.loading = false
      } catch (err) {
        this.error = true
        this.loading = false
      }
    },
    // Action to manually set visibility to ensure reactivity
    setVisibleTiers(ids) {
      this.visibleTimelineTiers = Array.from(ids);
    },
    setSubtitleTiers(ids) {
      this.subtitleTiers = Array.from(ids).filter(Boolean);
    },
    setTier(tierId) {
      if (this.eaf && this.eaf.tiers) {
        this.currentTier = this.eaf.tiers[tierId] || Object.values(this.eaf.tiers).find(t => t.id === tierId);
        if (this.currentTier && !this.subtitleTiers.includes(this.currentTier.id)) {
          this.subtitleTiers = [this.currentTier.id];
        }
      }
    },
    setZoom(pps) {
      this.pixelsPerSecond = pps;
    },
    buildActiveAnnotationTimeline() {
      const events = [];
      Object.values(this.eaf?.tiers || {}).forEach(tier => {
        Object.values(tier.annotations || {}).forEach(ann => {
          const start = ann.custom_start ?? ann.start ?? ann.referenced_annotation?.custom_start ?? ann.referenced_annotation?.start;
          const end = ann.custom_end ?? ann.end ?? ann.referenced_annotation?.custom_end ?? ann.referenced_annotation?.end;
          if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return;
          events.push({ time: start, kind: 1, id: ann.id });
          events.push({ time: end, kind: -1, id: ann.id });
        });
      });
      events.sort((a, b) => a.time - b.time || a.kind - b.kind);

      const active = new Set();
      const timeline = [];
      for (let i = 0; i < events.length;) {
        const time = events[i].time;
        while (i < events.length && events[i].time === time) {
          const event = events[i++];
          if (event.kind < 0) active.delete(event.id);
          else active.add(event.id);
        }
        timeline.push({ time, activeIds: [...active] });
      }
      this.activeAnnotationTimeline = timeline;
    },
    updateActiveAnnotations(currentTimeMs) {
      const timeline = this.activeAnnotationTimeline;
      let low = 0;
      let high = timeline.length - 1;
      let match = -1;
      while (low <= high) {
        const middle = (low + high) >> 1;
        if (timeline[middle].time <= currentTimeMs) {
          match = middle;
          low = middle + 1;
        }
        else {
          high = middle - 1;
        }
      }
      const unique = match >= 0 ? timeline[match].activeIds : [];
      const prev = this.activeAnnotationIds;
      if (unique.length !== prev.length || unique.some((id, i) => id !== prev[i])) {
        this.activeAnnotationIds = [...unique];
      }
    }
  }
})

export const useMediaStore = defineStore('media', {
  state: () => ({
    currentTime: 0,
    duration: 0,
    isPlaying: false,
    volume: 1,
    muted: false,
    currentMedia: null,
    locations: {},
    selectionStart: null,
    selectionEnd: null,
    isPlayingSegment: false,
    playbackRate: 1,
    fps: 25,
    frameDuration: 40 // ms
  }),
  actions: {
    setFps(fps) {
      this.fps = fps;
      this.frameDuration = 1000 / fps;
    },
    setPlaybackRate(rate) {
      this.playbackRate = rate;
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
    setSelection(start, end) {
      if (start === null || end === null) {
        this.selectionStart = null;
        this.selectionEnd = null;
      } else {
        this.selectionStart = Math.min(start, end);
        this.selectionEnd = Math.max(start, end);
      }
    },
    clearSelection() {
      this.selectionStart = null;
      this.selectionEnd = null;
      this.isPlayingSegment = false;
    },
    playSegment() {
      if (this.selectionStart !== null && this.selectionEnd !== null) {
        this.currentTime = this.selectionStart;
        this.isPlayingSegment = true;
        this.isPlaying = true;
      }
    }
  }
})
