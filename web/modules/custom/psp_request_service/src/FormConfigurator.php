<?php

namespace Drupal\psp_request_service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\webform\WebformInterface;

/**
 * Writes the per-site settings (brand, lead source, zips) into the webforms.
 *
 * The zip list lives in two places in the form: the zip field's #pattern
 * (validation + the zip script's check) and every #states rule that reveals
 * the rest of the form. Keeping both in sync by hand is error-prone, so the
 * settings form writes them here in one go.
 */
class FormConfigurator {

  const WEBFORM_ID = 'request_service';

  /**
   * Every webform this module ships; settings apply to each one present.
   */
  const WEBFORM_IDS = ['request_service', 'book_online'];

  const CONSENT = [
    'receive_text_messages_about_appointment' => 'I agree to receive recurring automated texts from %s for appointment reminders and service updates at the number provided. Message frequency varies. Reply STOP to opt out, HELP for help. Consent is not required for purchase.',
    'receive_text_messages_about_marketing_promotions' => 'I agree to receive recurring automated texts from %s for offers, discounts, seasonal and membership promotions at the number provided. Message frequency varies. Reply STOP to opt out, HELP for help. Consent is not required for purchase.',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Applies psp_request_service.settings to each of this module's webforms.
   *
   * @return bool
   *   FALSE when none of the webforms exist.
   */
  public function apply(): bool {
    $applied = FALSE;
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple(self::WEBFORM_IDS) as $webform) {
      $this->applyTo($webform);
      $applied = TRUE;
    }
    return $applied;
  }

  /**
   * Applies psp_request_service.settings to one webform.
   */
  protected function applyTo(WebformInterface $webform): void {
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
    // Multi-market sites (psp_service_area with a zip map): the booking is
    // tagged with its market; see psp_request_service_webform_submission_presave().
    if (static::markets() && !isset($elements['service_market'])) {
      $elements['service_market'] = [
        '#type' => 'hidden',
        '#title' => 'Service market',
        '#prepopulate' => TRUE,
      ];
    }
    $webform->setElements($elements);

    if ($webform->getHandlers()->has('email_booking')) {
      $handler = $webform->getHandler('email_booking');
      $subject = trim((string) $settings->get('email_subject')) ?: 'New ' . $company . ' Booking Request';
      $handler->setSetting('subject', $subject);
      $handler->setSetting('from_name', $company . ' Website');
      $webform->updateWebformHandler($handler);
    }
    $webform->save();
  }

  /**
   * The site's markets on multi-market sites, keyed by path prefix.
   *
   * @return string[]
   *   e.g. ['raleigh-nc' => 'Raleigh, NC']; empty without psp_service_area
   *   service areas that have a path prefix and a zip map.
   */
  public static function markets(): array {
    if (!\Drupal::moduleHandler()->moduleExists('psp_service_area') || !\Drupal::config('psp_service_area.zip_map')->get('map')) {
      return [];
    }
    $markets = [];
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'service_area']) as $term) {
      if ($term->hasField('field_path_prefix') && !$term->get('field_path_prefix')->isEmpty()) {
        $markets[trim((string) $term->get('field_path_prefix')->value)] = $term->label();
      }
    }
    return $markets;
  }

  /**
   * Replaces one of the site's webforms with this module's copy.
   *
   * @param bool $force
   *   Replace the form even if it has submissions.
   * @param string $webform_id
   *   One of self::WEBFORM_IDS.
   *
   * @return int
   *   Number of existing submissions (nothing is replaced when > 0 unless
   *   $force is set).
   */
  public function resetForm(bool $force = FALSE, string $webform_id = self::WEBFORM_ID): int {
    if (!in_array($webform_id, self::WEBFORM_IDS, TRUE)) {
      throw new \InvalidArgumentException("Unknown webform: $webform_id");
    }
    $count = (int) $this->entityTypeManager->getStorage('webform_submission')->getQuery()
      ->accessCheck(FALSE)->condition('webform_id', $webform_id)->count()->execute();
    if ($count && !$force) {
      return $count;
    }
    $storage = $this->entityTypeManager->getStorage('webform');
    $path = \Drupal::service('extension.list.module')->getPath('psp_request_service') . '/config/optional/webform.webform.' . $webform_id . '.yml';
    $data = Yaml::decode(file_get_contents($path));
    if ($existing = $storage->load($webform_id)) {
      // Keep the UUID so references (and config sync) stay stable.
      $data['uuid'] = $existing->uuid();
      $existing->delete();
    }
    $webform = $storage->createFromStorageRecord($data);
    $webform->save();
    $this->applyTo($webform);
    return $count;
  }

}
