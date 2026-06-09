<?php

namespace Drupal\meridian\ThemeSettings;

use Drupal\dripyard_base\ThemeSettings\RecipeInstaller as RecipeInstallerBase;

class RecipeInstaller extends RecipeInstallerBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   Array of available recipes.
   */
  protected function getAvailableRecipes(): array {
    return [
      'dripyard_meridian_blocks' => [
        'machine_name' => 'dripyard_meridian_blocks',
        'title' => t('Meridian Blocks'),
        'description' => t('This recipe provides a set of block types based on the single directory components of this theme. These work well with layout builder, but can be used with other page layout modules.'),
        'extended_by' => ['dripyard_meridian_demo_content', 'dripyard_meridian_landing_pages'],
      ],
      'dripyard_meridian_nodes' => [
        'machine_name' => 'dripyard_meridian_nodes',
        'title' => t('Meridian Nodes'),
        'description' => t('This recipe provides configurations and view modes for the article node type. These provide examples how how to theme nodes with the theme single directory components.'),
        'extended_by' => ['dripyard_meridian_demo_content'],
      ],
      'dripyard_meridian_demo_content' => [
        'machine_name' => 'dripyard_meridian_demo_content',
        'title' => t('Meridian Demo Content'),
        'description' => t('This recipe provides the demo content for meridian use to build <a href="https://meridian.dripyard.com" target="_blank">https://meridian.dripyard.com</a> including article nodes, and block content.'),
        'extended_by' => ['dripyard_meridian_layout_builder_demo', 'dripyard_meridian_canvas_demo'],
      ],
      'dripyard_meridian_landing_pages' => [
        'machine_name' => 'dripyard_meridian_landing_pages',
        'title' => t('Meridian Landing Pages'),
        'description' => t('This recipe provides a landing page content type based on layout builder. It will allow you to place the meridian blocks from the recipe above in page layouts.'),
        'extended_by' => ['dripyard_meridian_layout_builder_demo'],
      ],
      'dripyard_meridian_layout_builder_demo' => [
        'machine_name' => 'dripyard_meridian_layout_builder_demo',
        'title' => t('Meridian Layout Builder Demo'),
        'description' => t('This recipe provides a <em>Layout Builder</em> based install of <a href="https://meridian.dripyard.com" target="_blank">https://meridian.dripyard.com</a>. It includes a landing page with various blocks and configurations.<br><strong>This is a content demo and will add entities to your site.</strong>'),
        'extended_by' => [],
      ],
      'dripyard_meridian_canvas_demo' => [
        'machine_name' => 'dripyard_meridian_canvas_demo',
        'title' => t('Meridian Drupal Canvas Demo'),
        'description' => t('This recipe provides a <em>Drupal Canvas</em> based install of <a href="https://meridian.dripyard.com" target="_blank">https://meridian.dripyard.com</a>. It includes a canvas page with various components and configurations.<br><strong>This is a content demo and will add entities to your site.</strong>'),
        'extended_by' => [],
        'dependencies' => ['canvas'],
      ],
      'dripyard_meridian_canvas_patterns' => [
        'machine_name' => 'dripyard_meridian_canvas_patterns',
        'title' => t('Meridian Drupal Canvas Patterns'),
        'description' => t('This recipe provides component patterns to be used with <em>Drupal Canvas</em>.'),
        'extended_by' => ['dripyard_meridian_canvas_demo'],
        'dependencies' => ['canvas'],
      ],

    ];
  }

}
