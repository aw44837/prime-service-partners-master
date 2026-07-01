<?php

declare(strict_types=1);

namespace Drupal\psp_seo\EventSubscriber;

use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rewrites relative rel=canonical links to absolute URLs.
 *
 * Canvas (Experience Builder) pages emit a RELATIVE canonical (e.g. "/home" or
 * "/heating"), which fails Google/Lighthouse SEO audits (canonical must be an
 * absolute URL). Metatag adds an absolute canonical for node pages but not for
 * canvas_page entities, and the relative one is injected outside the page
 * attachments system — so this operates on the final HTML response to catch it
 * regardless of source. The front page canonical is pointed at the site root
 * rather than the "/home" alias. Already-absolute canonicals are left untouched.
 */
final class CanonicalAbsoluteSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly PathMatcherInterface $pathMatcher,
  ) {}

  /**
   * Rewrites relative canonical hrefs to absolute on HTML responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    if (stripos((string) $response->headers->get('Content-Type', ''), 'text/html') === FALSE) {
      return;
    }
    $html = $response->getContent();
    if (!is_string($html) || stripos($html, 'rel="canonical"') === FALSE) {
      return;
    }

    // Derive scheme+host from Drupal's URL generator (respects HTTPS behind the
    // LiteSpeed SSL terminator, matching the core-generated shortlink) rather
    // than the raw request, which can report http.
    try {
      $front = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable $e) {
      return;
    }
    $origin = rtrim($front, '/');
    $is_front = $this->pathMatcher->isFrontPage();

    // Match any <link ... rel="canonical" ...> and fix its href (any attr order).
    $new = preg_replace_callback('#<link\b[^>]*\brel="canonical"[^>]*>#i', static function (array $tag) use ($origin, $front, $is_front): string {
      return (string) preg_replace_callback('#\bhref="([^"]*)"#i', static function (array $h) use ($origin, $front, $is_front): string {
        $href = $h[1];
        // Already absolute — leave untouched.
        if ($href === '' || preg_match('#^https?://#i', $href)) {
          return $h[0];
        }
        $abs = $is_front ? $front : $origin . '/' . ltrim($href, '/');
        return 'href="' . $abs . '"';
      }, $tag[0]);
    }, $html);

    if (is_string($new) && $new !== $html) {
      $response->setContent($new);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run late so the response HTML is fully built.
    return [KernelEvents::RESPONSE => ['onResponse', -512]];
  }

}
