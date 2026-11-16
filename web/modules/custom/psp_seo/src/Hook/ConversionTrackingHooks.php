<?php

declare(strict_types=1);

namespace Drupal\psp_seo\Hook;

use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Conversion signals for GTM (js/conversion-tracking.js).
 *
 * The script runs on every page and pushes phone clicks and ServiceTitan
 * scheduler opens straight into the dataLayer, so they queue even before the
 * lazy GTM loader starts. Form completions come from here: when a webform
 * submission is completed, the event is queued in the session and handed to
 * whatever is rendered next for that visitor: the AJAX response, the rebuilt
 * form (modal/inline confirmations) or the redirect target (message/url).
 * Only completed submissions count; drafts and edits of earlier submissions
 * do not.
 */
final class ConversionTrackingHooks {

  private const SESSION_KEY = 'psp_seo_conversion_events';

  /**
   * Webform machine names grouped by the conversion they represent.
   *
   * Anything not listed is reported with form_type "other".
   */
  private const FORM_TYPES = [
    'book_online' => 'booking',
    'request_service' => 'booking',
    'request_appointment' => 'booking',
    'contact_jefco' => 'contact',
    'contact' => 'contact',
    'careers' => 'job_application',
    'careers_application' => 'job_application',
    'job_application' => 'job_application',
  ];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {}

  #[Hook('webform_submission_insert')]
  #[Hook('webform_submission_update')]
  public function submissionSaved(WebformSubmissionInterface $submission): void {
    if ($submission->getState() !== WebformSubmissionInterface::STATE_COMPLETED || PHP_SAPI === 'cli') {
      return;
    }
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return;
    }
    $webform = $submission->getWebform();
    $session = $request->getSession();
    $events = $session->get(self::SESSION_KEY, []);
    $events[] = [
      'form_type' => self::FORM_TYPES[$webform->id()] ?? 'other',
      'form_id' => $webform->id(),
      'form_name' => (string) $webform->label(),
      'sid' => (int) $submission->id(),
    ];
    $session->set(self::SESSION_KEY, $events);
  }

  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'psp_seo/conversion_tracking';
    // Redirect confirmations (message, url): the event reaches the next page.
    $this->deliver($attachments);
  }

  /**
   * Modal and inline confirmations rebuild the form in the POST response.
   *
   * Page attachments are collected before the form is processed, so the form
   * itself carries the event on that response.
   */
  #[Hook('form_webform_submission_form_alter')]
  public function webformAlter(array &$form): void {
    $this->deliver($form);
  }

  /**
   * AJAX submissions (the booking card's form) get the event as a settings
   * command, placed first so behaviors attached by the commands after it
   * already see it.
   */
  #[Hook('ajax_render_alter')]
  public function ajaxRenderAlter(array &$data): void {
    $element = [];
    $this->deliver($element);
    if (isset($element['#attached']['drupalSettings'])) {
      array_unshift($data, (new SettingsCommand($element['#attached']['drupalSettings'], TRUE))->render());
    }
  }

  /**
   * Moves queued form events from the session into drupalSettings.
   */
  private function deliver(array &$element): void {
    // Drupal starts anonymous sessions lazily: reading one sends no cookie
    // and leaves the page cacheable unless something was written to it.
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return;
    }
    $session = $request->getSession();
    $events = $session->get(self::SESSION_KEY);
    if (!$events) {
      return;
    }
    $session->remove(self::SESSION_KEY);
    $element['#attached']['drupalSettings']['pspConversion']['forms'] = $events;
    $element['#cache']['max-age'] = 0;
    $this->pageCacheKillSwitch->trigger();
  }

}
