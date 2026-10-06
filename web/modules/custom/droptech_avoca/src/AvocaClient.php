<?php

namespace Drupal\droptech_avoca;

use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Posts lead payloads to the Avoca Speed-to-Lead ingest endpoint.
 */
class AvocaClient {

  public function __construct(
    protected ClientInterface $httpClient,
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Sends one lead.
   *
   * @param array $payload
   *   The JSON body.
   * @param array $settings
   *   endpoint, key_id, auth_header, auth_prefix, timeout.
   * @param string $label
   *   Identifies the lead in logs (e.g. "request_service #123").
   *
   * @return bool
   *   TRUE when Avoca accepted the lead (2xx). FALSE on a retryable failure.
   *
   * @throws \InvalidArgumentException
   *   When the handler is misconfigured (no endpoint/key); not retryable.
   */
  public function send(array $payload, array $settings, string $label): bool {
    $endpoint = trim($settings['endpoint'] ?? '');
    $key = !empty($settings['key_id']) ? $this->keyRepository->getKey($settings['key_id']) : NULL;
    $secret = $key ? trim((string) $key->getKeyValue()) : '';
    if ($endpoint === '' || $secret === '') {
      throw new \InvalidArgumentException('Avoca endpoint or API key is not configured.');
    }

    $header = trim($settings['auth_header'] ?? '') ?: 'Authorization';
    $prefix = (string) ($settings['auth_prefix'] ?? '');

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json' => $payload,
        'headers' => [
          $header => $prefix . $secret,
          'Accept' => 'application/json',
        ],
        'timeout' => (float) ($settings['timeout'] ?? 5),
        'connect_timeout' => 3,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Avoca ingest failed for @label: @message', ['@label' => $label, '@message' => $e->getMessage()]);
      return FALSE;
    }

    $status = $response->getStatusCode();
    $body = mb_substr((string) $response->getBody(), 0, 500);
    if ($status >= 200 && $status < 300) {
      $this->logger->info('Avoca accepted @label (HTTP @status): @body', ['@label' => $label, '@status' => $status, '@body' => $body]);
      return TRUE;
    }
    $this->logger->warning('Avoca rejected @label (HTTP @status): @body', ['@label' => $label, '@status' => $status, '@body' => $body]);
    // 4xx other than 408/429 will not succeed on retry (bad key/payload),
    // but retrying a few times is cheap and covers transient auth hiccups.
    return FALSE;
  }

}
