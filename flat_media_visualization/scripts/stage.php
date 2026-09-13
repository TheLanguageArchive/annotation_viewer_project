<?php

/** Internal worker: run only through Generator's timed Drush child process. */
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\flat_media_visualization\SourceStager;

if (PHP_SAPI !== 'cli') throw new \RuntimeException('Source staging is CLI-only.');
$file = File::load(getenv('FLAT_VIZ_SOURCE_FID'));
if (!$file) throw new \RuntimeException('Source file no longer exists.');
$limit = (int) Settings::get('flat_visualization_max_source_bytes', 2147483648);
if ($limit < 1 || $file->getSize() > $limit) throw new \RuntimeException('Source exceeds configured byte limit.');
$copy = static fn() => SourceStager::copy($file->getFileUri(), getenv('FLAT_VIZ_SOURCE_DESTINATION'), $limit);
if (\Drupal::hasService('flat_permissions.fedora_reader')) {
  \Drupal::service('flat_permissions.fedora_reader')->runAs($copy);
}
else $copy();
