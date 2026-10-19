<?php

declare(strict_types=1);

namespace Drupal\psp_seo\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;

/**
 * Serves /llms.txt: a Markdown index of the site for AI agents.
 *
 * Built from entity queries at request time and cached with the node and
 * canvas_page list tags, so it stays current as content is created, edited
 * or deleted — no regeneration step. Entries link to Markdownify's `.md`
 * variants when markdownify_path is enabled (canvas_page entities always
 * link to their HTML URL; Markdownify has no route for them).
 */
class LlmsTxtController extends ControllerBase {

  public function build(): CacheableResponse {
    $settings = $this->config('psp_seo.settings');
    $site = $this->config('system.site');
    $md_links = $this->moduleHandler()->moduleExists('markdownify_path');

    $name = $settings->get('company_name') ?: $site->get('name');
    $lines = ['# ' . $name, ''];
    if ($description = ($settings->get('llms_description') ?: $site->get('slogan'))) {
      $lines[] = '> ' . $description;
      $lines[] = '';
    }
    array_push($lines, ...$this->keyFacts());
    if ($md_links) {
      $lines[] = 'Links below point to Markdown versions of each page; remove the `.md` suffix for the HTML version.';
      $lines[] = '';
    }

    $sections = [
      'Services' => $this->nodeLinks('services', $md_links),
      'Service areas' => $this->nodeLinks('local_service_area_page', $md_links),
      'Pages' => array_merge($this->canvasPageLinks(), $this->nodeLinks('page', $md_links)),
      'Blog' => $this->nodeLinks('article', $md_links, 'created'),
    ];
    foreach ($sections as $label => $links) {
      if (!$links) {
        continue;
      }
      $lines[] = '## ' . $label;
      $lines[] = '';
      array_push($lines, ...$links);
      $lines[] = '';
    }

    $response = new CacheableResponse(implode("\n", $lines), 200, [
      'Content-Type' => 'text/markdown; charset=UTF-8',
    ]);
    $meta = new CacheableMetadata();
    $tags = ['node_list', 'config:psp_seo.settings', 'config:system.site'];
    if ($this->entityTypeManager()->hasDefinition('canvas_page')) {
      $tags[] = 'canvas_page_list';
    }
    $meta->addCacheTags($tags);
    $meta->addCacheContexts(['url.site']);
    $response->addCacheableDependency($meta);
    return $response;
  }

  /**
   * Builds the "Key facts" (and, on multi-market sites, "Locations") sections.
   *
   * Lets an AI agent answer the common questions — phone, address, hours,
   * area served, trades, how to book — from one fetch, using the same
   * psp_seo.settings values that feed the schema.org markup.
   *
   * @return string[]
   */
  protected function keyFacts(): array {
    $s = $this->config('psp_seo.settings');
    if (!$s->get('company_name')) {
      return [];
    }
    $trades = [
      'Plumber' => 'plumbing',
      'HVACBusiness' => 'heating & air conditioning (HVAC)',
      'Electrician' => 'electrical',
      'RoofingContractor' => 'roofing',
    ];
    $facts = [];
    $offered = array_filter(array_map(fn($t) => $trades[$t] ?? NULL, (array) $s->get('business_types')));
    if ($offered) {
      $facts[] = 'Services: ' . implode(', ', $offered);
    }
    if ($phone = $s->get('phone')) {
      $facts[] = 'Phone: ' . $phone;
    }
    if ($address = $s->get('address')) {
      $facts[] = 'Address: ' . preg_replace('/\s*\R\s*/', ', ', trim($address));
    }
    $hours = array_values(array_filter((array) $s->get('opening_hours')));
    if ($hours) {
      $facts[] = 'Hours: ' . ($hours === ['Mo-Su 00:00-23:59'] ? 'open 24/7' : implode('; ', $hours));
    }
    if ($areas = array_values(array_filter((array) $s->get('area_served')))) {
      $facts[] = 'Areas served: ' . implode(', ', $areas);
    }
    if (($booking = $s->get('booking_url')) && preg_match('@^(https?://|/)@', $booking)) {
      $facts[] = 'Book online: ' . (str_starts_with($booking, '/') ? \Drupal::request()->getSchemeAndHttpHost() . $booking : $booking);
    }
    foreach (array_filter((array) $s->get('llms_facts')) as $fact) {
      $facts[] = $fact;
    }
    if ($profiles = array_values(array_filter((array) $s->get('same_as')))) {
      $facts[] = 'Profiles: ' . implode(', ', $profiles);
    }
    $lines = [];
    if ($facts) {
      $lines[] = '## Key facts';
      $lines[] = '';
      foreach ($facts as $fact) {
        $lines[] = '- ' . $fact;
      }
      $lines[] = '';
    }

    // Multi-market sites: one line per market office (psp_service_area).
    if ($this->moduleHandler()->moduleExists('psp_service_area')) {
      $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadTree('service_area', 0, NULL, TRUE);
      $offices = [];
      foreach ($terms as $term) {
        $parts = [$term->label()];
        foreach (['field_phone' => 'phone', 'field_address' => 'address'] as $field => $label) {
          if ($term->hasField($field) && ($v = trim((string) $term->get($field)->value)) !== '') {
            $parts[] = $label . ' ' . preg_replace('/\s*\R\s*/', ', ', $v);
          }
        }
        if ($term->hasField('field_path_prefix') && ($prefix = trim((string) $term->get('field_path_prefix')->value, "/ \t\n"))) {
          $parts[] = \Drupal::request()->getSchemeAndHttpHost() . '/' . $prefix;
        }
        $offices[] = '- ' . implode(' — ', $parts);
      }
      if ($offices) {
        array_push($lines, '## Locations', '', ...$offices);
        $lines[] = '';
      }
    }
    return $lines;
  }

  /**
   * Builds "- [Title](url)" lines for published nodes of a bundle.
   *
   * @return string[]
   */
  protected function nodeLinks(string $bundle, bool $md_links, string $sort = 'title'): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->sort($sort, $sort === 'created' ? 'DESC' : 'ASC')
      ->accessCheck(TRUE)
      ->execute();
    $links = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      $label = $node->label();
      // Multi-market sites repeat service titles per market; disambiguate
      // with the market name so entries stay unique for an LLM.
      if ($node->hasField('field_service_area') && !$node->get('field_service_area')->isEmpty()
        && ($term = $node->get('field_service_area')->entity)) {
        $label .= ' — ' . $term->label();
      }
      $line = '- [' . $label . '](' . $url . ($md_links ? '.md' : '') . ')';
      // The page's quick answer (intro under the H1), as llms.txt link notes.
      if ($intro = _psp_seo_node_intro($node)) {
        $line .= ': ' . $intro;
      }
      $links[] = $line;
    }
    if ($sort === 'title') {
      sort($links);
    }
    return $links;
  }

  /**
   * Builds "- [Title](url)" lines for published Canvas pages.
   *
   * @return string[]
   */
  protected function canvasPageLinks(): array {
    if (!$this->entityTypeManager()->hasDefinition('canvas_page')) {
      return [];
    }
    $storage = $this->entityTypeManager()->getStorage('canvas_page');
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->sort('title')
      ->accessCheck(TRUE)
      ->execute();
    $links = [];
    foreach ($storage->loadMultiple($ids) as $page) {
      $url = $page->toUrl('canonical', ['absolute' => TRUE])->toString();
      $links[] = '- [' . $page->label() . '](' . $url . ')';
    }
    return $links;
  }

}
