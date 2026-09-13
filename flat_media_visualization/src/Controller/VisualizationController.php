<?php

namespace Drupal\flat_media_visualization\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Serves derivatives through the same access boundary as their source media. */
class VisualizationController extends ControllerBase {

  public static function source(MediaInterface $media): ?FileInterface {
    $field = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    $file = $field && $media->hasField($field) ? $media->get($field)->entity : NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  public function access(NodeInterface $node, MediaInterface $media, AccountInterface $account) {
    $parents = $media->hasField('field_media_of') ? array_column($media->get('field_media_of')->getValue(), 'target_id') : [];
    $file = self::source($media);
    $allowed = $file && in_array($node->id(), $parents) && preg_match('@^(audio|video)/@', $file->getMimeType());
    $access = AccessResult::allowedIf((bool) $allowed)->addCacheableDependency($media)->setCacheMaxAge(0);
    if (!$allowed) return $access;
    return $access->andIf($node->access('view', $account, TRUE))
      ->andIf($media->access('view', $account, TRUE))
      ->andIf($file->access('download', $account, TRUE));
  }

  public function data(NodeInterface $node, MediaInterface $media, string $kind, Request $request) {
    \Drupal::service('page_cache_kill_switch')->trigger();
    $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie, Authorization, Accept-Encoding'];
    $generator = \Drupal::service('flat_media_visualization.generator');
    $file = self::source($media);
    try {
      $key = $generator->key($file);
      if ($request->query->has('v') && $request->query->get('v') !== $key) {
        return new JsonResponse(['status' => 'failed', 'message' => 'The source changed. Reload the viewer.'], 409, $headers);
      }
      $status = $generator->request($file);
      if ($status['status'] !== 'ready') {
        $headers['Retry-After'] = '5';
        return new JsonResponse($status, $status['status'] === 'failed' ? 503 : 202, $headers);
      }
      if ($kind === 'status') {
        $parameters = ['node' => $node->id(), 'media' => $media->id()];
        $status['peaks_url'] = \Drupal\Core\Url::fromRoute('flat_media_visualization.data', $parameters + ['kind' => 'peaks'], ['query' => ['v' => $key]])->toString();
        $status['spectrogram_url'] = \Drupal\Core\Url::fromRoute('flat_media_visualization.data', $parameters + ['kind' => 'spectrogram'], ['query' => ['v' => $key]])->toString();
        return new JsonResponse($status, 200, $headers);
      }
      $path = $generator->directory($key) . '/' . $kind . '.json';
      $headers['Content-Type'] = 'application/json';
      $headers['X-Content-Type-Options'] = 'nosniff';
      $gzip = \Symfony\Component\HttpFoundation\AcceptHeader::fromString($request->headers->get('Accept-Encoding', ''))->get('gzip');
      if ($gzip && $gzip->getQuality() > 0 && is_file($path . '.gz')) {
        $path .= '.gz';
        $headers['Content-Encoding'] = 'gzip';
      }
      return new BinaryFileResponse($path, 200, $headers, FALSE);
    }
    catch (\Throwable $error) {
      $this->getLogger('flat_media_visualization')->error('Visualization request failed: @error', ['@error' => $error->getMessage()]);
      return new JsonResponse(['status' => 'failed', 'message' => 'Visualization generation is unavailable. Please contact the site administrator.'], 503, $headers);
    }
  }
}
