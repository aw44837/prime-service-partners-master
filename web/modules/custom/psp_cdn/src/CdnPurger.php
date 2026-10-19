<?php

namespace Drupal\psp_cdn;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Purges the Hostinger CDN edge cache after changes that affect pages.
 *
 * Drupal invalidates cache tags whenever content, menus, blocks or
 * configuration change. Hostinger only purges whole websites, so the tags are
 * used to decide *whether* to purge: content entities, configuration and
 * "rendered" count; internal tags (routes, local tasks, 4xx responses, user
 * accounts touched by logins) do not.
 *
 * At most one purge is sent per request, after the response has gone to the
 * browser (destruct), so saving a page never waits on the API. A purge within
 * the cooldown of the previous one is held as "pending" and sent by the next
 * request that changes something, or by cron.
 *
 * Configured only in the server's settings.php, so local and pre-launch
 * copies stay inert:
 * @code
 * $settings['psp_cdn'] = [
 *   'account' => 'u569401512',
 *   'domain' => 'example.com',
 *   'token_file' => '/home/u569401512/.hostinger-cdn-purge.env',
 * ];
 * @endcode
 * The token file holds HOSTINGER_API_TOKEN=... (one account-wide token).
 */
class CdnPurger implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * Seconds between purges; changes inside this window are batched.
   */
  const COOLDOWN = 15;

  /**
   * Tag prefixes whose invalidation means page HTML may have changed.
   */
  const TRIGGER_PATTERN = '/^(node|canvas_page|media|file|taxonomy_term|menu_link_content|block_content|redirect|path_alias|webform)(_list)?(:|$)|^config:|^rendered$/';

  /**
   * Tags that never warrant a purge on their own, even if they match above.
   */
  const IGNORE_PATTERN = '/:revisions$|^config:(core\.extension|system\.cron|update\.settings)$/';

  /**
   * Whether this request invalidated a page-affecting tag.
   *
   * @var bool
   */
  protected bool $needed = FALSE;

  public function __construct(
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    if ($this->needed || !$this->config()) {
      return;
    }
    foreach ($tags as $tag) {
      if (preg_match(self::TRIGGER_PATTERN, $tag) && !preg_match(self::IGNORE_PATTERN, $tag)) {
        $this->needed = TRUE;
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if ($this->needed) {
      $this->needed = FALSE;
      $this->state->set('psp_cdn.pending', TRUE);
    }
    $this->purgePending();
  }

  /**
   * Sends the pending purge unless the previous one was within the cooldown.
   */
  public function purgePending(): void {
    if (!$this->state->get('psp_cdn.pending') || !($config = $this->config())) {
      return;
    }
    $now = $this->time->getCurrentTime();
    if ($now - (int) $this->state->get('psp_cdn.last_purge', 0) < self::COOLDOWN) {
      return;
    }
    $this->state->set('psp_cdn.last_purge', $now);
    $this->state->set('psp_cdn.pending', FALSE);
    $this->purge($config);
  }

  /**
   * Calls the Hostinger API to purge the website's CDN edge cache.
   */
  protected function purge(array $config): void {
    $url = sprintf('https://developers.hostinger.com/api/hosting/v1/accounts/%s/websites/%s/cdn/purge',
      rawurlencode($config['account']), rawurlencode($config['domain']));
    try {
      $response = $this->httpClient->request('DELETE', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $config['token'], 'Accept' => 'application/json'],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
      $code = $response->getStatusCode();
      if ($code >= 200 && $code < 300) {
        $this->logger->info('Purged the CDN cache for @domain.', ['@domain' => $config['domain']]);
      }
      else {
        // A failed purge should be retried rather than lost.
        $this->state->set('psp_cdn.pending', TRUE);
        $this->logger->warning('CDN purge for @domain failed: HTTP @code @body', [
          '@domain' => $config['domain'], '@code' => $code, '@body' => mb_substr((string) $response->getBody(), 0, 300),
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->state->set('psp_cdn.pending', TRUE);
      $this->logger->warning('CDN purge for @domain failed: @message', ['@domain' => $config['domain'], '@message' => $e->getMessage()]);
    }
  }

  /**
   * Returns account, domain and token, or NULL when not configured.
   */
  protected function config(): ?array {
    static $config = FALSE;
    if ($config !== FALSE) {
      return $config;
    }
    $settings = Settings::get('psp_cdn', []);
    $token = NULL;
    if (!empty($settings['token_file']) && is_readable($settings['token_file'])
      && preg_match('/^HOSTINGER_API_TOKEN=(.+)$/m', (string) file_get_contents($settings['token_file']), $m)) {
      $token = trim($m[1], " \t\"'\r");
    }
    $config = (!empty($settings['account']) && !empty($settings['domain']) && $token && $token !== 'PUT_TOKEN_HERE')
      ? ['account' => $settings['account'], 'domain' => $settings['domain'], 'token' => $token]
      : NULL;
    return $config;
  }

}
