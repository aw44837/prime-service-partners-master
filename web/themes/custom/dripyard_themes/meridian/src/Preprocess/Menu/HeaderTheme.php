<?php

namespace Drupal\meridian\Preprocess\Menu;

use Drupal\dripyard_base\Preprocess\PreprocessBase;

/**
 * Presprocess logic for menu theme.
 */
class HeaderTheme extends PreprocessBase {

  /**
   * {@inheritdoc}
   */
  public function preprocess(&$variables): void {
    // Get header settings from configuration.
    $header_settings = \Drupal::config($this->getTheme() . '.settings')->get('header_settings')
      ?? [
        'theme' => 'inherit',
      ];

    // Make header theme available as a variable for the component prop.
    $variables['header_theme'] = $header_settings['theme'];
  }

  /**
   * {@inheritdoc}
   */
  public function applies($variables): bool {
    // Applies to all menu preprocessors.
    return TRUE;
  }

}
