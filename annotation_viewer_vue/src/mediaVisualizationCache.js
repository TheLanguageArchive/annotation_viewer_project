const DATABASE_NAME = 'flat-annotation-viewer';
const STORE_NAME = 'decoded-media';
const CACHE_VERSION = 1;
const MAX_CACHE_BYTES = 60 * 1024 * 1024;

const cacheKey = (url) => `${CACHE_VERSION}:${url}`;

const openDatabase = () => new Promise((resolve, reject) => {
  const request = indexedDB.open(DATABASE_NAME, CACHE_VERSION);
  request.onupgradeneeded = () => {
    if (!request.result.objectStoreNames.contains(STORE_NAME)) request.result.createObjectStore(STORE_NAME);
  };
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error);
});

const runTransaction = async (mode, callback) => {
  if (!('indexedDB' in window)) return null;
  const database = await openDatabase();
  try {
    return await new Promise((resolve, reject) => {
      const request = callback(database.transaction(STORE_NAME, mode).objectStore(STORE_NAME));
      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => reject(request.error);
    });
  } finally {
    database.close();
  }
};

export const getCachedDecodedMedia = async (url) => {
  try { return await runTransaction('readonly', (store) => store.get(cacheKey(url))); }
  catch (_) { return null; }
};

export const cacheDecodedMedia = async (url, decodedData) => {
  if (!decodedData?.duration) return;
  const channels = Array.from({ length: decodedData.numberOfChannels }, (_, index) => decodedData.getChannelData(index).slice());
  if (channels.reduce((total, channel) => total + channel.byteLength, 0) > MAX_CACHE_BYTES) return;
  try {
    await runTransaction('readwrite', (store) => store.put({ duration: decodedData.duration, channels, cachedAt: Date.now() }, cacheKey(url)));
  } catch (_) {
    // Caching is optional: quota and private-mode failures must not affect playback.
  }
};
