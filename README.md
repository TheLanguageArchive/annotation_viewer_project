# FLAT annotation viewer and media player

This repository contains deployable Drupal custom modules and their Vue source projects:

- `flat_annotation_viewer/` — an annotation-viewer Drupal module for visualising media annotated with the [ELAN](https://archive.mpi.nl/tla/elan) annotation tool. This includes the Vue Javascript bundle used by Drupal.
- `flat_media_player/` — essentially the media player part of the above annotation viewer, for playing video and audio files in Drupal.
- `flat_media_visualization/` — the server-side media-visualization module for generating waveform and spectrogram cache files.
- `annotation_viewer_vue/` and `media_player_vue/` — Vue/Vite source projects used to build the browser components.
- `shared/` — JavaScript shared by the visualization tooling.

## Drupal deployment

These modules target an existing FLAT/Islandora site, not a bare Drupal install.
Before enabling them, install and configure:

- Drupal 11 with Node, Media and File enabled, and PHP SimpleXML.
- Islandora and its configured Fedora/Flysystem connection for the annotation
  viewer and for any media stored under `fedora://`.
- [FLAT Permissions](https://github.com/TheLanguageArchive/flat_permissions)
  for the annotation viewer, including the
  `flat_permissions.fedora_reader` service and
  `Drupal\flat_permissions\Service\FedoraReader::runAs()`. Install its
  dependencies too. This repository does not bundle FLAT Permissions, its
  dependencies or their site configuration.
- A dedicated Fedora read account configured in
  `flat_permissions.settings:fedora_read_user`, with permission to read the
  repository sources. Drupal node/media/file permissions still determine who
  may request the viewer data. Verify access as both anonymous and restricted
  users before exposing protected collections.
- Islandora media relationships (`field_media_of`), media-use taxonomy
  (`field_media_use`, using `Original File`/`Service File` labels), and file-based
  media source fields. The annotation viewer additionally expects an
  `annotation` media bundle containing EAF files and uses `field_member_of`
  when resolving media belonging to a collection or compound object.
- Drush on the queue worker's PATH, FFmpeg/FFprobe, Python 3 and NumPy for
  server-generated waveforms and spectrograms, plus writable private storage
  outside the web root and a regularly scheduled queue worker.

Check that the required reader API exists before enabling the annotation viewer:

```sh
drush php:eval 'if (!Drupal::hasService("flat_permissions.fedora_reader")) { throw new RuntimeException("Install and enable FLAT Permissions with FedoraReader."); }'
```

Follow [visualization setup](flat_media_visualization/README.md#install-and-deploy)
for private storage, executable paths, resource limits and the queue command.
Copy the three `flat_*` directories into Drupal's custom-modules directory, then:

```sh
drush en flat_media_visualization flat_media_player flat_annotation_viewer -y
drush cr
```

The checked-in JavaScript files referenced by each module's
`*.libraries.yml` file are the deployment assets.

## Front-end development

Use Node.js 22.12+ within the 22.x line, or Node.js 24+, as declared in each
package's `engines` field. Keep `shared/` alongside both Vue directories when
building. Each Vue project has its own dependency lockfile:

```sh
cd annotation_viewer_vue   # or media_player_vue
npm ci
npm run dev
```

These are web-component libraries, without standalone HTML demo pages. Vite's
development server needs a custom harness; the supported integration check is
to build and load the components in Drupal.

Run `npm run build` to produce a local `dist/` directory. Deploy a newly built
bundle to the corresponding Drupal module only after updating the module's
library definition to reference it.

## AI assistance

This project was developed with assistance from Claude Code, Google Gemini, and
ChatGPT. Human contributors reviewed, directed, and remain responsible for all
project decisions and released code.
