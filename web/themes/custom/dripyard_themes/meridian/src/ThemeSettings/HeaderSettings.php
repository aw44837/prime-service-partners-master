<?php

namespace Drupal\meridian\ThemeSettings;

use Drupal\dripyard_base\ThemeSettings\ThemeSettingsBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides form and theme settings for header customization.
 */
class HeaderSettings extends ThemeSettingsBase {

  /**
   * The theme settings form alter.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function themeSettingsFormAlter(array &$form, FormStateInterface $form_state): void {
    parent::themeSettingsFormAlter($form, $form_state);
    $config = \Drupal::config($this->getTheme() . '.settings')->get('header_settings');

    // Define defaults.
    if (empty($config)) {
      $config = [
        'theme' => 'inherit',
        'logo_adjustments' => [
          'when_hero' => 'no_change',
          'when_stickied' => 'no_change',
        ],
      ];
    }

    $form['header_settings'] = [
      '#type' => 'details',
      '#title' => t('Header Settings'),
      '#tree' => TRUE,
    ];

    $form['header_settings']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => t('These settings control the appearance and behavior of the site header.'),
    ];

    $form['header_settings']['theme'] = [
      '#type' => 'select',
      '#title' => t('Theme'),
      '#description' => t('Color theme of the header.'),
      '#default_value' => $config['theme'],
      '#options' => [
        'inherit' => t('Inherit'),
        'white' => t('White'),
        'light' => t('Light'),
        'dark' => t('Dark'),
        'black' => t('Black'),
        'primary' => t('Primary'),
        'secondary' => t('Secondary'),
      ],
    ];

    $form['header_settings']['logo_adjustments'] = [
      '#type' => 'fieldset',
      '#title' => t('Logo adjustments'),
      '#tree' => TRUE,
    ];

    $form['header_settings']['logo_adjustments']['when_hero'] = [
      '#type' => 'select',
      '#title' => t('When placed in front of hero'),
      '#description' => t("If logo is superimposed over a hero, use the CSS <code>filter</code> property to adjust brightness of the logo to match the hero's text."),
      '#default_value' => $config['logo_adjustments']['when_hero'] ?? 'no_change',
      '#options' => [
        'no_change' => t('No change (default)'),
        'match_hero_theme' => t('Match theme of hero'),
      ],
    ];

    $form['header_settings']['logo_adjustments']['when_stickied'] = [
      '#type' => 'select',
      '#title' => t('When stickied'),
      '#description' => t("After the page is scrolled and the header is sticky, use the CSS <code>filter</code> property to adjust brightness of the logo to match the header's text."),
      '#default_value' => $config['logo_adjustments']['when_stickied'] ?? 'no_change',
      '#options' => [
        'no_change' => t('No change (default)'),
        'match_header_theme' => t('Match theme of header'),
      ],
    ];

    $form['#submit'][] = [self::class, 'submitHeaderSettings'];
  }

  /**
   * Submit callback to save the settings.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submitHeaderSettings(array &$form, FormStateInterface $form_state): void {
    $header_settings = $form_state->getValue(['header_settings']);
    $theme = $form_state->get('theme_name');
    \Drupal::configFactory()->getEditable($theme . '.settings')
      ->set('header_settings', $header_settings)
      ->save();
  }

}
