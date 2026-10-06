<?php

namespace Drupal\psp_brands\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * /brands — every brand page, grouped by equipment category.
 */
class BrandIndexController extends ControllerBase {

  /**
   * Builds the brand index.
   */
  public function index(): array {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'brand_page')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('title')
      ->execute();

    // Brand entry per node, remembering its equipment category tids.
    $brands = [];
    foreach ($node_storage->loadMultiple($nids) as $node) {
      $intro = '';
      if (!$node->get('field_intro_description')->isEmpty()) {
        $intro = trim(strip_tags($node->get('field_intro_description')->value ?? ''));
      }
      $brands[$node->id()] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'intro' => $intro,
        'categories' => array_column($node->get('field_equipment_categories')->getValue(), 'target_id'),
      ];
    }

    // Group by equipment category (weight order); a brand may repeat.
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $groups = [];
    if ($brands) {
      $terms = $term_storage->loadByProperties(['vid' => 'equipment_category']);
      uasort($terms, fn($a, $b) => [$a->getWeight(), $a->getName()] <=> [$b->getWeight(), $b->getName()]);
      foreach ($terms as $term) {
        $members = array_filter($brands, fn($brand) => in_array($term->id(), $brand['categories']));
        if ($members) {
          $groups[] = ['label' => $term->getName(), 'brands' => array_values($members)];
        }
      }
      $uncategorized = array_filter($brands, fn($brand) => !$brand['categories']);
      if ($uncategorized) {
        $groups[] = [
          'label' => $groups ? (string) $this->t('More brands') : NULL,
          'brands' => array_values($uncategorized),
        ];
      }
    }

    return [
      '#theme' => 'psp_brand_index',
      '#groups' => $groups,
      '#attached' => ['library' => ['psp_brands/brand_index']],
      '#cache' => [
        'tags' => ['node_list:brand_page', 'taxonomy_term_list:equipment_category'],
      ],
    ];
  }

}
