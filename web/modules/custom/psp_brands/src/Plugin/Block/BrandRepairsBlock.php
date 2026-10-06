<?php

namespace Drupal\psp_brands\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Common [Brand] repairs" — the field_common_repairs list on a brand page.
 *
 * Canvas prop expressions render a single field delta, so this multi-value
 * field gets a block instead. Renders nothing off brand pages or when the
 * field is empty.
 *
 * @Block(
 *   id = "psp_brand_repairs",
 *   admin_label = @Translation("Brand: common repairs list"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class BrandRepairsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
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
    $repairs = array_column($node->get('field_common_repairs')->getValue(), 'value');
    if (!$repairs) {
      return [];
    }

    return [
      '#theme' => 'psp_brand_repairs',
      '#heading' => $this->t('Common @brand Repairs', ['@brand' => $node->label()]),
      '#repairs' => $repairs,
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
    $tags = parent::getCacheTags();
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return $tags;
  }

}
