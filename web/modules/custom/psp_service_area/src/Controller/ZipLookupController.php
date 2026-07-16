<?php

namespace Drupal\psp_service_area\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\psp_service_area\AreaResolver;

/**
 * ZIP code -> service area lookup for the location bar.
 */
class ZipLookupController extends ControllerBase {

  /**
   * Returns {found, area, label, url} for a 5-digit ZIP.
   */
  public function lookup(string $zip) {
    $map = $this->config('psp_service_area.zip_map')->get('map') ?? [];
    $prefix = $map[$zip] ?? NULL;

    $payload = ['found' => FALSE, 'zip' => $zip];
    if ($prefix) {
      $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'vid' => AreaResolver::VOCABULARY,
        'field_path_prefix' => $prefix,
      ]);
      if ($term = reset($terms)) {
        $payload = [
          'found' => TRUE,
          'zip' => $zip,
          'area' => $prefix,
          'label' => $term->getName(),
          'url' => '/' . $prefix,
        ];
      }
    }

    $response = new CacheableJsonResponse($payload);
    $meta = new CacheableMetadata();
    $meta->setCacheTags(['config:psp_service_area.zip_map', 'taxonomy_term_list:' . AreaResolver::VOCABULARY]);
    $meta->setCacheContexts(['url.path']);
    $response->addCacheableDependency($meta);
    return $response;
  }

}
