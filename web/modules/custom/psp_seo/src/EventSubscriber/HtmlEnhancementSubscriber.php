<?php

declare(strict_types=1);

namespace Drupal\psp_seo\EventSubscriber;

use Drupal\Core\Routing\AdminContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Final-HTML SEO fixes that depend on what the page actually rendered.
 *
 * - One H1 per page: Canvas hero headings render the page H1, but body text
 *   migrated from the old sites often carries its own <h1>. When a Canvas
 *   heading H1 is present, any other <h1> is demoted to <h2>. Pages whose only
 *   H1 lives in body text are left alone.
 * - FAQPage JSON-LD: a heading reading "FAQ" / "Frequently Asked Questions"
 *   followed by question headings (or bold "…?" paragraphs) and answer
 *   paragraphs is emitted as schema.org FAQPage, unless the page already
 *   carries FAQPage markup (e.g. from psp_services).
 *
 * Both operate on the response so they work regardless of which component,
 * field or template produced the markup. The page cache stores the result.
 */
final class HtmlEnhancementSubscriber implements EventSubscriberInterface {

  private const HERO_H1 = '<h1 data-component-id="dripyard_base:heading"';

  public function __construct(
    protected readonly AdminContext $adminContext,
  ) {}

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $this->adminContext->isAdminRoute()) {
      return;
    }
    $response = $event->getResponse();
    if (stripos((string) $response->headers->get('Content-Type', ''), 'text/html') === FALSE) {
      return;
    }
    $html = $response->getContent();
    if (!is_string($html) || stripos($html, '<main') === FALSE) {
      return;
    }

    $new = $this->demoteExtraH1s($html);
    $new = $this->addFaqSchema($new);
    if ($new !== $html) {
      $response->setContent($new);
    }
  }

  /**
   * Demotes body-text <h1>s to <h2> when a Canvas hero heading H1 exists.
   */
  private function demoteExtraH1s(string $html): string {
    if (substr_count($html, self::HERO_H1) !== 1) {
      return $html;
    }
    $out = preg_replace_callback('#<h1(?![^>]*data-component-id="dripyard_base:heading")(\s[^>]*)?>(.*?)</h1>#si',
      static fn(array $m): string => '<h2' . ($m[1] ?? '') . '>' . $m[2] . '</h2>',
      $html);
    return is_string($out) ? $out : $html;
  }

  /**
   * Builds FAQPage JSON-LD from a rendered FAQ section and adds it to <head>.
   */
  private function addFaqSchema(string $html): string {
    if (stripos($html, 'FAQPage') !== FALSE || !preg_match('#<h[2-4][^>]*>\s*(?:<[^>]+>\s*)*(?:FAQs?\b|Frequently Asked Questions)#i', $html)) {
      return $html;
    }
    $start = stripos($html, '<main');
    $end = stripos($html, '</main>', $start);
    if ($end === FALSE) {
      return $html;
    }
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . substr($html, $start, $end - $start + 7) . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $items = [];
    $xpath = new \DOMXPath($doc);
    foreach ($xpath->query('//h2|//h3|//h4') as $heading) {
      if (!preg_match('/^\s*(FAQs?\b|Frequently Asked Questions)/i', $heading->textContent)) {
        continue;
      }
      $level = (int) substr($heading->nodeName, 1);
      $question = NULL;
      $answer = [];
      for ($node = $heading->nextSibling; $node; $node = $node->nextSibling) {
        if (!$node instanceof \DOMElement) {
          continue;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $is_heading = (bool) preg_match('/^h([1-6])$/', $node->nodeName, $hm);
        if ($is_heading && (int) $hm[1] <= $level) {
          break;
        }
        $bold_only = $node->nodeName === 'p' && $this->isBoldOnly($node);
        if (($is_heading || $bold_only) && str_ends_with($text, '?')) {
          $this->pushFaq($items, $question, $answer);
          $question = preg_replace('/^\d+[.)]\s*/', '', $text);
          $answer = [];
        }
        elseif ($question && $node->nodeName === 'p' && !$bold_only && $text !== '' && !preg_match('/^The post .+ appeared first on /', $text)) {
          $answer[] = $text;
        }
        elseif ($is_heading || $bold_only) {
          // A non-question heading or CTA line ends the current answer.
          $this->pushFaq($items, $question, $answer);
          $question = NULL;
        }
      }
      $this->pushFaq($items, $question, $answer);
    }
    if (count($items) < 2) {
      return $html;
    }

    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'FAQPage',
      'mainEntity' => $items,
    ];
    $script = '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    $pos = stripos($html, '</head>');
    return $pos === FALSE ? $html : substr_replace($html, $script . "\n", $pos, 0);
  }

  private function isBoldOnly(\DOMElement $p): bool {
    $bold = '';
    foreach ($p->getElementsByTagName('strong') as $s) {
      $bold .= $s->textContent;
    }
    foreach ($p->getElementsByTagName('b') as $s) {
      $bold .= $s->textContent;
    }
    $all = preg_replace('/\s+/u', '', $p->textContent);
    return $all !== '' && preg_replace('/\s+/u', '', $bold) === $all;
  }

  private function pushFaq(array &$items, ?string $question, array $answer): void {
    if ($question && $answer) {
      $items[] = [
        '@type' => 'Question',
        'name' => $question,
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => implode(' ', $answer)],
      ];
    }
  }

  public static function getSubscribedEvents(): array {
    // After CanonicalAbsoluteSubscriber (-512); the page cache stores the result.
    return [KernelEvents::RESPONSE => ['onResponse', -520]];
  }

}
