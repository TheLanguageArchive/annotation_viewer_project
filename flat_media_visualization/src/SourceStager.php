<?php

namespace Drupal\flat_media_visualization;

/** Bounded source copying, executed in a separately timed Drupal process. */
final class SourceStager {

  public static function copy(string $uri, string $destination, int $maxBytes): void {
    if ($maxBytes < 1) throw new \InvalidArgumentException('Source byte limit must be positive.');
    $input = @fopen($uri, 'rb');
    if (!$input) throw new \RuntimeException('Cannot read source media.');
    $output = NULL;
    $complete = FALSE;
    try {
      $output = @fopen($destination, 'xb');
      if (!$output) throw new \RuntimeException('Cannot create staged source.');
      $copied = 0;
      while (!feof($input)) {
        $chunk = fread($input, min(1024 * 1024, $maxBytes - $copied + 1));
        if ($chunk === FALSE || ($chunk === '' && !feof($input))) {
          throw new \RuntimeException('Source read failed or stalled.');
        }
        $length = strlen($chunk);
        if ($length > $maxBytes - $copied) throw new \RuntimeException('Source exceeds configured byte limit.');
        for ($offset = 0; $offset < $length; $offset += $written) {
          $written = fwrite($output, substr($chunk, $offset));
          if ($written === FALSE || $written === 0) throw new \RuntimeException('Source write failed.');
        }
        $copied += $length;
      }
      if (!fflush($output)) throw new \RuntimeException('Cannot flush staged source.');
      $complete = TRUE;
    }
    finally {
      fclose($input);
      if (is_resource($output)) {
        fclose($output);
        if (!$complete) unlink($destination);
      }
    }
  }
}
