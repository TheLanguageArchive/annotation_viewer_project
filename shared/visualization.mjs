// Shared by the two separately bundled Vue applications.
export function delay(ms, signal) {
  return new Promise((resolve, reject) => {
    const abort = () => { clearTimeout(timer); reject(new DOMException('Aborted', 'AbortError')); };
    const timer = setTimeout(() => { signal.removeEventListener('abort', abort); resolve(); }, ms);
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
  });
}

async function fetchData(url, signal) {
  const response = await fetch(url, { signal, credentials: 'same-origin' });
  if (!response.ok) throw new Error('Unable to load visualization data. Please retry.');
  return response;
}

export async function waitForVisualization(url, signal, onPending, { timeout = 600000, interval = 5000 } = {}) {
  const deadline = Date.now() + timeout;
  while (true) {
    const response = await fetch(url, { signal, credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (response.status === 200 && data.status === 'ready') return data;
    if (response.status !== 202 || data.status !== 'pending') {
      throw new Error(data.message || 'Visualization unavailable. Please retry.');
    }
    onPending();
    if (Date.now() >= deadline) throw new Error('Still preparing the visualization. Please retry shortly.');
    await delay(interval, signal);
  }
}

export function parsePeaks(json, fallbackDuration) {
  let channels = json.peaks ?? json.data ?? json;
  if (!Array.isArray(channels) || !channels.length) throw new Error('Invalid waveform peaks.');
  if (!Array.isArray(channels[0])) {
    // audiowaveform JSON interleaves min/max values for each channel.
    const count = json.channels || 1;
    if (!Number.isInteger(count) || count < 1 || (count > 1 && channels.length % (2 * count))) throw new Error('Invalid waveform channels.');
    const values = channels;
    channels = count === 1 ? [values] : Array.from({ length: count }, () => []);
    for (let i = 0; count > 1 && i < values.length; i += count * 2) {
      for (let c = 0; c < count; c++) channels[c].push(values[i + 2 * c], values[i + 2 * c + 1]);
    }
  }
  const divisor = json.bits ? 2 ** (json.bits - 1) : 1;
  channels = channels.map(channel => {
    if (!channel.length || channel.some(value => !Number.isFinite(value))) throw new Error('Invalid waveform samples.');
    return channel.map(value => value / divisor);
  });
  const duration = json.duration || (json.length && json.samples_per_pixel && json.sample_rate
    ? json.length * json.samples_per_pixel / json.sample_rate : fallbackDuration);
  if (!Number.isFinite(duration) || duration <= 0) throw new Error('Waveform duration is unavailable.');
  return { channels, duration };
}

/** Owns async loads and prevents an old source from changing a newer display. */
export class Visualization {
  constructor(WaveSurfer, SpectrogramPlugin) {
    this.WaveSurfer = WaveSurfer;
    this.SpectrogramPlugin = SpectrogramPlugin;
  }

  destroy() {
    this.abort?.abort();
    this.abort = null;
    this.ws?.destroy();
    this.ws = null;
    if (this.blobUrl) URL.revokeObjectURL(this.blobUrl);
    this.blobUrl = null;
  }

  async load(options) {
    this.destroy();
    const controller = new AbortController();
    this.abort = controller;
    const { signal } = controller;
    const current = () => !signal.aborted && this.abort === controller;
    const fail = error => { if (current() && error.name !== 'AbortError') options.onError(error.message); };
    options.onLoading(`Loading ${options.spectrogram ? 'spectrogram' : 'waveform'}…`);
    try {
      let manifest;
      let peaks;
      if (options.statusUrl) {
        manifest = await waitForVisualization(options.statusUrl, signal,
          () => options.onLoading('Preparing visualization on the server…'));
        peaks = parsePeaks(await (await fetchData(manifest.peaks_url, signal)).json(), manifest.duration);
      } else if (!options.spectrogram && options.peaksUrl) {
        peaks = parsePeaks(await (await fetchData(options.peaksUrl, signal)).json(), options.duration);
      }
      if (!current()) return;
      let plugin;
      if (options.spectrogram) {
        let frequenciesDataUrl;
        if (manifest) {
          const blob = await (await fetchData(manifest.spectrogram_url, signal)).blob();
          if (!current()) return;
          frequenciesDataUrl = this.blobUrl = URL.createObjectURL(blob);
        }
        plugin = this.SpectrogramPlugin.create({
          ...options.spectrogram,
          ...(manifest ? {
            frequenciesDataUrl, sampleRate: manifest.sample_rate,
            fftSamples: manifest.frequency_bins * 2, frequencyMin: manifest.frequency_min,
            frequencyMax: manifest.frequency_max, scale: manifest.scale,
          } : {}),
        });
        // The plugin fetches on redraw too. A local blob avoids repeated server downloads.
        // Its render path does not await/catch loadFrequenciesData, so handle failures here.
        const load = plugin.loadFrequenciesData.bind(plugin);
        plugin.loadFrequenciesData = async url => { try { await load(url); } catch (error) { fail(error); } };
        plugin.on('ready', () => { if (current()) options.onReady(); });
        plugin.on('click', position => { if (current()) options.onSeek?.(position * this.ws.getDuration()); });
      }
      const ws = this.WaveSurfer.create({
        ...options.wave, container: options.container,
        ...(plugin ? { plugins: [plugin] } : {}),
      });
      this.ws = ws;
      ws.on('error', fail);
      ws.on('ready', () => { if (current() && !plugin) options.onReady(); });
      ws.on('interaction', seconds => { if (current()) options.onSeek?.(seconds); });
      // Derived data needs no media URL. The real audio/video element handles playback.
      await ws.load(peaks ? '' : options.url, peaks?.channels, peaks?.duration);
      if (current()) ws.updateProgress(options.currentTime / 1000);
    } catch (error) { fail(error); }
  }
}
