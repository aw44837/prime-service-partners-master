<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Choose Your Location" button that expands a dropdown of every market.
 *
 * Each row links to the market's landing page (field_path_prefix), in
 * chooser (weight) order, so market additions/removals propagate
 * automatically. Settings-free so Canvas auto-exposes it as a component.
 *
 * @Block(
 *   id = "psp_area_location_selector",
 *   admin_label = @Translation("Choose Your Location selector"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaLocationSelectorBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $locations = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', AreaResolver::VOCABULARY)
      ->sort('weight')
      ->sort('name')
      ->execute();
    foreach ($storage->loadMultiple($tids) as $term) {
      $prefix = trim((string) $term->get('field_path_prefix')->value, "/ \t\n");
      if ($prefix === '') {
        continue;
      }
      $locations[] = [
        'label' => $term->getName(),
        'url' => '/' . $prefix,
      ];
    }

    return [
      '#theme' => 'psp_location_selector',
      '#locations' => $locations,
      '#attached' => ['library' => ['psp_service_area/location_selector']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['taxonomy_term_list:' . AreaResolver::VOCABULARY]);
  }

}
