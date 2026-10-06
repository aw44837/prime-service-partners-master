<?php

namespace Drupal\droptech_avoca\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\droptech_avoca\AvocaClient;
use Drupal\webform\Entity\Webform;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries Avoca leads that failed to send at submission time.
 *
 * Runs on cron; up to 6 attempts in total, spaced 5, 10, 20, 40 and 80
 * minutes apart, before giving up with an error that includes the lead.
 */
#[QueueWorker(
  id: 'droptech_avoca_retry',
  title: new TranslatableMarkup('Avoca Speed-to-Lead retries'),
  cron: ['time' => 30],
)]
class AvocaRetry extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  const MAX_ATTEMPTS = 6;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AvocaClient $client,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('droptech_avoca.client'),
      $container->get('logger.channel.droptech_avoca'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $wait = ($data['not_before'] ?? 0) - \Drupal::time()->getRequestTime();
    if ($wait > 0) {
      throw new DelayedRequeueException($wait);
    }
    $webform = Webform::load($data['webform_id']);
    if (!$webform || !$webform->getHandlers()->has($data['handler_id'])) {
      $this->logger->error('Avoca retry dropped for @webform #@sid: handler no longer exists.', ['@webform' => $data['webform_id'], '@sid' => $data['sid']]);
      return;
    }
    $settings = $webform->getHandler($data['handler_id'])->getConfiguration()['settings'];
    // The key chosen when the lead was first sent (per-market keys).
    if (!empty($data['key_id'])) {
      $settings['key_id'] = $data['key_id'];
    }
    $label = $data['webform_id'] . ' #' . $data['sid'];
    try {
      if ($this->client->send($data['payload'], $settings, $label . ' (retry ' . $data['attempts'] . ')')) {
        return;
      }
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('Avoca retry dropped for @label: @message', ['@label' => $label, '@message' => $e->getMessage()]);
      return;
    }
    if ($data['attempts'] + 1 >= self::MAX_ATTEMPTS) {
      $this->logger->error('Avoca gave up on @label after @n attempts. Lead: @payload', [
        '@label' => $label,
        '@n' => self::MAX_ATTEMPTS,
        '@payload' => json_encode($data['payload']),
      ]);
      return;
    }
    // Back off: 5, 10, 20, 40, 80 minutes.
    $data['not_before'] = \Drupal::time()->getRequestTime() + 300 * (2 ** ($data['attempts'] - 1));
    $data['attempts']++;
    \Drupal::queue('droptech_avoca_retry')->createItem($data);
  }

}
