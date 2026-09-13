<?php

namespace Drupal\flat_media_visualization;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\file\Entity\File;
use Symfony\Component\Process\Process;

/** Manages a durable queue and private, versioned derivative files. */
class Generator {
  const VERSION = 1;
  const TIMEOUT = 3600;

  public function key(FileInterface $file): string {
    $local = \Drupal::service('file_system')->realpath($file->getFileUri());
    $mtime = $local && is_file($local) ? filemtime($local) : NULL;
    return hash('sha256', json_encode([self::VERSION, $file->uuid(), $file->getFileUri(), $file->getSize(), $file->getChangedTime(), $mtime]));
  }

  public function directory(string $key): string {
    if (!Settings::get('file_private_path')) throw new \RuntimeException('Configure file_private_path outside the web root.');
    $root = 'private://flat-visualizations';
    $fs = \Drupal::service('file_system');
    if (!$fs->prepareDirectory($root, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Cannot create the private visualization directory.');
    }
    return $fs->realpath($root) . '/' . $key;
  }

  public function request(FileInterface $file): array {
    $key = $this->key($file);
    $directory = $this->directory($key);
    if (is_file($directory . '/manifest.json')) {
      return ['status' => 'ready'] + json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    $state = \Drupal::keyValue('flat_media_visualization');
    $lock = \Drupal::lock();
    if (!$lock->acquire('flat-viz-request:' . $key, 10)) return ['status' => 'pending'];
    try {
      $current = $state->get($key);
      // Failed jobs may be retried after five minutes. Lost/stale jobs after a day.
      if (!$current || $current['expires'] < time()) {
        $item = \Drupal::queue('flat_media_visualization')->createItem(['fid' => $file->id(), 'key' => $key]);
        if (!$item) throw new \RuntimeException('Cannot enqueue visualization.');
        $current = ['status' => 'pending', 'expires' => time() + 86400];
        $state->set($key, $current);
      }
      return $current['status'] === 'failed'
        ? ['status' => 'failed', 'message' => 'Unable to prepare this media visualization. Please try again in a few minutes.']
        : ['status' => 'pending'];
    }
    finally { $lock->release('flat-viz-request:' . $key); }
  }

  public function generate(array $item): void {
    $file = File::load($item['fid']);
    if (!$file || $this->key($file) !== $item['key']) return;
    $key = $item['key'];
    $lock = \Drupal::lock();
    if (!$lock->acquire('flat-viz-generate:' . $key, self::TIMEOUT + 120)) {
      throw new \Drupal\Core\Queue\DelayedRequeueException(60, 'Generation already running.');
    }
    $work = NULL;
    $started = microtime(TRUE);
    try {
      $directory = $this->directory($key);
      if (is_file($directory . '/manifest.json')) return;
      $maxBytes = (int) Settings::get('flat_visualization_max_source_bytes', 2147483648);
      $stageTimeout = (int) Settings::get('flat_visualization_stage_timeout', 300);
      if ($maxBytes < 1 || $stageTimeout < 1 || $stageTimeout >= self::TIMEOUT) {
        throw new \RuntimeException('Invalid visualization staging limits.');
      }
      if ($file->getSize() > $maxBytes) throw new \RuntimeException('Source exceeds configured byte limit.');
      $work = $directory . '.work-' . bin2hex(random_bytes(8));
      if (!mkdir($work, 0700)) throw new \RuntimeException('Cannot create work directory.');
      $source = $work . '/source';
      $module = \Drupal::service('extension.list.module')->getPath('flat_media_visualization');
      // A parent-enforced timeout covers blocking fopen/read calls in wrappers.
      // Bootstrap the same site to use its configured trusted Fedora reader.
      $stage = new Process([
        Settings::get('flat_visualization_drush', 'drush'),
        '--root=' . DRUPAL_ROOT,
        '--uri=' . \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBasePath(),
        'php:script', DRUPAL_ROOT . '/' . $module . '/scripts/stage.php',
      ], DRUPAL_ROOT, [
        'FLAT_VIZ_SOURCE_FID' => (string) $file->id(),
        'FLAT_VIZ_SOURCE_DESTINATION' => $source,
      ]);
      $stage->setTimeout($stageTimeout);
      $stage->mustRun();
      $process = new Process([
        Settings::get('flat_visualization_python', 'python3'),
        DRUPAL_ROOT . '/' . $module . '/scripts/generate.py', $source, $work,
        '--ffmpeg', Settings::get('flat_visualization_ffmpeg', 'ffmpeg'),
        '--ffprobe', Settings::get('flat_visualization_ffprobe', 'ffprobe'),
      ]);
      $remaining = self::TIMEOUT - (microtime(TRUE) - $started);
      if ($remaining <= 0) throw new \RuntimeException('Visualization job timed out.');
      $process->setTimeout($remaining);
      $process->mustRun();
      unlink($source);
      // Do not publish derivatives if the file entity changed during processing.
      \Drupal::entityTypeManager()->getStorage('file')->resetCache([$file->id()]);
      $fresh = File::load($file->id());
      if (!$fresh || $this->key($fresh) !== $key) return;
      if (!rename($work, $directory)) throw new \RuntimeException('Cannot publish visualization cache.');
      $work = NULL;
      \Drupal::keyValue('flat_media_visualization')->delete($key);
    }
    catch (\Throwable $error) {
      \Drupal::keyValue('flat_media_visualization')->set($key, ['status' => 'failed', 'expires' => time() + 300]);
      \Drupal::logger('flat_media_visualization')->error('Generation failed for file @fid: @error', ['@fid' => $file->id(), '@error' => $error->getMessage()]);
    }
    finally {
      if ($work && is_dir($work)) \Drupal::service('file_system')->deleteRecursive($work);
      $lock->release('flat-viz-generate:' . $key);
    }
  }
}
