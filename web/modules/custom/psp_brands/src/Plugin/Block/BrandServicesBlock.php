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
 * "Our [Brand] services" — automatic crosslinks on a brand page.
 *
 * Lists every published services node tagged (field_brand) with the brand
 * term(s) of the brand_page being viewed. Renders nothing elsewhere or when
 * no services are tagged yet, so the Canvas template can place it
 * unconditionally.
 *
 * @Block(
 *   id = "psp_brand_services",
 *   admin_label = @Translation("Brand: services for this brand"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class BrandServicesBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    if (!$node instanceof NodeInterface || $node->bundle() !== 'brand_page') {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');

    // Curated picks first (field_related_services), then every published
    // services node tagged with this page's brand term(s).
    $featured = [];
    foreach ($node->get('field_related_services')->referencedEntities() as $service) {
      if ($service->isPublished() && $service->access('view')) {
        $featured[$service->id()] = [
          'title' => $service->label(),
          'url' => $service->toUrl()->toString(),
        ];
      }
    }

    $links = [];
    if ($tids = array_column($node->get('field_brand')->getValue(), 'target_id')) {
      $nids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'services')
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('field_brand.target_id', $tids, 'IN')
        ->sort('title')
        ->execute();
      foreach ($storage->loadMultiple(array_diff($nids, array_keys($featured))) as $service) {
        $links[] = [
          'title' => $service->label(),
          'url' => $service->toUrl()->toString(),
        ];
      }
    }

    if (!$featured && !$links) {
      return [];
    }

    return [
      '#theme' => 'psp_brand_services',
      '#heading' => $this->t('Our @brand Services', ['@brand' => $node->label()]),
      '#featured' => array_values($featured),
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
    $tags = ['node_list:services'];
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return Cache::mergeTags(parent::getCacheTags(), $tags);
  }

}
