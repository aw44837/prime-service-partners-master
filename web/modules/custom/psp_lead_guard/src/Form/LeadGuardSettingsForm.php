<?php

namespace Drupal\psp_lead_guard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-site AI Lead Guard settings.
 *
 * Deliberately prose-first: the lead/spam definitions and service area are
 * plain-language textareas fed into the prompt, so tuning a site never means
 * touching code.
 */
class LeadGuardSettingsForm extends ConfigFormBase {

  /**
   * The AI provider plugin manager.
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->aiProvider = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'psp_lead_guard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['psp_lead_guard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('psp_lead_guard.settings');

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode'),
      '#default_value' => $config->get('mode') ?: 'log_only',
      '#options' => [
        'off' => $this->t('Off — no screening, all emails send'),
        'log_only' => $this->t('Log only — classify and record the verdict, but never suppress an email (pilot mode)'),
        'enforce' => $this->t('Enforce — suppress notification emails for confident spam (submission is still stored)'),
      ],
      '#required' => TRUE,
    ];

    $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $default_label = $default
      ? $this->t('Site default chat model (currently @provider / @model)', ['@provider' => $default['provider_id'], '@model' => $default['model_id']])
      : $this->t('Site default chat model (none configured — set one at /admin/config/ai/settings!)');
    $form['provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI model'),
      '#description' => $this->t('Which model classifies submissions. Leave on the site default so fleet-wide model changes only happen in one place (AI settings).'),
      '#options' => ['' => $default_label] + $this->aiProvider->getSimpleProviderModelOptions('chat', FALSE),
      '#default_value' => $config->get('provider_model') ?: '',
    ];

    $form['profile'] = [
      '#type' => 'details',
      '#title' => $this->t('Site profile (used in the AI prompt)'),
      '#open' => TRUE,
    ];
    $form['profile']['business_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What this business does'),
      '#default_value' => $config->get('business_description'),
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['profile']['service_area'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service area'),
      '#description' => $this->t('Cities, counties, and regions this site serves, in plain language.'),
      '#default_value' => $config->get('service_area'),
      '#rows' => 2,
      '#required' => TRUE,
    ];
    $form['profile']['lead_definition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What counts as a real lead'),
      '#default_value' => $config->get('lead_definition'),
      '#rows' => 4,
      '#required' => TRUE,
    ];
    $form['profile']['spam_definition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What counts as spam'),
      '#default_value' => $config->get('spam_definition'),
      '#rows' => 4,
      '#required' => TRUE,
    ];

    $form['prechecks'] = [
      '#type' => 'details',
      '#title' => $this->t('Deterministic pre-checks (no AI call needed)'),
      '#open' => FALSE,
    ];
    $form['prechecks']['allowed_phone_prefixes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed phone country prefixes'),
      '#description' => $this->t('Comma-separated, e.g. <em>+1</em>. Numbers dialed with a different international prefix are marked spam; local numbers without a prefix always pass. Leave empty to disable.'),
      '#default_value' => implode(', ', $config->get('allowed_phone_prefixes') ?? []),
    ];
    $form['prechecks']['blocklist_keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keyword blocklist — instant spam'),
      '#description' => $this->t('One phrase per line, case-insensitive.'),
      '#default_value' => implode("\n", $config->get('blocklist_keywords') ?? []),
      '#rows' => 6,
    ];
    $form['prechecks']['allowlist_keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keyword allowlist — instant lead, skips all other checks'),
      '#description' => $this->t('One phrase per line, case-insensitive.'),
      '#default_value' => implode("\n", $config->get('allowlist_keywords') ?? []),
      '#rows' => 3,
    ];
    $form['prechecks']['max_links'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum URLs in a submission'),
      '#description' => $this->t('More links than this is marked spam. 0 disables the check.'),
      '#default_value' => $config->get('max_links') ?? 3,
      '#min' => 0,
    ];

    $form['decision'] = [
      '#type' => 'details',
      '#title' => $this->t('Decision & digest'),
      '#open' => FALSE,
    ];
    $form['decision']['confidence_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Confidence threshold to suppress'),
      '#description' => $this->t('In Enforce mode, a spam verdict below this confidence still sends the email.'),
      '#default_value' => $config->get('confidence_threshold') ?? 0.8,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
    ];
    $form['decision']['digest_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email a digest of suppressed submissions'),
      '#default_value' => (bool) $config->get('digest_enabled'),
    ];
    $form['decision']['digest_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Digest frequency'),
      '#options' => ['daily' => $this->t('Daily'), 'weekly' => $this->t('Weekly')],
      '#default_value' => $config->get('digest_frequency') ?: 'weekly',
      '#states' => ['visible' => [':input[name="digest_enabled"]' => ['checked' => TRUE]]],
    ];
    $form['decision']['digest_recipients'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Digest recipients'),
      '#description' => $this->t('Comma-separated emails. Empty = the site email address.'),
      '#default_value' => implode(', ', $config->get('digest_recipients') ?? []),
      '#states' => ['visible' => [':input[name="digest_enabled"]' => ['checked' => TRUE]]],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced: prompt template'),
      '#open' => FALSE,
    ];
    $form['advanced']['prompt_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Classification prompt'),
      '#description' => $this->t('Tokens: [business_description], [lead_definition], [spam_definition], [service_area], [submission_data]. The model must be asked to return JSON with "verdict", "confidence", and "reason".'),
      '#default_value' => $config->get('prompt_template'),
      '#rows' => 18,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if (!str_contains($form_state->getValue('prompt_template'), '[submission_data]')) {
      $form_state->setErrorByName('prompt_template', $this->t('The prompt template must contain the [submission_data] token.'));
    }
    foreach ($this->splitList($form_state->getValue('digest_recipients'), ',') as $mail) {
      if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('digest_recipients', $this->t('%mail is not a valid email address.', ['%mail' => $mail]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('psp_lead_guard.settings')
      ->set('mode', $form_state->getValue('mode'))
      ->set('provider_model', $form_state->getValue('provider_model'))
      ->set('business_description', $form_state->getValue('business_description'))
      ->set('service_area', $form_state->getValue('service_area'))
      ->set('lead_definition', $form_state->getValue('lead_definition'))
      ->set('spam_definition', $form_state->getValue('spam_definition'))
      ->set('allowed_phone_prefixes', $this->splitList($form_state->getValue('allowed_phone_prefixes'), ','))
      ->set('blocklist_keywords', $this->splitList($form_state->getValue('blocklist_keywords'), "\n"))
      ->set('allowlist_keywords', $this->splitList($form_state->getValue('allowlist_keywords'), "\n"))
      ->set('max_links', (int) $form_state->getValue('max_links'))
      ->set('confidence_threshold', (float) $form_state->getValue('confidence_threshold'))
      ->set('digest_enabled', (bool) $form_state->getValue('digest_enabled'))
      ->set('digest_frequency', $form_state->getValue('digest_frequency'))
      ->set('digest_recipients', $this->splitList($form_state->getValue('digest_recipients'), ','))
      ->set('prompt_template', $form_state->getValue('prompt_template'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Splits a delimited string into a clean array of non-empty values.
   */
  protected function splitList(?string $value, string $delimiter): array {
    return array_values(array_filter(array_map('trim', explode($delimiter, (string) $value)), 'strlen'));
  }

}
