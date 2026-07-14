<?php

namespace Drupal\psp_service_area\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\psp_service_area\AreaResolver;

/**
 * Cache context that varies by the active service area.
 *
 * Cache context ID: 'service_area'.
 */
class ServiceAreaCacheContext implements CacheContextInterface {

  public function __construct(protected AreaResolver $areaResolver) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Service area');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $term = $this->areaResolver->resolve();
    return $term ? (string) $term->id() : '0';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    // The prefix map (and thus the resolved value) depends on the set of
    // service_area terms.
    return (new CacheableMetadata())->setCacheTags(['taxonomy_term_list:' . AreaResolver::VOCABULARY]);
  }

}
