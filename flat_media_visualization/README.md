# Server-generated media visualizations

The shared Drupal module generates a waveform and a deliberately coarse mono
spectrogram the first time either viewer requests a recording. Playback continues
through the real audio/video element. The browser polls the status endpoint and
loads derivatives when ready; server errors offer Retry, without silently falling
back to decoding a large source file.

## Install and deploy

1. Install `ffmpeg` (including `ffprobe`), Python 3 and NumPy in the Drupal image.
   Alpine: `apk add --no-cache ffmpeg python3 py3-numpy`.
   Debian/Ubuntu: `apt-get install ffmpeg python3 python3-numpy`.
   Persist these dependencies in the image Dockerfile; an install in a running
   container disappears when the container is recreated.
2. Copy `flat_media_visualization` alongside `flat_annotation_viewer` and
   `flat_media_player` in `web/modules/custom`.
3. Install Drush and make it available on the worker's PATH. Source staging uses
   a separate Drupal/Drush process so a blocked remote stream can be terminated.
   Configure `$settings['file_private_path']` to a writable directory outside the
   web root. Optional executable overrides in `settings.php`:
   `$settings['flat_visualization_python']`, `flat_visualization_ffmpeg`,
   `flat_visualization_ffprobe` (default: `python3`, `ffmpeg`, `ffprobe`).
   Set `$settings['flat_visualization_drush']` to an absolute Drush executable
   path if it is not available on PATH. The child process bootstraps the same
   Drupal root and site URI; use the correct `--uri` when running multisite queues.
   Source staging defaults to 2 GiB and 300 seconds, configurable via
   `$settings['flat_visualization_max_source_bytes']` and
   `$settings['flat_visualization_stage_timeout']`. The timeout must be positive
   and below 3600 seconds. Oversized file metadata is rejected before copying;
   actual copied bytes are also limited even if metadata is inaccurate. A timed
   out or failed staging job has its partial working directory removed.
4. Run `drush en flat_media_visualization -y` **before** deploying the updated API
   controllers. Deploy both viewer modules, including their new versioned JS
   files and `libraries.yml`, then run `drush cr`.
5. Run the queue regularly as the **same OS user as PHP-FPM** (locally `nginx`).
   For example, this entry in that user's crontab checks once per minute:

   ```cron
   * * * * * cd /var/www/drupal && /usr/local/bin/drush queue:run flat_media_visualization --time-limit=50 --lease-time=3720
   ```

   Drupal cron also handles the queue, but its frequency controls how long a first
   visitor waits. Do not rely on page-triggered cron for lengthy conversions.
   A long individual job can exceed `--time-limit`; the generation process has a
   one-hour budget shared by staging and conversion. Run one worker for
   predictable CPU/disk consumption. Allow room for the configured staging
   limit plus generated artifacts; the limits do not cap total retained cache
   storage. Monitor free disk space and prune unused caches as needed.

Both APIs attach `visualization_url` to each source location; the annotation
resolver preserves it in EAF media descriptors. Data is tied to the exact file,
not a node-wide peaks URL that could belong to another recording. Existing API
responses without this field retain the legacy browser path.

## Format and resource use

- Mono audio at 16 kHz; only the first audio track is used for video.
- Waveform: 100 min/max pairs per second, normalized floating-point values,
  plus duration in seconds.
- Spectrogram: 20 time slices per second, 128 linear-frequency bins over 0–8 kHz;
  intensities are 8-bit values covering −100 to −20 dBFS. A Hann-windowed FFT is
  pooled into the display bins. This is intended for broad speech/sound patterns,
  not detailed acoustic measurement. Zooming cannot recover finer detail.
- Spectrogram JSON shape: `[channel][time slice][frequency bin]`, compatible with
  WaveSurfer's `frequenciesDataUrl`. Gzip variants are generated and served when
  accepted. The browser downloads spectral data once per component load and
  reuses a local blob on redraw, instead of redownloading on each zoom.
- Decoding/FFT processing streams in small blocks; the full PCM recording is
  never kept in memory. Disk must accommodate one staged source per worker plus
  cached derivatives. Input recordings are currently limited to four hours.
- Video audio offsets are padded against the media timeline, with silence through
  the video end. Source format duration is used consistently for both derivatives.

## Cache and access

Results live under `private://flat-visualizations`, keyed by file UUID, URI, size,
changed time, local modification time (when available), and generator version.
Publish happens by atomic directory rename. Concurrent first requests enqueue one
job. Failures become retryable after five minutes; stale queued requests after a
day. A source change creates a new key. If remote bytes are replaced without a
Drupal file update, resave the file entity to invalidate its derivative cache.

The endpoint checks the source node, media, file download access and the media's
relationship to that node on every request, including ready artifacts. Normal
Drupal private-file download routes are denied for this directory. Responses are
private/no-store; server disk caching is shared without making the data public.
Source staging uses `flat_permissions.fedora_reader` when available; otherwise
it reads through the site's normal stream wrappers and storage credentials.
The Drush queue worker must be able to read the source files in either case.

Old derivative directories are retained. Administrators can remove unused cache
folders when needed; missing results regenerate on demand. Avoid deleting a
`.work-*` directory belonging to an active worker.

## Verification

The GitHub source distribution intentionally excludes local test files. After
installing on your site, verify:

- Both viewer APIs return the expected media and annotation data.
- Public recordings generate derivatives through the queue as the PHP-FPM user.
- Restricted recordings are denied to anonymous and unauthorized users on the
  status, peaks and spectrogram routes as well as the viewer APIs.
- Ready derivatives use private/no-store responses; stale version URLs fail and
  direct private-file download paths cannot bypass the controller.
- Oversized sources fail without leaving staged files, and stalled staging
  processes time out and release their locks.
- Waveform/spectrogram toggles, zoom, seeking, media switching and fullscreen
  behave correctly in the browser.

Check the [root deployment prerequisites](../README.md#drupal-deployment) before
using Fedora sources. FLAT Permissions and its trusted FedoraReader service are
optional for both viewers and the visualization worker.
