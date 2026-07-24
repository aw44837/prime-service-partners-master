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
      $links[] = '- [' . $label . '](' . $url . ($md_links ? '.md' : '') . ')';
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
