<?php

namespace Drupal\droptech_avoca\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Posts completed submissions to Avoca Speed-to-Lead.
 *
 * Add a condition (ai_action is not "suppress") so only leads that pass AI
 * Lead Guard are sent, the same as the notification email. Sends right away
 * with a short timeout; failures are queued and retried on cron.
 *
 * @WebformHandler(
 *   id = "avoca_speed_to_lead",
 *   label = @Translation("Avoca Speed-to-Lead"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts the lead to the Avoca Speed-to-Lead ingest webhook."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class AvocaSpeedToLeadHandler extends WebformHandlerBase {

  /**
   * Avoca payload fields and the form elements that feed them.
   */
  const FIELD_MAP = [
    'customer_name' => 'Customer name',
    'phone_number' => 'Phone number (sent as digits only)',
    'email' => 'Email',
    'address' => 'Street address',
    'city' => 'City',
    'state' => 'State',
    'zip' => 'Zip',
    'country' => 'Country',
    'service_type' => 'Service type',
    'external_id' => 'External ID',
    'lead_source' => 'Lead source',
    'utm_source' => 'UTM source',
    'utm_medium' => 'UTM medium',
    'utm_campaign' => 'UTM campaign',
    'utm_term' => 'UTM term',
    'utm_content' => 'UTM content',
  ];

  /**
   * Fields always sent (empty string when unmapped), as originally.
   *
   * The others are sent only when they have a value, so existing handlers'
   * payloads don't change.
   */
  const ALWAYS_SENT = ['customer_name', 'phone_number', 'email', 'zip', 'lead_source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

  /**
   * The Avoca client.
   */
  protected $avocaClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->avocaClient = $container->get('droptech_avoca.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'endpoint' => 'https://app.avoca.ai/api/outbound/speed-to-lead/ingest',
      'key_id' => '',
      'auth_header' => 'Authorization',
      'auth_prefix' => 'Bearer ',
      'timeout' => 5,
      'mapping' => [
        'customer_name' => 'customer_name',
        'phone_number' => 'phone_number',
        'email' => 'email',
        'address' => '',
        'city' => '',
        'state' => '',
        'zip' => 'zip',
        'country' => '',
        'service_type' => '',
        'external_id' => '',
        'lead_source' => 'lead_source',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
      ],
      'notes_elements' => "comments\npage_url",
      'notes_labels' => FALSE,
      'key_element' => '',
      'key_map' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $key = $this->configuration['key_id'] ?: $this->t('no key selected');
    if (!empty($this->configuration['key_element'])) {
      $key .= ' ' . $this->t('(or per @element)', ['@element' => $this->configuration['key_element']]);
    }
    return [
      '#markup' => $this->t('POST to @endpoint (key: @key, header: @header)', [
        '@endpoint' => $this->configuration['endpoint'],
        '@key' => $key,
        '@header' => $this->configuration['auth_header'],
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];
    $form['connection']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Ingest endpoint'),
      '#default_value' => $this->configuration['endpoint'],
      '#required' => TRUE,
    ];
    $form['connection']['key_id'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#description' => $this->t('The Speed-to-Lead ingest key for this client, stored in <a href=":url">Keys</a>.', [':url' => '/admin/config/system/keys']),
      '#default_value' => $this->configuration['key_id'],
      '#empty_option' => $this->t('- Select a key -'),
    ];
    $form['connection']['key_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key per value of'),
      '#description' => $this->t('Optional. A form element (e.g. <code>service_market</code>) whose value picks the key from the list below, for clients with a separate Avoca account per market. Values not listed use the API key above.'),
      '#default_value' => $this->configuration['key_element'],
      '#size' => 30,
    ];
    $form['connection']['key_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keys by value'),
      '#description' => $this->t('One per line: <code>value|key_id</code>, e.g. <code>raleigh-nc|avoca_speed_to_lead_raleigh_nc</code>.'),
      '#default_value' => $this->configuration['key_map'],
      '#rows' => 4,
      '#states' => ['invisible' => [':input[name="settings[key_element]"]' => ['value' => '']]],
    ];
    $form['connection']['auth_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auth header name'),
      '#description' => $this->t('As specified by Avoca, e.g. <code>Authorization</code> or <code>x-api-key</code>.'),
      '#default_value' => $this->configuration['auth_header'],
      '#required' => TRUE,
    ];
    $form['connection']['auth_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auth value prefix'),
      '#description' => $this->t('Text before the key, including the trailing space, e.g. <code>Bearer </code>. Leave empty to send the bare key.'),
      '#default_value' => $this->configuration['auth_prefix'],
    ];
    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#description' => $this->t('The visitor waits at most this long; a timed-out lead is retried on cron.'),
      '#default_value' => $this->configuration['timeout'],
      '#min' => 1,
      '#max' => 20,
    ];

    $form['mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Field mapping'),
      '#description' => $this->t('Form element machine name for each Avoca field (<code>element:key</code> for part of a composite, e.g. <code>address:postal_code</code>), or text with tokens (e.g. <code>[webform:id]-[webform_submission:sid]</code>). The original fields are always sent (empty when unmapped); the others only when they have a value.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach (self::FIELD_MAP as $field => $label) {
      $form['mapping'][$field] = [
        '#type' => 'textfield',
        '#title' => $label . ' (' . $field . ')',
        '#default_value' => $this->configuration['mapping'][$field] ?? '',
        '#size' => 30,
      ];
    }
    $form['notes_elements'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Element machine names, one per line, optionally with a label: <code>emergency_service|Emergency</code>. Their non-empty values are joined with line breaks into the Avoca <code>notes</code> field.'),
      '#default_value' => $this->configuration['notes_elements'],
      '#rows' => 5,
    ];
    $form['notes_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Label each note'),
      '#description' => $this->t('"Preferred date: October 13, 2026" instead of the bare value, so an answering service can read them. Uses the label after "|" or the element title; dates are written out.'),
      '#default_value' => $this->configuration['notes_labels'],
    ];
    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
    $this->configuration['timeout'] = (int) $this->configuration['timeout'];
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Send each lead once: when it is first completed.
    if ($update || $webform_submission->getState() !== WebformSubmissionInterface::STATE_COMPLETED) {
      return;
    }
    $payload = $this->buildPayload($webform_submission);
    $label = $this->getWebform()->id() . ' #' . $webform_submission->id();
    $key_id = $this->keyId($webform_submission);
    try {
      if ($this->avocaClient->send($payload, ['key_id' => $key_id] + $this->configuration, $label)) {
        return;
      }
    }
    catch (\InvalidArgumentException $e) {
      $this->getLogger('droptech_avoca')->error('Avoca handler on @webform is misconfigured: @message', [
        '@webform' => $this->getWebform()->id(),
        '@message' => $e->getMessage(),
      ]);
      return;
    }
    \Drupal::queue('droptech_avoca_retry')->createItem([
      'webform_id' => $this->getWebform()->id(),
      'handler_id' => $this->getHandlerId(),
      'sid' => $webform_submission->id(),
      'payload' => $payload,
      'key_id' => $key_id,
      'attempts' => 1,
      'not_before' => \Drupal::time()->getRequestTime() + 300,
    ]);
  }

  /**
   * The Key entity to send this submission with.
   *
   * The key_map entry for the key_element's value when there is one,
   * otherwise the handler's API key.
   */
  public function keyId(WebformSubmissionInterface $webform_submission): string {
    $element = trim((string) ($this->configuration['key_element'] ?? ''));
    if ($element !== '') {
      $value = $this->plainValue($webform_submission, $element);
      foreach (preg_split('/\R/', (string) ($this->configuration['key_map'] ?? '')) as $line) {
        [$match, $key] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        if ($match !== '' && $key !== '' && $match === $value) {
          return $key;
        }
      }
    }
    return (string) $this->configuration['key_id'];
  }

  /**
   * Builds the Avoca JSON body from a submission.
   */
  public function buildPayload(WebformSubmissionInterface $webform_submission): array {
    $payload = [];
    foreach (array_keys(self::FIELD_MAP) as $field) {
      $source = (string) ($this->configuration['mapping'][$field] ?? '');
      if (str_contains($source, '[')) {
        $value = trim(strip_tags((string) $this->replaceTokens($source, $webform_submission)));
      }
      else {
        $value = $source !== '' ? $this->plainValue($webform_submission, $source) : '';
      }
      if ($value !== '' || in_array($field, self::ALWAYS_SENT, TRUE)) {
        $payload[$field] = $value;
      }
    }
    $digits = preg_replace('/\D+/', '', $payload['phone_number']);
    if (strlen($digits) === 11 && $digits[0] === '1') {
      $digits = substr($digits, 1);
    }
    $payload['phone_number'] = $digits;

    $notes = [];
    $labelled = !empty($this->configuration['notes_labels']);
    foreach (preg_split('/\R/', (string) $this->configuration['notes_elements']) as $line) {
      [$element, $label] = array_map('trim', explode('|', $line, 2) + [1 => '']);
      if ($element === '' || ($value = $this->plainValue($webform_submission, $element)) === '') {
        continue;
      }
      if ($labelled) {
        $definition = $this->getWebform()->getElement($element) ?? [];
        if (($definition['#type'] ?? '') === 'date' && ($time = strtotime($value))) {
          $value = date('F j, Y', $time);
        }
        $label = $label !== '' ? $label : trim(strip_tags((string) ($definition['#title'] ?? $element)));
        $value = $label . ': ' . $value;
      }
      $notes[] = $value;
    }
    // Keep Avoca's documented field order: notes sits after lead_source.
    $ordered = [];
    foreach ($payload as $field => $value) {
      $ordered[$field] = $value;
      if ($field === 'lead_source') {
        $ordered['notes'] = implode("\n", $notes);
      }
    }
    return $ordered;
  }

  /**
   * Returns an element's submitted value as a trimmed string.
   *
   * "element:key" reads one part of a composite (e.g. address:postal_code).
   * Other composites (keyed arrays, e.g. webform_name) are joined with spaces;
   * multi-value lists are joined with commas.
   */
  protected function plainValue(WebformSubmissionInterface $webform_submission, string $element): string {
    [$element, $key] = array_pad(explode(':', $element, 2), 2, NULL);
    $value = $webform_submission->getElementData($element);
    if ($key !== NULL) {
      $value = is_array($value) ? ($value[$key] ?? '') : '';
    }
    if (is_array($value)) {
      $parts = array_filter(array_map(fn($v) => is_scalar($v) ? trim((string) $v) : '', $value), 'strlen');
      $value = implode(array_is_list($value) ? ', ' : ' ', $parts);
    }
    return trim((string) $value);
  }

}
