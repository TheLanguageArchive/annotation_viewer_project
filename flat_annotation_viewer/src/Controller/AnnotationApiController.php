<?php

namespace Drupal\flat_annotation_viewer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\flat_permissions\Service\FedoraReader;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\flat_annotation_viewer\EAF\Parser;
use Drupal\flat_annotation_viewer\EAF\Resolver\MediaResolver;

/**
 * Controller for the FLAT Annotation Viewer API.
 */
class AnnotationApiController extends ControllerBase {

  public function __construct(
    protected readonly FedoraReader $fedoraReader,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('flat_permissions.fedora_reader'));
  }

  /**
   * Returns EAF data and media locations for the viewer.
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
      $response = new CacheableJsonResponse(['status' => 'error', 'message' => 'Access denied', 'accessible' => false], 403);
      $response->addCacheableDependency($cache_metadata);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Cookie, Authorization');
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }

    $eaf_result = $this->getEafContent($node, $cache_metadata);
    $eaf_xml = $eaf_result['content'];
    
    $locations = [];
    


    $parent_nid = $node->hasField('field_member_of') ? $node->get('field_member_of')->target_id : NULL;
    $candidate_nids = $parent_nid ? [$parent_nid] : [$node->id()];
    if ($parent_nid) {
      $siblings = \Drupal::entityQuery('node')
        ->condition('field_member_of', $parent_nid)
        ->accessCheck(FALSE)
        ->execute();
      $candidate_nids = array_unique(array_merge($candidate_nids, array_values($siblings)));
    }

    $service_locations = [];
    $original_locations = [];

    foreach ($candidate_nids as $nid) {
      $candidate = \Drupal\node\Entity\Node::load($nid);
      if (!$candidate) continue;
      $cache_metadata->addCacheableDependency($candidate);
      $candidate_access = $candidate->access('view', NULL, TRUE);
      $cache_metadata->addCacheableDependency($candidate_access);
      if (!$candidate_access->isAllowed()) continue;
      $mids = \Drupal::entityQuery('media')
        ->condition('field_media_of', $nid)
        ->accessCheck(TRUE)
        ->execute();
      
      if (empty($mids)) continue;

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
              if (in_array($label, ['Original File', 'Service File'])) {
                $media_use = $label;
                break;
              }
            }
          } else {
            $val = $media->get('field_media_use')->value;
            if (in_array($val, ['Original File', 'Service File'])) {
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

            $mimetype = $file->getMimeType();
            
            if (in_array($mimetype, ['video/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'video/webm', 'video/ogg'])) {
              $location = [
                'mimetype' => $mimetype,
                'url' => $url,
                'label' => $media->label(),
                'visualization_url' => \Drupal\Core\Url::fromRoute('flat_media_visualization.data', [
                  'node' => $nid, 'media' => $media->id(), 'kind' => 'status',
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

    // Use Service Files by default if any exist across all candidates, fallback to Original Files.
    $locations = !empty($service_locations) ? $service_locations : $original_locations;

    $response_data = [
      'pid' => $node->id(),
      'accessible' => $eaf_result['status'] === 'ok',
      'locations' => $locations,
      'annotation' => null,
    ];

    if ($eaf_result['status'] !== 'ok') {
      $status_code = $eaf_result['status'] === 'forbidden' ? 403 : 502;
      $response_data['status'] = 'error';
      $response_data['message'] = $eaf_result['status'] === 'forbidden'
        ? 'Access denied'
        : 'Unable to read annotation file';
      $response = new CacheableJsonResponse($response_data, $status_code);
      $response->addCacheableDependency($cache_metadata);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Cookie, Authorization');
      return $response;
    }

    if (!empty($eaf_xml)) {
      try {
        $xml_element = simplexml_load_string($eaf_xml);
        if ($xml_element !== FALSE) {
          $parser = new Parser($xml_element, new MediaResolver($locations));
          $response_data['annotation'] = $parser->parse();
        }
        else {
          \Drupal::logger('flat_annotation_viewer')->error('Invalid EAF XML for node @nid.', ['@nid' => $node->id()]);
        }
      } catch (\Exception $e) {
        \Drupal::logger('flat_annotation_viewer')->error('Failed to parse EAF for node @nid: @message', [
          '@nid' => $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $response = new CacheableJsonResponse($response_data);
    $response->addCacheableDependency($cache_metadata);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Cookie, Authorization');
    return $response;
  }

  /**
   * Loads the EAF after Drupal access checks, using the trusted Fedora reader.
   *
   * @return array{status: string, content: string|null}
   */
  protected function getEafContent(NodeInterface $node, CacheableMetadata $cache_metadata): array {
    $mids = \Drupal::entityQuery('media')
      ->condition('field_media_of', $node->id())
      ->condition('bundle', 'annotation')
      ->accessCheck(FALSE) // We check access manually below
      ->execute();
    
    if (!empty($mids)) {
      $has_forbidden = false;
      foreach (Media::loadMultiple($mids) as $media) {
        $cache_metadata->addCacheableDependency($media);
        $media_access = $media->access('view', NULL, TRUE);
        $cache_metadata->addCacheableDependency($media_access);
        if (!$media_access->isAllowed()) {
          $has_forbidden = true;
          continue;
        }
        $source_field = $media->getSource()->getConfiguration()['source_field'] ?? 'field_media_file';
        if ($media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
          $file = $media->get($source_field)->entity;
          if ($file instanceof File) {
            $cache_metadata->addCacheableDependency($file);
            $file_access = $file->access('download', NULL, TRUE);
            $cache_metadata->addCacheableDependency($file_access);
            if (!$file_access->isAllowed()) {
              $has_forbidden = true;
              continue;
            }
            $uri = $file->getFileUri();
            if (!$uri) {
              continue;
            }
            $content = $this->fedoraReader->runAs(static fn() => @file_get_contents($uri));
            if ($content === FALSE) {
              $this->getLogger('flat_annotation_viewer')->error('Unable to read EAF file @fid from @uri.', [
                '@fid' => $file->id(),
                '@uri' => $uri,
              ]);
              return ['status' => 'unavailable', 'content' => NULL];
            }
            return ['status' => 'ok', 'content' => $content];
          }
        }
      }
      return ['status' => $has_forbidden ? 'forbidden' : 'unavailable', 'content' => NULL];
    }
    return ['status' => 'unavailable', 'content' => NULL];
  }
}
