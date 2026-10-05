<?php

namespace Drupal\psp_request_service;

/**
 * Trade icons for the booking card's service buttons.
 *
 * Same artwork and colours as the Progressive hero's trade badges (24×24,
 * stroke 2); colours come from --psp-trade-* when a theme sets them. Options
 * are matched to an icon by keyword, so any site's service list works.
 */
final class ServiceIcons {

  const PATHS = [
    'plumbing' => 'M12 3c3 4.2 5.5 7.2 5.5 10.3A5.5 5.5 0 0 1 12 19a5.5 5.5 0 0 1-5.5-5.7C6.5 10.2 9 7.2 12 3z',
    'heating' => 'M12 3c1 3-2.5 4.5-2.5 7a2.5 2.5 0 0 0 5 .2C16.5 12 19 13 19 15.5A7 7 0 0 1 5 15.5C5 10 10.5 8 12 3z',
    'air' => 'M12 12m-2 0a2 2 0 1 0 4 0 2 2 0 1 0-4 0M12 10c0-3 1-6 3.5-6S18 7.5 15 9M12 14c0 3-1 6-3.5 6S6 16.5 9 15M10 12c-3 0-6-1-6-3.5S7.5 6 9 9M14 12c3 0 6 1 6 3.5S16.5 18 15 15',
    'electric' => 'M13 2 5 13h5l-1 9 8-11h-5l1-9z',
    'other' => 'M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z',
  ];

  /**
   * Example text for the request description, by trade (never submitted).
   */
  const PLACEHOLDERS = [
    'plumbing' => 'e.g. Water heater leaking, no hot water, or a slow drain',
    'heating' => 'e.g. Furnace not turning on, or no heat upstairs',
    'air' => 'e.g. AC not cooling, making noise, or leaking water',
    'electric' => 'e.g. Breaker keeps tripping, or adding an EV charger',
    'other' => 'e.g. What is happening, and when it started',
  ];

  /**
   * Keywords (start of a word) for each trade, checked in order.
   */
  const KEYWORDS = [
    'plumbing' => ['plumb', 'drain', 'sewer', 'pipe', 'water', 'leak', 'toilet', 'faucet'],
    'heating' => ['heat', 'furnace', 'boiler'],
    'air' => ['air', 'ac', 'a/c', 'cool', 'hvac', 'duct'],
    'electric' => ['electr', 'power', 'panel', 'generator', 'light', 'wiring'],
  ];

  /**
   * The icon for a service option's label (or value).
   */
  public static function forLabel(string $label): string {
    $label = mb_strtolower($label);
    foreach (self::KEYWORDS as $trade => $words) {
      foreach ($words as $word) {
        if (preg_match('/(^|[^a-z])' . preg_quote($word, '/') . '/', $label)) {
          return $trade;
        }
      }
    }
    return 'other';
  }

}
