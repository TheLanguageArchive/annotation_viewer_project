<?php

namespace Drupal\flat_media_visualization\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Generates media derivatives outside viewer HTTP requests.
 *
 * @QueueWorker(
 *   id = "flat_media_visualization",
 *   title = @Translation("FLAT media visualizations"),
 *   cron = {"time" = 60}
 * )
 */
class VisualizationWorker extends QueueWorkerBase {
  public function processItem($data) {
    \Drupal::service('flat_media_visualization.generator')->generate($data);
  }
}
