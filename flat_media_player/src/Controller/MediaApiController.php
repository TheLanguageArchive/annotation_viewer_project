<?php

namespace Drupal\flat_media_player\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

/**
 * Controller for the FLAT Media Player API.
 */
class MediaApiController extends ControllerBase {

  /**
   * Returns media locations for the player.
   */
  public function getData(NodeInterface $node) {
    $cache_metadata = new CacheableMetadata();
    $cache_metadata->setCacheMaxAge(0);
    \Drupal::service('page_cache_kill_switch')->trigger();
    $cache_metadata->addCacheContexts(['url.path', 'user']);
    $cache_metadata->addCacheTags($node->getCacheTags());
    
    $node_access = $node->access('view', NULL, TRUE);
    $cache_metadata->addCacheableDependency($node_access);
    if (!$node_access->isAllowed()) {
      $response = new CacheableJsonResponse(['error' => 'Access denied'], 403);
      $response->addCacheableDependency($cache_metadata);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Cookie, Authorization');
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }

    $locations = [];
    
    // Only look at media associated with the current node.
    $mids = \Drupal::entityQuery('media')
      ->condition('field_media_of', $node->id())
      ->accessCheck(TRUE)
      ->execute();
    
    $service_locations = [];
    $original_locations = [];
    $peaks_url = NULL;

    if (!empty($mids)) {
      foreach (Media::loadMultiple($mids) as $media) {
        $cache_metadata->addCacheableDependency($media);
        $media_access = $media->access('view', NULL, TRUE);
        $cache_metadata->addCacheableDependency($media_access);
        if (!$media_access->isAllowed()) continue;
        
        $media_use = NULL;
        if ($media->hasField('field_media_use')) {
          $media_use_items = $media->get('field_media_use')->referencedEntities();
          if (!empty($media_use_items)) {
            foreach ($media_use_items as $term) {
              $label = $term->label();
              if (in_array($label, ['Original File', 'Service File', 'Peaks'])) {
                $media_use = $label;
                break;
              }
            }
          } else {
            $val = $media->get('field_media_use')->value;
            if (in_array($val, ['Original File', 'Service File', 'Peaks'])) {
              $media_use = $val;
            }
          }
        }
        
        if (!$media_use) continue;

        $source_field = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
        
        if ($source_field && $media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
          $file = $media->get($source_field)->entity;
          if ($file instanceof File) {
            $cache_metadata->addCacheableDependency($file);
            $file_access = $file->access('download', NULL, TRUE);
            $cache_metadata->addCacheableDependency($file_access);
            if (!$file_access->isAllowed()) continue;
            $url = \Drupal::service('file_url_generator')->generateString($file->getFileUri());

            if ($media_use === 'Peaks') {
              if (!isset($peaks_url)) {
                $peaks_url = $url;
              }
              continue;
            }

            $mimetype = $file->getMimeType();
            // Check if able to play
            if (in_array($mimetype, ['video/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'video/webm', 'video/ogg'])) {
              $location = [
                'mimetype' => $mimetype,
                'url' => $url,
                'label' => $media->label(),
                'visualization_url' => \Drupal\Core\Url::fromRoute('flat_media_visualization.data', [
                  'node' => $node->id(), 'media' => $media->id(), 'kind' => 'status',
                ])->toString(),
              ];
              $filename = $file->getFilename();

              if ($media_use === 'Service File') {
                $service_locations[$filename] = $location;
              } elseif ($media_use === 'Original File') {
                $original_locations[$filename] = $location;
              }
            }
          }
        }
      }
    }

    // Use Service Files by default, fallback to Original Files.
    $locations = !empty($service_locations) ? $service_locations : $original_locations;

    $response_data = [
      'pid' => $node->id(),
      'locations' => $locations,
      'peaks_url' => $peaks_url ?? NULL,
    ];

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache_metadata);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Cookie, Authorization');
    return $response;
  }
}
