# Dependency security update

Build with Node.js 22.12+ (22.x) or 24+, then `npm ci` and `npm run build`.
Vite 8.2.2 and plugin-vue 6.0.8 are locked with patched PostCSS and nanoid
transitives. Full and production-only npm audits reported zero vulnerabilities
on 6 September 2026. Do not expose Vite dev/preview servers in production.

The IIFE bundle is emitted into `dist/`. Copy it to the corresponding Drupal
module's versioned JS filename and update its library version when rebuilding.
Deploy the library YAML and JS together, then run `drush cache:rebuild` and
purge cached assets. Existing older JS files are retained for rollback.

The production environment define is explicit for browser-only bundles. The
WaveSurfer worker_threads warning refers to its optional Node fallback;
browser playback and spectrogram initialization were smoke-tested locally.
