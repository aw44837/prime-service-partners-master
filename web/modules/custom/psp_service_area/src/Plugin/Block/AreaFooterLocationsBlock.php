<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Footer directory of every market — core (non-market) pages only.
 *
 * Bold market name linking to the landing page, address lines, and a
 * Google Business profile link (the term's Map link). Renders nothing
 * inside a market, where the single-location footer info block covers
 * it. Placed in footer_top (full width, 5x2 grid on desktop).
 *
 * @Block(
 *   id = "psp_area_footer_locations",
 *   admin_label = @Translation("Footer all-markets directory"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaFooterLocationsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected AreaResolver $areaResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->areaResolver = $container->get('psp_service_area.area_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if ($this->areaResolver->resolve()) {
      return [];
    }

    $locations = [];
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', AreaResolver::VOCABULARY)
      ->sort('weight')
      ->sort('name')
      ->execute();
    foreach ($storage->loadMultiple($tids) as $market) {
      $prefix = trim((string) $market->get('field_path_prefix')->value, "/ \t\n");
      if ($prefix === '') {
        continue;
      }
      $address = $market->get('field_address')->value;
      $locations[] = [
        'label' => $market->getName(),
        'url' => '/' . $prefix,
        'address_lines' => $address ? preg_split('/\r\n|\r|\n/', $address) : [],
        'profile_url' => $market->get('field_map_link')->uri,
      ];
    }

    return [
      '#theme' => 'psp_footer_locations',
      '#locations' => $locations,
      '#attached' => ['library' => ['psp_service_area/footer_locations']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['service_area']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['taxonomy_term_list:' . AreaResolver::VOCABULARY]);
  }

}
