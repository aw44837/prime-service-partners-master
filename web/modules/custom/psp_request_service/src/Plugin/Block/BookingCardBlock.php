<?php

namespace Drupal\psp_request_service\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\psp_request_service\ServiceIcons;
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
   * Call option choices: style and position in one plain-language list.
   */
  const CALL_DISPLAYS = [
    'button_above' => 'Button above the card',
    'button_below' => 'Button below the card',
    'link_above' => 'Text link above the card',
    'link_below' => 'Text link below the card',
    'none' => 'No call option',
  ];

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
      'call_display' => 'button_above',
      'call_label' => 'Call',
      'call_link_intro' => 'Prefer to talk?',
      'phone' => '',
      'online_heading' => '',
      'choice_style' => 'icons',
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
    $form['call_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Call option'),
      '#description' => $this->t('A way to call instead of booking online.'),
      '#options' => array_map(fn($label) => $this->t($label), self::CALL_DISPLAYS),
      '#default_value' => $this->configuration['call_display'] ?? 'button_above',
    ];
    $form['call_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Call label'),
      '#description' => $this->t('Shown before the phone number, e.g. "Call".'),
      '#default_value' => $this->configuration['call_label'],
    ];
    $form['call_link_intro'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Text link lead-in'),
      '#description' => $this->t('For the text link options, e.g. "Prefer to talk?" before "Call (877) 325-0180".'),
      '#default_value' => $this->configuration['call_link_intro'] ?? 'Prefer to talk?',
    ];
    $form['phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone number'),
      '#description' => $this->t("Leave empty to use the site's main number (@phone).", ['@phone' => $this->sitePhone()['display'] ?: $this->t('none set')]),
      '#default_value' => $this->configuration['phone'],
    ];
    $form['online_heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Book online heading'),
      '#description' => $this->t('Optional heading under the title, e.g. "Or book online".'),
      '#default_value' => $this->configuration['online_heading'],
    ];
    $form['subtitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Book online text'),
      '#default_value' => $this->configuration['subtitle'],
    ];
    $form['choice_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Service buttons'),
      '#options' => [
        'icons' => $this->t('Animated trade icons'),
        'pills' => $this->t('Text buttons'),
      ],
      '#default_value' => $this->configuration['choice_style'],
    ];
    $form['button_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button label'),
      '#default_value' => $this->configuration['button_label'],
      '#required' => TRUE,
    ];
    // Selects, not radios: the Canvas editor sends radios back as "undefined"
    // whenever another setting changes.
    $form['display'] = [
      '#type' => 'select',
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
    // Anything but "pills" (incl. a stray value from the editor) means icons.
    $icons = ($this->configuration['choice_style'] ?? 'icons') !== 'pills';
    foreach ($choice['options'] as &$option) {
      $option['icon'] = ServiceIcons::forLabel($option['label']);
      $option['path'] = ServiceIcons::PATHS[$option['icon']];
    }
    unset($option);
    $phone = $this->phone();
    $preselect = $this->preselect($choice['options']);
    $subtitle = $this->configuration['subtitle'];
    // The service is decided by the page, so the card only asks for a day.
    if ($preselect !== '' && $subtitle === 'Pick a service and a time that works for you.') {
      $subtitle = 'Pick a time that works for you.';
    }
    $build = [
      '#theme' => 'psp_booking_card',
      '#title' => $this->configuration['title'],
      '#subtitle' => $subtitle,
      '#preselect' => $preselect,
      '#topic' => $this->pageTopic(),
      '#button_label' => $this->configuration['button_label'],
      '#display' => $this->configuration['display'] === 'inline' ? 'inline' : 'panel',
      '#theme_name' => in_array($this->configuration['theme'] ?? 'white', self::THEMES, TRUE) ? ($this->configuration['theme'] ?? 'white') : 'white',
      '#form_title' => $webform->label(),
      '#options' => $choice['options'],
      '#choice_name' => $choice['key'],
      '#choice_label' => $choice['title'],
      '#date_name' => $date['key'],
      '#months_ahead' => $date['months_ahead'],
      '#form_url' => Url::fromRoute('entity.webform.share_page', ['webform' => $webform->id()])->toString(),
      '#choice_style' => $icons ? 'icons' : 'pills',
      '#call_label' => trim((string) ($this->configuration['call_label'] ?? '')),
      '#call_display' => isset(self::CALL_DISPLAYS[$this->configuration['call_display'] ?? '']) ? $this->configuration['call_display'] : 'button_above',
      '#call_link_intro' => trim((string) ($this->configuration['call_link_intro'] ?? 'Prefer to talk?')),
      '#phone_display' => $phone['display'],
      '#phone_uri' => $phone['uri'],
      '#online_heading' => trim((string) ($this->configuration['online_heading'] ?? '')),
      '#attached' => ['library' => ['psp_request_service/booking_card']],
    ];
    $build['#cache']['tags'] = array_merge($webform->getCacheTags(), ['config:psp_service_area.settings', 'config:psp_request_service.settings']);
    // The pre-selected service depends on the page.
    $build['#cache']['contexts'][] = 'url.path';
    return $build;
  }

  /**
   * The title of the page the card is on (node or Canvas page), or ''.
   *
   * Sent with the booking as "Page topic" so staff see what the visitor was
   * reading. Not for the front page, whose title says nothing.
   */
  protected function pageTopic(): string {
    if (\Drupal::service('path.matcher')->isFrontPage()) {
      return '';
    }
    foreach (\Drupal::routeMatch()->getParameters() as $parameter) {
      if ($parameter instanceof \Drupal\Core\Entity\ContentEntityInterface && in_array($parameter->getEntityTypeId(), ['node', 'canvas_page'], TRUE)) {
        return mb_substr(trim((string) $parameter->label()), 0, 200);
      }
    }
    return '';
  }

  /**
   * The service this page pre-selects (booking_service_rules), or ''.
   *
   * Rules are "URL pattern|Service" lines; * matches anything and the most
   * specific matching pattern wins (an empty service means none). The service
   * must be one of the choice's options (value or label, any case).
   */
  protected function preselect(array $options): string {
    $rules = (string) \Drupal::config('psp_request_service.settings')->get('booking_service_rules');
    if (trim($rules) === '') {
      return '';
    }
    $path = \Drupal::service('path.current')->getPath();
    $alias = mb_strtolower(rtrim(\Drupal::service('path_alias.manager')->getAliasByPath($path), '/') ?: '/');
    $best = NULL;
    $score = -1;
    foreach (preg_split('/\R/', $rules) as $line) {
      if (!str_contains($line, '|')) {
        continue;
      }
      [$pattern, $service] = array_map('trim', explode('|', $line, 2));
      $pattern = mb_strtolower(rtrim($pattern, '/') ?: '/');
      $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#u';
      $specificity = mb_strlen(str_replace('*', '', $pattern));
      if (preg_match($regex, $alias) && $specificity > $score) {
        $best = $service;
        $score = $specificity;
      }
    }
    if ($best === NULL || $best === '') {
      return '';
    }
    foreach ($options as $option) {
      if (strcasecmp($option['value'], $best) === 0 || strcasecmp($option['label'], $best) === 0) {
        return $option['value'];
      }
    }
    return '';
  }

  /**
   * The phone for the call button: this block's, else the site's main one.
   *
   * @return array
   *   ['display' => '(877) 325-0180', 'uri' => 'tel:8773250180'] or empties.
   */
  protected function phone(): array {
    $display = trim((string) ($this->configuration['phone'] ?? ''));
    if ($display !== '') {
      $digits = preg_replace('/\D+/', '', $display);
      return ['display' => $display, 'uri' => $digits !== '' ? 'tel:' . $digits : ''];
    }
    return $this->sitePhone();
  }

  /**
   * The site's main phone (PSP service-area settings), if set.
   */
  protected function sitePhone(): array {
    $config = \Drupal::config('psp_service_area.settings');
    $display = trim((string) $config->get('default_phone_display'));
    $uri = trim((string) $config->get('default_phone_uri'));
    if ($display !== '' && $uri === '') {
      $uri = 'tel:' . preg_replace('/\D+/', '', $display);
    }
    return ['display' => $display, 'uri' => $display !== '' ? $uri : ''];
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
