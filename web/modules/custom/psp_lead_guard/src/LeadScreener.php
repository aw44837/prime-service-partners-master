<?php

namespace Drupal\psp_lead_guard;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoderInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Classifies webform submissions as real leads or spam.
 *
 * Runs cheap deterministic pre-checks first (keyword allow/block lists, phone
 * country prefix, link count), then falls back to an AI chat model resolved
 * through the AI module's provider abstraction. Fails open: every error path
 * yields an "unsure" verdict, which never suppresses email.
 */
class LeadScreener {

  public const VERDICT_LEAD = 'lead';
  public const VERDICT_SPAM = 'spam';
  public const VERDICT_UNSURE = 'unsure';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AiProviderPluginManager $aiProvider,
    protected PromptJsonDecoderInterface $jsonDecoder,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Screens a submission.
   *
   * @return array
   *   Keys: verdict (lead|spam|unsure), confidence (float), reason (string),
   *   source (precheck_*|ai|error), model (string).
   */
  public function screen(WebformSubmissionInterface $submission): array {
    $config = $this->configFactory->get('psp_lead_guard.settings');
    $text = $this->flattenSubmission($submission);
    $haystack = mb_strtolower($text);

    foreach ($config->get('allowlist_keywords') ?? [] as $keyword) {
      if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
        return $this->result(self::VERDICT_LEAD, 1.0, "Allowlist keyword: $keyword", 'precheck_allowlist');
      }
    }

    if ($reason = $this->checkPhonePrefixes($submission, $config->get('allowed_phone_prefixes') ?? [])) {
      return $this->result(self::VERDICT_SPAM, 1.0, $reason, 'precheck_phone');
    }

    foreach ($config->get('blocklist_keywords') ?? [] as $keyword) {
      if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
        return $this->result(self::VERDICT_SPAM, 1.0, "Blocklist keyword: $keyword", 'precheck_blocklist');
      }
    }

    $max_links = (int) $config->get('max_links');
    if ($max_links > 0) {
      $links = preg_match_all('#https?://#i', $text);
      if ($links > $max_links) {
        return $this->result(self::VERDICT_SPAM, 1.0, "Message contains $links URLs (limit $max_links)", 'precheck_links');
      }
    }

    return $this->classifyWithAi($text, $config);
  }

  /**
   * Runs the AI classification.
   */
  protected function classifyWithAi(string $submission_text, $config): array {
    try {
      [$provider_id, $model_id] = $this->resolveProviderModel($config->get('provider_model'));
      if (!$provider_id || !$model_id) {
        throw new \RuntimeException('No AI model configured: set a default chat model in the AI module or an override in Lead Guard settings.');
      }

      $prompt = str_replace(
        ['[business_description]', '[lead_definition]', '[spam_definition]', '[service_area]', '[submission_data]'],
        [
          trim((string) $config->get('business_description')),
          trim((string) $config->get('lead_definition')),
          trim((string) $config->get('spam_definition')),
          trim((string) $config->get('service_area')),
          $submission_text,
        ],
        (string) $config->get('prompt_template')
      );

      $provider = $this->aiProvider->createInstance($provider_id);
      $input = new ChatInput([new ChatMessage('user', $prompt)]);
      $output = $provider->chat($input, $model_id, ['psp_lead_guard']);
      $message = $output->getNormalized();

      $decoded = $this->jsonDecoder->decode($message);
      if (!is_array($decoded)) {
        throw new \RuntimeException('Model response was not parseable JSON: ' . mb_substr($message->getText(), 0, 200));
      }

      $verdict = $decoded['verdict'] ?? '';
      if (!in_array($verdict, [self::VERDICT_LEAD, self::VERDICT_SPAM], TRUE)) {
        throw new \RuntimeException('Model returned unknown verdict: ' . json_encode($decoded));
      }
      $confidence = max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0)));
      $reason = mb_substr(trim((string) ($decoded['reason'] ?? '')), 0, 500);

      return $this->result($verdict, $confidence, $reason, 'ai', "$provider_id/$model_id");
    }
    catch (\Throwable $e) {
      // Fail open: an unreachable or misbehaving model must never cost a lead.
      $this->logger->error('AI screening failed, defaulting to unsure: @message', ['@message' => $e->getMessage()]);
      return $this->result(self::VERDICT_UNSURE, 0.0, 'Screening error: ' . mb_substr($e->getMessage(), 0, 300), 'error');
    }
  }

  /**
   * Resolves [provider_id, model_id] from settings or the AI module default.
   */
  protected function resolveProviderModel(?string $override): array {
    if ($override) {
      $parts = explode('__', $override, 2);
      if (count($parts) === 2) {
        return $parts;
      }
    }
    $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
    return [$default['provider_id'] ?? NULL, $default['model_id'] ?? NULL];
  }

  /**
   * Flags phone numbers with a disallowed international prefix.
   *
   * Only numbers dialed with an explicit international prefix ("+" or "00")
   * are judged; bare local numbers always pass. An empty allowed list
   * disables the check.
   */
  protected function checkPhonePrefixes(WebformSubmissionInterface $submission, array $allowed): ?string {
    if (!$allowed) {
      return NULL;
    }
    $elements = $submission->getWebform()->getElementsInitializedAndFlattened();
    foreach ($elements as $key => $element) {
      if (($element['#type'] ?? '') !== 'tel') {
        continue;
      }
      $value = $submission->getElementData($key);
      if (!is_string($value) || $value === '') {
        continue;
      }
      $digits = preg_replace('/[\s\-().]/', '', $value);
      if (str_starts_with($digits, '00')) {
        $digits = '+' . substr($digits, 2);
      }
      if (!str_starts_with($digits, '+')) {
        continue;
      }
      foreach ($allowed as $prefix) {
        $prefix = '+' . ltrim(trim($prefix), '+');
        if (str_starts_with($digits, $prefix)) {
          continue 2;
        }
      }
      return "Phone number $value is outside the allowed country prefixes";
    }
    return NULL;
  }

  /**
   * Flattens submission values into "Label: value" lines for the prompt.
   */
  protected function flattenSubmission(WebformSubmissionInterface $submission): string {
    $elements = $submission->getWebform()->getElementsInitializedAndFlattened();
    $lines = [];
    foreach ($submission->getData() as $key => $value) {
      if (in_array($key, ['ai_verdict', 'ai_action'], TRUE)) {
        continue;
      }
      $flat = $this->flattenValue($value);
      if ($flat === '') {
        continue;
      }
      $label = (string) ($elements[$key]['#title'] ?? $key);
      $lines[] = "$label: $flat";
    }
    return implode("\n", $lines);
  }

  /**
   * Renders a submission value (possibly a composite array) as one string.
   */
  protected function flattenValue(mixed $value): string {
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        $flat = $this->flattenValue($item);
        if ($flat !== '') {
          $parts[] = $flat;
        }
      }
      return implode(', ', $parts);
    }
    return trim((string) $value);
  }

  /**
   * Builds a screening result array.
   */
  protected function result(string $verdict, float $confidence, string $reason, string $source, string $model = ''): array {
    return [
      'verdict' => $verdict,
      'confidence' => $confidence,
      'reason' => $reason,
      'source' => $source,
      'model' => $model,
    ];
  }

}
