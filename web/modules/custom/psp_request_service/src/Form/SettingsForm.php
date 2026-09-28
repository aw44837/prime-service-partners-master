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
    $form['zips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service-area zip codes'),
      '#description' => $this->t('Separated by commas, spaces or new lines. Visitors entering any other zip see the out-of-area message and cannot continue. Leave empty to accept any 5-digit zip.'),
      '#default_value' => implode("\n", (array) $config->get('zips')),
      '#rows' => 10,
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
      ->set('zips', $this->parseZips($form_state->getValue('zips')))
      ->save();
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
