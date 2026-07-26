<?php

namespace Drupal\psp_lead_guard\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\psp_lead_guard\LeadScreener;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Screens submissions with AI and records a verdict for email gating.
 *
 * Runs in preSave() so the verdict is stored on the submission before email
 * handlers evaluate their conditions in postSave(). Requires two "value"
 * elements on the webform: ai_verdict (lead|spam|unsure) and ai_action
 * (send|suppress). Email handlers should be conditioned on ai_action.
 *
 * @WebformHandler(
 *   id = "psp_lead_guard",
 *   label = @Translation("AI Lead Screening"),
 *   category = @Translation("Prime Service Partners"),
 *   description = @Translation("Classifies the submission as a real lead or spam and records the verdict; condition email handlers on the ai_action element to gate notifications."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class AiLeadScreeningHandler extends WebformHandlerBase {

  /**
   * The lead screener service.
   */
  protected LeadScreener $screener;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->screener = $container->get('psp_lead_guard.screener');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $config = $this->configFactory->get('psp_lead_guard.settings');
    $summary = parent::getSummary();
    $summary['#settings']['mode'] = $config->get('mode');
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('All screening settings (mode, model, lead/spam definitions, thresholds) are global per site: <a href=":url">AI Lead Guard settings</a>. This handler must run before email handlers, and the webform needs the <code>ai_verdict</code> and <code>ai_action</code> value elements.', [
        ':url' => Url::fromRoute('psp_lead_guard.settings')->toString(),
      ]),
    ];
    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {
    if ($webform_submission->isDraft() || !$webform_submission->isNew()) {
      return;
    }

    $elements = $webform_submission->getWebform()->getElementsInitializedAndFlattened();
    if (!isset($elements['ai_verdict']) || !isset($elements['ai_action'])) {
      $this->getLogger('psp_lead_guard')->warning('Webform @id has the AI Lead Screening handler but is missing the ai_verdict/ai_action elements; skipping screening.', [
        '@id' => $webform_submission->getWebform()->id(),
      ]);
      return;
    }

    $config = $this->configFactory->get('psp_lead_guard.settings');
    $mode = $config->get('mode') ?: 'off';
    if ($mode === 'off') {
      $webform_submission->setElementData('ai_verdict', LeadScreener::VERDICT_UNSURE);
      $webform_submission->setElementData('ai_action', 'send');
      return;
    }

    $start = microtime(TRUE);
    $result = $this->screener->screen($webform_submission);
    $elapsed_ms = (int) round((microtime(TRUE) - $start) * 1000);

    $confident_spam = $result['verdict'] === LeadScreener::VERDICT_SPAM
      && $result['confidence'] >= (float) $config->get('confidence_threshold');
    $suppress = $mode === 'enforce' && $confident_spam;

    $webform_submission->setElementData('ai_verdict', $result['verdict']);
    $webform_submission->setElementData('ai_action', $suppress ? 'suppress' : 'send');

    $note = 'AI Lead Guard: ' . json_encode($result + [
      'mode' => $mode,
      'action' => $suppress ? 'suppress' : 'send',
      'elapsed_ms' => $elapsed_ms,
    ], JSON_UNESCAPED_SLASHES);
    $notes = $webform_submission->getNotes();
    $webform_submission->setNotes($notes ? "$notes\n$note" : $note);
  }

}
