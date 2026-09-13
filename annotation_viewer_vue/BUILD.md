# Building the Annotation Viewer

This project is a Vue 3 application that compiles into a standard **Web Component**.

## Prerequisites
- Node.js 22.12+ (22.x), or 24+
- npm
- The adjacent `shared/` directory from this repository

## Build Instructions

1. **Install Dependencies**
   ```bash
   npm ci
   ```

2. **Build for Production**
   ```bash
   npm run build
   ```

3. **Deploy to Drupal**
   Once the build is complete, a `dist/annotation-viewer.iife.js` file will be generated.
   - Copy it to `flat_annotation_viewer/js/annotation-viewer-vNEXT.js`, replacing
     `NEXT` with the next bundle version. Update the module's `libraries.yml`
     path and version, and the tracked-bundle exception in the root `.gitignore`.
   - Clear Drupal caches: `drush cr`

## Development
The Vite server serves the component sources; this library project has no
standalone HTML demo. Test the built component in the configured Drupal site.
To start Vite for a custom development harness:
```bash
npm run dev
```
