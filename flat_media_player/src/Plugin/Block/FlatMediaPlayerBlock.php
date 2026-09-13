<?php
namespace Drupal\flat_media_player\Plugin\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * @Block(id = "flat_media_player_block", admin_label = @Translation("FLAT Media Player"))
 */
class FlatMediaPlayerBlock extends BlockBase implements ContainerFactoryPluginInterface {
  protected $routeMatch;
  public function __construct(array $config, $plugin_id, $plugin_def, RouteMatchInterface $route_match) {
    parent::__construct($config, $plugin_id, $plugin_def);
    $this->routeMatch = $route_match;
  }
  public static function create(ContainerInterface $container, array $config, $plugin_id, $plugin_def) {
    return new static($config, $plugin_id, $plugin_def, $container->get("current_route_match"));
  }
  public function build() {
    try {
      $node = $this->routeMatch->getParameter("node");
      if (!$node) $node = \Drupal::request()->attributes->get("node");
      if (is_numeric($node)) $node = \Drupal\node\Entity\Node::load($node);
      
      if (!($node instanceof NodeInterface)) {
        return ["#markup" => "<!-- NO NODE FOUND in FlatMediaPlayerBlock -->"];
      }

      $url = \Drupal\Core\Url::fromRoute("flat_media_player.api", ["node" => $node->id()])->toString();

      return [
        "#markup" => '<div id="flat-media-player"><!-- Node: ' . $node->id() . ' --></div>',
        "#attached" => [
          "library" => ["flat_media_player/media-player"],
          "drupalSettings" => [
            "flat_media_player" => [
              "apiUrl" => $url,
            ],
          ],
        ],
        "#cache" => [
          "contexts" => ["url.path"],
          "tags" => ["node:" . $node->id()],
        ],
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('flat_media_player')->error('Media player block error: @message', ['@message' => $e->getMessage()]);
      return [
        "#markup" => '<div class="messages messages--error">' . $this->t('The media player could not be loaded.') . '</div>'
      ];
    }
  }
}
