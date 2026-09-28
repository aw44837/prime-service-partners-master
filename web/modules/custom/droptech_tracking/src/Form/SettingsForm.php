<?php

namespace Drupal\droptech_tracking\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Per-site Droptech Tracking settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'droptech_tracking_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['droptech_tracking.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('droptech_tracking.settings');

    $form['parameters'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Captured URL parameters'),
      '#description' => $this->t('One per line, matched regardless of capitalization. A webform hidden field with the same machine name receives the stored value (e.g. utm_source).'),
      '#default_value' => implode("\n", $config->get('parameters') ?: []),
      '#rows' => 6,
      '#required' => TRUE,
    ];
    $form['lifetime_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Cookie lifetime (days)'),
      '#description' => $this->t('How long captured values are kept after the landing visit.'),
      '#default_value' => $config->get('lifetime_days') ?: 90,
      '#min' => 1,
      '#max' => 730,
      '#required' => TRUE,
    ];
    $form['cookie_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cookie name'),
      '#default_value' => $config->get('cookie_name') ?: 'dt_utm',
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach ($this->parseParameters($form_state->getValue('parameters')) as $parameter) {
      if (!preg_match('/^[a-z0-9_\-]+$/', $parameter)) {
        $form_state->setErrorByName('parameters', $this->t('Invalid parameter name: %p', ['%p' => $parameter]));
      }
    }
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $form_state->getValue('cookie_name'))) {
      $form_state->setErrorByName('cookie_name', $this->t('Use letters, numbers, dashes and underscores only.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('droptech_tracking.settings')
      ->set('parameters', $this->parseParameters($form_state->getValue('parameters')))
      ->set('lifetime_days', (int) $form_state->getValue('lifetime_days'))
      ->set('cookie_name', $form_state->getValue('cookie_name'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Splits the textarea into unique lowercase parameter names.
   */
  protected function parseParameters(string $raw): array {
    $lines = array_map(fn($line) => strtolower(trim($line)), preg_split('/[\r\n,]+/', $raw));
    return array_values(array_unique(array_filter($lines)));
  }

}
