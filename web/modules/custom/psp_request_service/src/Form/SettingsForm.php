<?php

namespace Drupal\psp_request_service\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Per-site settings for the Request Service (Book Online) form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'psp_request_service_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['psp_request_service.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('psp_request_service.settings');
    $site_name = $this->config('system.site')->get('name');

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#description' => $this->t('Used in the SMS consent checkboxes and the booking email subject/sender. Leave empty to use the site name (currently "@name").', ['@name' => $site_name]),
      '#default_value' => $config->get('company_name'),
      '#placeholder' => $site_name,
    ];
    $form['lead_source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lead source'),
      '#description' => $this->t('Sent with every submission (hidden field). Defaults to the company name.'),
      '#default_value' => $config->get('lead_source'),
    ];
    $form['email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Booking email subject'),
      '#description' => $this->t('Leave empty for "New [company] Booking Request".'),
      '#default_value' => $config->get('email_subject'),
    ];
    $form['zips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service-area zip codes'),
      '#description' => $this->t('Separated by commas, spaces or new lines. Visitors entering any other zip see the out-of-area message and cannot continue. Leave empty to accept any 5-digit zip.'),
      '#default_value' => implode("\n", (array) $config->get('zips')),
      '#rows' => 10,
    ];
    $form['address'] = [
      '#type' => 'details',
      '#title' => $this->t('Service-address lookup (Book Online)'),
      '#open' => TRUE,
    ];
    $keys = [];
    foreach ($this->entityTypeManager()->getStorage('key')->loadMultiple() as $id => $key) {
      $keys[$id] = $key->label();
    }
    $form['address']['address_key_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Google Places API key'),
      '#description' => $this->t('A Key entity holding a Google Maps Platform key with Places API (New) enabled, restricted to this server\'s IP. Without one the form asks for a zip code only.'),
      '#options' => $keys,
      '#empty_option' => $this->t('- None (zip code only) -'),
      '#default_value' => $config->get('address_key_id'),
    ];
    $form['address']['address_stub'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stub mode (testing only)'),
      '#description' => $this->t('Canned suggestions, no API calls. Type "nowhere" for not found, "outage" for a service failure, or a service-area city name.'),
      '#default_value' => $config->get('address_stub'),
    ];
    $places = (array) $config->get('zip_places');
    $form['address']['zip_places'] = [
      '#type' => 'item',
      '#title' => $this->t('Service-area cities'),
      '#markup' => $places
        ? $this->t('@count of the service-area zips have a city on file, used when a visitor enters a city instead of an address. New zips are looked up when you save.', ['@count' => count($places)])
        : $this->t('No cities on file yet; they are looked up when you save.'),
    ];
    if (!$this->entityTypeManager()->getStorage('webform')->load('request_service')) {
      $this->messenger()->addWarning($this->t('This site has no request_service webform yet. Run <code>drush psp-request-service:reset-form</code> to install it.'));
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $bad = array_filter($this->parseZips($form_state->getValue('zips')), fn($z) => !preg_match('/^\d{5}$/', $z));
    if ($bad) {
      $form_state->setErrorByName('zips', $this->t('Not 5-digit zip codes: %zips', ['%zips' => implode(', ', $bad)]));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('psp_request_service.settings')
      ->set('company_name', trim($form_state->getValue('company_name')))
      ->set('lead_source', trim($form_state->getValue('lead_source')))
      ->set('email_subject', trim($form_state->getValue('email_subject')))
      ->set('zips', $this->parseZips($form_state->getValue('zips')))
      ->set('address_key_id', (string) $form_state->getValue('address_key_id'))
      ->set('address_stub', (bool) $form_state->getValue('address_stub'))
      ->save();
    $result = \Drupal::service('psp_request_service.address_lookup')->refreshZipPlaces();
    if ($result['missing']) {
      $this->messenger()->addWarning($this->t('No city found for: @zips', ['@zips' => implode(', ', $result['missing'])]));
    }
    if (\Drupal::service('psp_request_service.configurator')->apply()) {
      $this->messenger()->addStatus($this->t('The Request Service form was updated.'));
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * Splits the textarea into unique, sorted zip codes.
   */
  protected function parseZips(string $raw): array {
    $zips = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $raw))));
    sort($zips);
    return $zips;
  }

  /**
   * Entity type manager accessor.
   */
  protected function entityTypeManager() {
    return \Drupal::entityTypeManager();
  }

}
