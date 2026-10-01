<?php

namespace Drupal\psp_request_service\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\psp_request_service\AddressLookup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoints behind the Book Online service-address field.
 *
 * Rate-limited per visitor IP, so the site's Places key can't be used as a
 * free lookup service. Failures answer 503 and the form falls back to the
 * zip field.
 */
class AddressController extends ControllerBase {

  /**
   * Lookups per visitor IP per window (typing makes several per address).
   */
  const LIMITS = ['suggest' => 150, 'place' => 40];

  const WINDOW = 3600;

  public function __construct(
    protected AddressLookup $lookup,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psp_request_service.address_lookup'),
      $container->get('flood'),
    );
  }

  /**
   * GET /psp-address/suggest?q=…&session=…
   */
  public function suggest(Request $request): JsonResponse {
    $input = trim((string) $request->query->get('q'));
    if (mb_strlen($input) < 3 || mb_strlen($input) > 150) {
      return $this->json(['suggestions' => []]);
    }
    if ($limited = $this->limit('suggest')) {
      return $limited;
    }
    try {
      return $this->json(['suggestions' => $this->lookup->suggest($input, $this->session($request))]);
    }
    catch (\Throwable $e) {
      return $this->json(['error' => 'unavailable'], 503);
    }
  }

  /**
   * GET /psp-address/place?id=…&session=…
   */
  public function place(Request $request): JsonResponse {
    $id = (string) $request->query->get('id');
    if ($id === '') {
      return $this->json(['error' => 'missing id'], 400);
    }
    if ($limited = $this->limit('place')) {
      return $limited;
    }
    try {
      return $this->json(['place' => $this->lookup->place($id, $this->session($request))]);
    }
    catch (\InvalidArgumentException $e) {
      return $this->json(['error' => 'bad id'], 400);
    }
    catch (\Throwable $e) {
      return $this->json(['error' => 'unavailable'], 503);
    }
  }

  /**
   * Registers a lookup; a 429 response once the visitor is over the limit.
   */
  protected function limit(string $kind): ?JsonResponse {
    $event = 'psp_address_' . $kind;
    if (!$this->flood->isAllowed($event, self::LIMITS[$kind], self::WINDOW)) {
      return $this->json(['error' => 'rate limited'], 429);
    }
    $this->flood->register($event, self::WINDOW);
    return NULL;
  }

  /**
   * The browser's Places session token (a UUID), or ''.
   */
  protected function session(Request $request): string {
    $session = (string) $request->query->get('session');
    return preg_match('/^[0-9a-f-]{36}$/i', $session) ? $session : '';
  }

  /**
   * An uncacheable JSON response.
   */
  protected function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

}
