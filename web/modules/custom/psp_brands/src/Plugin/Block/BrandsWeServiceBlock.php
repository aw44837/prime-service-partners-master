<?php

namespace Drupal\psp_brands\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Brands we service" — link strip to brand pages.
 *
 * On a services node tagged with brands (field_brand), links to those brands'
 * pages; on an untagged services node or any other page, links to every
 * published brand page. On a brand page itself, lists the *other* brands.
 * Renders nothing when no brand pages exist, so templates can place it
 * unconditionally.
 *
 * @Block(
 *   id = "psp_brands_we_service",
 *   admin_label = @Translation("Brand: brands we service"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class BrandsWeServiceBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->routeMatch->getParameter('node');
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'brand_page')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('title');

    if ($node instanceof NodeInterface) {
      if ($node->bundle() === 'brand_page') {
        $query->condition('nid', $node->id(), '<>');
      }
      elseif ($node->hasField('field_brand') && ($tids = array_column($node->get('field_brand')->getValue(), 'target_id'))) {
        $query->condition('field_brand.target_id', $tids, 'IN');
      }
    }

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $links = [];
    foreach ($storage->loadMultiple($nids) as $brand_page) {
      $links[] = [
        'title' => $brand_page->label(),
        'url' => $brand_page->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'psp_brands_we_service',
      '#heading' => $node instanceof NodeInterface && $node->bundle() === 'brand_page'
        ? $this->t('Other Brands We Service')
        : $this->t('Brands We Service'),
      '#links' => $links,
      '#attached' => ['library' => ['psp_brands/brand_links']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = ['node_list:brand_page'];
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return Cache::mergeTags(parent::getCacheTags(), $tags);
  }

}
