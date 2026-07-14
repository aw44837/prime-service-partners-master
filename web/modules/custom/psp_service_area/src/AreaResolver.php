<?php

namespace Drupal\psp_service_area;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves the active service area for the current request or an entity.
 *
 * Resolution order:
 * 1. The routed entity's field_service_area value (authoritative).
 * 2. The first segment of the current path alias, matched against the
 *    service_area terms' field_path_prefix (covers Canvas pages and any
 *    other content living under an area's URL prefix).
 */
class AreaResolver {

  public const VOCABULARY = 'service_area';

  /**
   * Per-request cache of the resolved area (FALSE = not yet resolved).
   */
  protected TermInterface|null|false $resolved = FALSE;

  /**
   * Map of path prefix => term id, built once per request.
   *
   * @var array<string, int>|null
   */
  protected ?array $prefixMap = NULL;

  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected CurrentPathStack $currentPath,
    protected AliasManagerInterface $aliasManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves the active service area for the current request.
   */
  public function resolve(): ?TermInterface {
    if ($this->resolved !== FALSE) {
      return $this->resolved;
    }
    $this->resolved = NULL;

    // 1. Routed entity's own field wins.
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        if ($term = $this->resolveFromEntity($parameter)) {
          return $this->resolved = $term;
        }
      }
    }

    // 2. Fall back to the URL prefix.
    $alias = $this->aliasManager->getAliasByPath($this->currentPath->getPath());
    return $this->resolved = $this->resolveFromPath($alias);
  }

  /**
   * Resolves the service area from an entity's field_service_area field.
   */
  public function resolveFromEntity(?FieldableEntityInterface $entity): ?TermInterface {
    if ($entity === NULL || !$entity->hasField('field_service_area') || $entity->get('field_service_area')->isEmpty()) {
      return NULL;
    }
    $term = $entity->get('field_service_area')->entity;
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Resolves the service area from a path's first segment.
   */
  public function resolveFromPath(string $path): ?TermInterface {
    $segments = explode('/', ltrim($path, '/'));
    $prefix = $segments[0] ?? '';
    if ($prefix === '') {
      return NULL;
    }
    $map = $this->getPrefixMap();
    if (!isset($map[$prefix])) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($map[$prefix]);
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Builds the path prefix => term id map.
   *
   * @return array<string, int>
   */
  protected function getPrefixMap(): array {
    if ($this->prefixMap !== NULL) {
      return $this->prefixMap;
    }
    $this->prefixMap = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->exists('field_path_prefix')
      ->execute();
    if ($tids) {
      foreach ($storage->loadMultiple($tids) as $term) {
        $prefix = trim((string) $term->get('field_path_prefix')->value, "/ \t\n");
        if ($prefix !== '') {
          $this->prefixMap[$prefix] = (int) $term->id();
        }
      }
    }
    return $this->prefixMap;
  }

  /**
   * Returns a value from the active area's term, or a module-settings default.
   *
   * @param \Drupal\taxonomy\TermInterface|null $term
   *   The area term, or NULL to use defaults.
   * @param string $field_name
   *   Term field to read.
   * @param string $settings_key
   *   psp_service_area.settings key used when the term is missing/empty.
   * @param string $property
   *   The field property to read (value, uri, target_id, ...).
   */
  public function areaValue(?TermInterface $term, string $field_name, string $settings_key, string $property = 'value'): ?string {
    if ($term && $term->hasField($field_name) && !$term->get($field_name)->isEmpty()) {
      $value = $term->get($field_name)->first()->get($property)->getValue();
      if ($value !== NULL && $value !== '') {
        return (string) $value;
      }
    }
    $default = \Drupal::config('psp_service_area.settings')->get($settings_key);
    return $default !== NULL && $default !== '' ? (string) $default : NULL;
  }

}
