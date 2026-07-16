<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Skinny location bar for the header_above region.
 *
 * Inside a service area, the bar shows that area (server-rendered — area
 * pages already cache-vary via the service_area context). On global pages
 * it renders a neutral shell; JS reads the psp_area cookie and swaps in
 * the remembered location, keeping the page cache clean. The chooser
 * panel offers a ZIP lookup plus direct links to every location.
 *
 * @Block(
 *   id = "psp_area_location_bar",
 *   admin_label = @Translation("Service area location bar"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class LocationBarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected AreaResolver $areaResolver;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->areaResolver = $container->get('psp_service_area.area_resolver');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $term = $this->areaResolver->resolve();

    // All locations for the chooser panel.
    $locations = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', AreaResolver::VOCABULARY)
      ->sort('name')
      ->execute();
    foreach ($storage->loadMultiple($tids) as $location) {
      $prefix = $location->get('field_path_prefix')->value;
      if ($prefix) {
        $locations[] = ['label' => $location->getName(), 'prefix' => $prefix, 'url' => '/' . $prefix];
      }
    }

    $settings = \Drupal::config('psp_service_area.settings');

    return [
      '#theme' => 'psp_location_bar',
      '#current' => $term ? [
        'label' => $term->getName(),
        'prefix' => $term->get('field_path_prefix')->value,
      ] : NULL,
      '#locations' => $locations,
      '#fallback_phone' => $settings->get('default_phone_display'),
      '#fallback_phone_uri' => $settings->get('default_phone_uri'),
      '#attached' => [
        'library' => ['psp_service_area/location_bar'],
        'drupalSettings' => [
          'pspServiceArea' => [
            'currentArea' => $term ? $term->get('field_path_prefix')->value : NULL,
            'currentLabel' => $term ? $term->getName() : NULL,
          ],
        ],
      ],
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
    return Cache::mergeTags(parent::getCacheTags(), [
      'taxonomy_term_list:' . AreaResolver::VOCABULARY,
      'config:psp_service_area.settings',
    ]);
  }

}
