<?php

namespace Drupal\psp_lead_guard;

use Drupal\Core\Mail\MailManagerInterface;

/**
 * Routes plain-text webform emails around Easy Email Override.
 *
 * Drupal CMS ships an Easy Email override matching every module/key, which
 * re-sends all mail through an HTML email template. A webform email handler
 * with "Send email as HTML" off (e.g. the lead notification a CRM parses)
 * should arrive as text/plain only, so those messages go directly to the
 * mail manager Easy Email wraps. Everything else is untouched. Without Easy
 * Email Override installed this is a pass-through.
 */
class PlainTextMailRouter implements MailManagerInterface {

  public function __construct(
    protected MailManagerInterface $inner,
    protected ?MailManagerInterface $withoutEasyEmail = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function mail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE) {
    $manager = $this->withoutEasyEmail
      && $module === 'webform'
      && array_key_exists('html', $params)
      && empty($params['html'])
      ? $this->withoutEasyEmail
      : $this->inner;
    return $manager->mail($module, $key, $to, $langcode, $params, $reply, $send);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->inner->getInstance($options);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  /**
   * Forwards cache-clearing and other plugin-manager calls.
   */
  public function __call($method, $args) {
    return $this->inner->$method(...$args);
  }

}
