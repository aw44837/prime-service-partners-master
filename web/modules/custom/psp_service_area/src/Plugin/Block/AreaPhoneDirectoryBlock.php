<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Call Today!" button that expands a dropdown of every market's phone.
 *
 * For core (non-market) pages — market names and numbers come from the
 * service_area terms in chooser (weight) order, so number changes and
 * market additions/removals propagate automatically. Settings-free so
 * Canvas auto-exposes it as a component.
 *
 * @Block(
 *   id = "psp_area_phone_directory",
 *   admin_label = @Translation("Call Today! market phone directory"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaPhoneDirectoryBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $markets = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', AreaResolver::VOCABULARY)
      ->sort('weight')
      ->sort('name')
      ->execute();
    foreach ($storage->loadMultiple($tids) as $term) {
      $phone = $term->get('field_phone')->value;
      if (!$phone) {
        continue;
      }
      $markets[] = [
        'label' => $term->getName(),
        'phone' => $phone,
        'tel' => 'tel:' . preg_replace('/[^0-9+\-]/', '', $phone),
      ];
    }

    return [
      '#theme' => 'psp_phone_directory',
      '#markets' => $markets,
      '#attached' => ['library' => ['psp_service_area/phone_directory']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['taxonomy_term_list:' . AreaResolver::VOCABULARY]);
  }

}
