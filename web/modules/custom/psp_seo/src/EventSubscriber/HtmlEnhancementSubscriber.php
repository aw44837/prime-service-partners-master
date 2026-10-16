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
    $new = $this->prioritizeHeroImage($new);
    $new = $this->sizeLocalImages($new);
    $new = $this->fixVideoPosters($new);
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

  /**
   * Makes the hero image (the usual LCP element) load without delay.
   *
   * Canvas renders hero images with loading="lazy" on most pages, so mobile
   * browsers fetch them late. The first image inside .hero__media, and the
   * first lazy image after it (e.g. a blog post's inline featured image,
   * which is the LCP element there), load eagerly; Chrome raises in-viewport
   * images to high priority on its own. Measured on PageSpeed (2026-10): an
   * explicit fetchpriority="high" or a <link rel="preload"> did not help and
   * the preload made several pages slower, so neither is added. The header
   * logo's fetchpriority="high" is dropped so it does not compete.
   */
  private function prioritizeHeroImage(string $html): string {
    $html = preg_replace('#(<img\b[^>]*class="header-logo__image"[^>]*?)\s+fetchpriority="high"#', '$1', $html) ?? $html;
    $media = strpos($html, 'class="hero__media"');
    if ($media === FALSE || !preg_match('#<img\b[^>]*>#', $html, $m, PREG_OFFSET_CAPTURE, $media)) {
      return $html;
    }
    [$tag, $pos] = $m[0];
    // Only treat it as the hero image when it sits in the hero's media slot.
    if (substr_count(substr($html, $media, $pos - $media), '</div>') > 1) {
      return $html;
    }
    $new = preg_replace('#\sloading="[^"]*"#', '', $tag);
    $new = preg_replace('#\ssizes="auto,?\s*#', ' sizes="', $new);
    $new = preg_replace('#^<img\b#', '<img loading="eager"', $new);
    $html = substr_replace($html, $new, $pos, strlen($tag));

    $after = strpos($html, '</div>', $pos + strlen($new));
    if ($after !== FALSE && preg_match('#<img\b[^>]*\bloading="lazy"[^>]*>#', $html, $next, PREG_OFFSET_CAPTURE, $after)) {
      $eager = str_replace(' loading="lazy"', ' loading="eager"', $next[0][0]);
      $eager = preg_replace('#\ssizes="auto,?\s*#', ' sizes="', $eager);
      $html = substr_replace($html, $eager, $next[0][1], strlen($next[0][0]));
    }
    return $html;
  }

  /**
   * Adds width/height to local raster images that lack them (e.g. logos).
   *
   * Without intrinsic dimensions the browser cannot reserve space before the
   * image loads. Dimensions are read from the file on disk; the page cache
   * stores the result, so this runs once per cached page.
   */
  private function sizeLocalImages(string $html): string {
    static $dims = [];
    return preg_replace_callback('#<img\b(?![^>]*\bwidth=)[^>]*\bsrc="(/(?:sites|themes|modules)/[^"?]+\.(?:png|jpe?g|gif|webp))(?:\?[^"]*)?"[^>]*>#i', static function (array $m) use (&$dims): string {
      $path = DRUPAL_ROOT . rawurldecode($m[1]);
      if (!array_key_exists($path, $dims)) {
        $size = is_file($path) ? @getimagesize($path) : FALSE;
        $dims[$path] = $size ? [$size[0], $size[1]] : NULL;
      }
      if (!$dims[$path]) {
        return $m[0];
      }
      return preg_replace('#^<img\b#', sprintf('<img width="%d" height="%d"', $dims[$path][0], $dims[$path][1]), $m[0]);
    }, $html) ?? $html;
  }

  /**
   * Repairs video poster attributes and points them at a resized image.
   *
   * The theme's video player prints the whole Canvas image object into
   * poster="" (URL, alt text and dimensions), so browsers fetch the
   * full-size original. The poster becomes the 1200px AVIF derivative named
   * in the URL's alternateWidths template. An empty poster on a video whose
   * file has a sibling "<name>-poster.webp" (or .jpg) uses that image.
   */
  private function fixVideoPosters(string $html): string {
    if (stripos($html, '<video') === FALSE) {
      return $html;
    }
    return preg_replace_callback('#<video\b[^>]*>\s*<source\s+src="([^"]+)"#i', static function (array $m): string {
      $tag = $m[0];
      if (preg_match('#\sposter="([^"]*)"#', $tag, $p)) {
        $value = trim(html_entity_decode($p[1]));
        $url = '';
        if ($value !== '') {
          $url = strtok($value, ' ');
          $query = parse_url($url, PHP_URL_QUERY);
          parse_str((string) $query, $params);
          if (!empty($params['alternateWidths'])) {
            $url = str_replace('{width}', '1200', $params['alternateWidths']);
          }
        }
        elseif (preg_match('#^(/sites/[^"?]+)\.mp4#i', $m[1], $v)) {
          foreach (['webp', 'jpg'] as $ext) {
            $candidate = rawurldecode($v[1]) . '-poster.' . $ext;
            if (is_file(DRUPAL_ROOT . $candidate)) {
              $url = $v[1] . '-poster.' . $ext;
              break;
            }
          }
        }
        $tag = $url === ''
          ? str_replace($p[0], '', $tag)
          : str_replace($p[0], ' poster="' . htmlspecialchars($url, ENT_QUOTES) . '"', $tag);
      }
      return $tag;
    }, $html) ?? $html;
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
