<?php

namespace Drupal\psp_lead_guard\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Formats plain-text mail without re-wrapping lines.
 *
 * Symfony Mailer Lite's formatter round-trips plain text through HTML and
 * Html2Text, hard-wrapping at 70 characters, which splits "Label: value"
 * lines that CRMs/parsers read one per line. Use this as the Mail System
 * *formatter* (sender stays symfony_mailer_lite) for webform mail: text/plain
 * bodies are joined as-is; anything else is handed to Symfony Mailer Lite.
 */
#[Mail(
  id: 'psp_plain_text',
  label: new TranslatableMarkup('PSP plain text (no wrapping)'),
  description: new TranslatableMarkup('Formatter only: sends text/plain bodies exactly as written; defers other mail to Symfony Mailer Lite.'),
)]
class PlainTextFormatter implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $content_type = $message['params']['content_type']
      ?? explode(';', $message['headers']['Content-Type'] ?? '')[0];
    if (trim($content_type) !== 'text/plain') {
      return \Drupal::service('plugin.manager.mail')->createInstance('symfony_mailer_lite')->format($message);
    }
    $parts = is_array($message['body']) ? $message['body'] : [$message['body']];
    $body = implode("\n\n", array_map('strval', $parts));
    $message['body'] = str_replace(["\r\n", "\r"], "\n", $body);
    $message['headers']['Content-Type'] = 'text/plain; charset=UTF-8';
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    // Formatter only; Mail System uses the configured sender to deliver.
    return FALSE;
  }

}
