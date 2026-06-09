<?php

namespace Drupal\meridian\Preprocess\Page;

use Drupal\dripyard_base\Preprocess\PreprocessBase;

/**
 * Preprocesses page template to add header attributes based on settings.
 */
class HeaderSettingsPreprocessor extends PreprocessBase {

  /**
   * {@inheritdoc}
   */
  public function applies($variables): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocess(&$variables): void {
    $theme_config = \Drupal::config($this->getTheme() . '.settings');

    $header_settings = $theme_config->get('header_settings') ?? [
      'theme' => 'inherit',
      'logo_adjustments' => [
        'when_hero' => 'no_change',
        'when_stickied' => 'no_change',
      ],
    ];
    $site_settings = $theme_config->get('theme_colors') ?? [
      'site_theme' => 'white',
    ];

    // Make site and header themes available as variables for component props.
    $variables['header_settings']['header_theme'] = $header_settings['theme'];
    $variables['header_settings']['site_theme'] = $site_settings['site_theme'];

    // Make logo adjustment settings available as variables.
    $variables['header_settings']['logo_when_hero'] = $header_settings['logo_adjustments']['when_hero'] ?? 'no_change';
    $variables['header_settings']['logo_when_stickied'] = $header_settings['logo_adjustments']['when_stickied'] ?? 'no_change';
  }

}
