<?php

namespace Drupal\psp_request_service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Serialization\Yaml;

/**
 * Writes the per-site settings (brand, lead source, zips) into the webform.
 *
 * The zip list lives in two places in the form: the zip field's #pattern
 * (validation + the zip script's check) and every #states rule that reveals
 * the rest of the form. Keeping both in sync by hand is error-prone, so the
 * settings form writes them here in one go.
 */
class FormConfigurator {

  const WEBFORM_ID = 'request_service';

  const CONSENT = [
    'receive_text_messages_about_appointment' => 'I agree to receive recurring automated texts from %s for appointment reminders and service updates at the number provided. Message frequency varies. Reply STOP to opt out, HELP for help. Consent is not required for purchase.',
    'receive_text_messages_about_marketing_promotions' => 'I agree to receive recurring automated texts from %s for offers, discounts, seasonal and membership promotions at the number provided. Message frequency varies. Reply STOP to opt out, HELP for help. Consent is not required for purchase.',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Applies psp_request_service.settings to the request_service webform.
   *
   * @return bool
   *   FALSE when the webform does not exist.
   */
  public function apply(): bool {
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $this->entityTypeManager->getStorage('webform')->load(self::WEBFORM_ID);
    if (!$webform) {
      return FALSE;
    }
    $settings = $this->configFactory->get('psp_request_service.settings');
    $site_name = (string) $this->configFactory->get('system.site')->get('name');
    $company = trim((string) $settings->get('company_name')) ?: $site_name;
    $lead_source = trim((string) $settings->get('lead_source')) ?: $company;
    $zips = array_values(array_filter((array) $settings->get('zips'), fn($z) => preg_match('/^\d{5}$/', (string) $z)));

    // No list = accept any 5-digit zip.
    $pattern = $zips ? implode('|', $zips) : '[0-9]{5}';
    $states_pattern = '^(' . $pattern . ')$';

    $elements = Yaml::decode($webform->get('elements'));
    $walk = function (array &$items) use (&$walk, $company, $lead_source, $pattern, $states_pattern) {
      foreach ($items as $key => &$element) {
        if (!is_array($element) || str_starts_with((string) $key, '#')) {
          continue;
        }
        if ($key === 'zip') {
          $element['#pattern'] = $pattern;
        }
        if ($key === 'lead_source') {
          $element['#default_value'] = $lead_source;
        }
        if (isset(self::CONSENT[$key])) {
          $element['#title'] = sprintf(self::CONSENT[$key], $company);
        }
        if (isset($element['#states']['visible'][':input[name="zip"]']['value']['pattern'])) {
          $element['#states']['visible'][':input[name="zip"]']['value']['pattern'] = $states_pattern;
        }
        $walk($element);
      }
    };
    $walk($elements);
    $webform->setElements($elements);

    if ($webform->getHandlers()->has('email_booking')) {
      $handler = $webform->getHandler('email_booking');
      $handler->setSetting('subject', 'New ' . $company . ' Booking Request');
      $handler->setSetting('from_name', $company . ' Website');
      $webform->updateWebformHandler($handler);
    }
    $webform->save();
    return TRUE;
  }

  /**
   * Replaces the site's request_service webform with this module's copy.
   *
   * @return int
   *   Number of existing submissions (nothing is replaced when > 0 unless
   *   $force is set).
   */
  public function resetForm(bool $force = FALSE): int {
    $count = (int) $this->entityTypeManager->getStorage('webform_submission')->getQuery()
      ->accessCheck(FALSE)->condition('webform_id', self::WEBFORM_ID)->count()->execute();
    if ($count && !$force) {
      return $count;
    }
    $storage = $this->entityTypeManager->getStorage('webform');
    $path = \Drupal::service('extension.list.module')->getPath('psp_request_service') . '/config/optional/webform.webform.' . self::WEBFORM_ID . '.yml';
    $data = Yaml::decode(file_get_contents($path));
    if ($existing = $storage->load(self::WEBFORM_ID)) {
      // Keep the UUID so references (and config sync) stay stable.
      $data['uuid'] = $existing->uuid();
      $existing->delete();
    }
    $storage->createFromStorageRecord($data)->save();
    $this->apply();
    return $count;
  }

}
