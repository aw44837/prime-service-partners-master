<?php

namespace Drupal\psp_request_service\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\webform\Entity\WebformOptions;
use Drupal\webform\WebformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Book in under 2 minutes" card: the webform's first two questions on-page.
 *
 * The card asks the target webform's first two questions (a choice and a
 * date) with chips, quick-day tiles and a month calendar. Continue opens the
 * full webform — its header/footer-free share page, prefilled through the
 * query string — either inside the card or in a side panel over the page.
 *
 * @Block(
 *   id = "psp_booking_card",
 *   admin_label = @Translation("Booking card"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class BookingCardBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Months ahead the calendar reaches when the date element sets no maximum.
   */
  const DEFAULT_MONTHS_AHEAD = 5;

  /**
   * Dripyard background themes (theme--* classes), as other components offer.
   */
  const THEMES = ['inherit', 'white', 'light', 'dark', 'black', 'primary', 'secondary'];

  /**
   * Element types that hold a date the card can fill.
   */
  const DATE_TYPES = ['date', 'datetime', 'datelist'];

  /**
   * Element types that never count as a question.
   */
  const SKIP_TYPES = ['hidden', 'value', 'webform_computed_token', 'webform_computed_twig'];

  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'webform' => 'book_online',
      'title' => 'Book in under 2 minutes',
      'subtitle' => 'Pick a service and a time that works for you.',
      'button_label' => 'Continue',
      'display' => 'panel',
      'theme' => 'white',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $webforms = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $id => $webform) {
      if (!$webform->isTemplate()) {
        $webforms[$id] = $webform->label();
      }
    }
    $form['webform'] = [
      '#type' => 'select',
      '#title' => $this->t('Webform'),
      '#description' => $this->t("The card asks this form's first two questions, which must be a choice (radios, buttons or select) and a date."),
      '#options' => $webforms,
      '#default_value' => $this->configuration['webform'],
      '#required' => TRUE,
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $this->configuration['title'],
    ];
    $form['subtitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subtitle'),
      '#default_value' => $this->configuration['subtitle'],
    ];
    $form['button_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button label'),
      '#default_value' => $this->configuration['button_label'],
      '#required' => TRUE,
    ];
    $form['display'] = [
      '#type' => 'radios',
      '#title' => $this->t('Show the rest of the form'),
      '#options' => [
        'panel' => $this->t('In a side panel over the page'),
        'inline' => $this->t('Inside the card'),
      ],
      '#default_value' => $this->configuration['display'],
    ];
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Background'),
      '#description' => $this->t('The card, its side panel and the form inside follow this background; text, borders and buttons adjust for contrast.'),
      '#options' => [
        'inherit' => $this->t('Inherit'),
        'white' => $this->t('White'),
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
        'black' => $this->t('Black'),
        'primary' => $this->t('Primary'),
        'secondary' => $this->t('Secondary'),
      ],
      '#default_value' => $this->configuration['theme'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (array_keys($this->defaultConfiguration()) as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $webform = $this->entityTypeManager->getStorage('webform')->load($this->configuration['webform']);
    $questions = $webform ? $this->firstQuestions($webform) : NULL;
    if (!$questions) {
      // Misconfigured: say why to people who can fix it, render nothing for
      // visitors.
      $build = [];
      if (\Drupal::currentUser()->hasPermission('administer webform')) {
        $build['message'] = [
          '#markup' => '<p>' . $this->t('Booking card: the first two questions of the "@webform" webform must be a choice and a date.', ['@webform' => $this->configuration['webform']]) . '</p>',
        ];
      }
      $build['#cache']['tags'] = $webform ? $webform->getCacheTags() : ['webform_list'];
      return $build;
    }

    [$choice, $date] = $questions;
    $build = [
      '#theme' => 'psp_booking_card',
      '#title' => $this->configuration['title'],
      '#subtitle' => $this->configuration['subtitle'],
      '#button_label' => $this->configuration['button_label'],
      '#display' => $this->configuration['display'] === 'inline' ? 'inline' : 'panel',
      '#theme_name' => in_array($this->configuration['theme'] ?? 'white', self::THEMES, TRUE) ? ($this->configuration['theme'] ?? 'white') : 'white',
      '#form_title' => $webform->label(),
      '#options' => $choice['options'],
      '#choice_name' => $choice['key'],
      '#choice_label' => $choice['title'],
      '#date_name' => $date['key'],
      '#months_ahead' => $date['months_ahead'],
      '#same_day' => $this->sameDayQuestion($webform),
      '#form_url' => Url::fromRoute('entity.webform.share_page', ['webform' => $webform->id()])->toString(),
      '#attached' => ['library' => ['psp_request_service/booking_card']],
    ];
    $build['#cache']['tags'] = $webform->getCacheTags();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $webform = $this->entityTypeManager->getStorage('webform')->load($this->configuration['webform']);
    if (!$webform) {
      return parent::blockAccess($account);
    }
    return $webform->access('submission_create', $account, TRUE);
  }

  /**
   * Finds the webform's first two questions: one choice, one date.
   *
   * @return array|null
   *   [choice, date] where choice has key/title/options and date has
   *   key/months_ahead, or NULL when the first two questions don't fit.
   */
  protected function firstQuestions(WebformInterface $webform): ?array {
    $questions = [];
    foreach ($webform->getElementsInitializedFlattenedAndHasValue() as $key => $element) {
      $type = $element['#type'] ?? '';
      if (in_array($type, self::SKIP_TYPES, TRUE) || (isset($element['#access']) && !$element['#access'])) {
        continue;
      }
      $questions[$key] = $element;
      if (count($questions) === 2) {
        break;
      }
    }

    $choice = $date = NULL;
    foreach ($questions as $key => $element) {
      if (!$date && in_array($element['#type'], self::DATE_TYPES, TRUE)) {
        $date = [
          'key' => $key,
          'months_ahead' => $this->monthsAhead($element['#date_date_max'] ?? $element['#date_max'] ?? ''),
        ];
      }
      elseif (!$choice && isset($element['#options'])) {
        $options = WebformOptions::getElementOptions($element);
        $flat = [];
        array_walk_recursive($options, function ($label, $value) use (&$flat) {
          $flat[] = ['value' => (string) $value, 'label' => strip_tags((string) $label)];
        });
        if ($flat) {
          $choice = [
            'key' => $key,
            'title' => (string) ($element['#title'] ?? ''),
            'options' => $flat,
          ];
        }
      }
    }
    return $choice && $date ? [$choice, $date] : NULL;
  }

  /**
   * Finds the optional question asked when the visitor picks today.
   *
   * Any choice element whose wrapper has the psp-same-day-question class
   * (Book Online's "Do you need emergency service?").
   *
   * @return array|null
   *   key/title/options, or NULL when the webform has none.
   */
  protected function sameDayQuestion(WebformInterface $webform): ?array {
    foreach ($webform->getElementsInitializedFlattenedAndHasValue() as $key => $element) {
      $classes = $element['#wrapper_attributes']['class'] ?? [];
      if (!in_array('psp-same-day-question', (array) $classes, TRUE) || !isset($element['#options'])) {
        continue;
      }
      $options = WebformOptions::getElementOptions($element);
      $flat = [];
      array_walk_recursive($options, function ($label, $value) use (&$flat) {
        $flat[] = ['value' => (string) $value, 'label' => strip_tags((string) $label)];
      });
      return [
        'key' => $key,
        'title' => (string) ($element['#title'] ?? ''),
        'options' => $flat,
      ];
    }
    return NULL;
  }

  /**
   * Converts a date element's max ("+3 months", "2026-12-31") to months ahead.
   */
  protected function monthsAhead(string $max): int {
    $timestamp = $max !== '' ? strtotime($max) : FALSE;
    if (!$timestamp) {
      return self::DEFAULT_MONTHS_AHEAD;
    }
    $now = new \DateTime('first day of this month');
    $end = (new \DateTime())->setTimestamp($timestamp);
    $months = ((int) $end->format('Y') - (int) $now->format('Y')) * 12 + (int) $end->format('n') - (int) $now->format('n');
    return max(0, min(24, $months));
  }

}
