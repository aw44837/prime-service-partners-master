<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the active service area's menu, falling back to a fixed menu.
 *
 * Renders through the core 'menu' theme hook with a region attribute so
 * dripyard_base's menu__region_<region> template suggestion applies — the
 * header mega-nav styling works for any area menu, not just 'main'.
 *
 * @Block(
 *   id = "psp_area_menu",
 *   admin_label = @Translation("Service area menu"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaMenuBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected MenuLinkTreeInterface $menuLinkTree;
  protected AreaResolver $areaResolver;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->menuLinkTree = $container->get('menu.link_tree');
    $instance->areaResolver = $container->get('psp_service_area.area_resolver');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'fallback_menu' => 'main',
      'expand_all_items' => TRUE,
      'depth' => 2,
      'fallback_depth' => 3,
      'region_suggestion' => 'header_second',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $menus = $this->entityTypeManager->getStorage('menu')->loadMultiple();
    $options = [];
    foreach ($menus as $menu) {
      $options[$menu->id()] = $menu->label();
    }
    asort($options);

    $form['fallback_menu'] = [
      '#type' => 'select',
      '#title' => $this->t('Fallback menu'),
      '#description' => $this->t('Menu rendered when the visitor is not inside a service area (or the area has no menu).'),
      '#options' => $options,
      '#default_value' => $this->configuration['fallback_menu'],
      '#required' => TRUE,
    ];
    $form['expand_all_items'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expand all items'),
      '#default_value' => $this->configuration['expand_all_items'],
    ];
    $form['depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Maximum depth (service area menus)'),
      '#options' => [0 => $this->t('Unlimited')] + array_combine(range(1, 9), range(1, 9)),
      '#default_value' => $this->configuration['depth'],
    ];
    $form['fallback_depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Maximum depth (fallback menu)'),
      '#description' => $this->t('Depth used when the fallback menu renders (outside any service area) — lets the main menu go deeper than the area menus.'),
      '#options' => [0 => $this->t('Unlimited')] + array_combine(range(1, 9), range(1, 9)),
      '#default_value' => $this->configuration['fallback_depth'],
    ];
    $form['region_suggestion'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Region template suggestion'),
      '#description' => $this->t('Theme region name used for the menu__region_* template suggestion (e.g. header_second).'),
      '#default_value' => $this->configuration['region_suggestion'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['fallback_menu'] = $form_state->getValue('fallback_menu');
    $this->configuration['expand_all_items'] = (bool) $form_state->getValue('expand_all_items');
    $this->configuration['depth'] = (int) $form_state->getValue('depth');
    $this->configuration['fallback_depth'] = (int) $form_state->getValue('fallback_depth');
    $this->configuration['region_suggestion'] = $form_state->getValue('region_suggestion');
  }

  /**
   * Resolves which menu to render for the current request.
   */
  protected function resolveMenuName(): string {
    $term = $this->areaResolver->resolve();
    if ($term && $term->hasField('field_area_menu') && !$term->get('field_area_menu')->isEmpty()) {
      $menu_id = $term->get('field_area_menu')->target_id;
      if ($menu_id && $this->entityTypeManager->getStorage('menu')->load($menu_id)) {
        return $menu_id;
      }
    }
    return $this->configuration['fallback_menu'];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $menu_name = $this->resolveMenuName();
    // Area menus and the fallback (main) menu can have different depths —
    // e.g. a 3-level main mega-menu while area dropdowns stay 2 levels.
    $depth = $menu_name === $this->configuration['fallback_menu']
      ? (int) ($this->configuration['fallback_depth'] ?? $this->configuration['depth'])
      : (int) $this->configuration['depth'];

    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks()->setMinDepth(1);
    if ($depth > 0) {
      $parameters->setMaxDepth($depth);
    }
    if ($this->configuration['expand_all_items']) {
      $parameters->expandedParents = [];
    }

    $tree = $this->menuLinkTree->load($menu_name, $parameters);
    $tree = $this->menuLinkTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
    $build = $this->menuLinkTree->build($tree);

    if ($this->configuration['region_suggestion'] !== '') {
      // Triggers dripyard_base's menu__region_<region> template suggestion.
      $build['#attributes']['region'] = $this->configuration['region_suggestion'];
    }
    $build['#cache']['tags'][] = 'config:system.menu.' . $menu_name;

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    $menu_name = $this->resolveMenuName();
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'service_area',
      'route.menu_active_trails:' . $menu_name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:system.menu.' . $this->resolveMenuName(),
      'taxonomy_term_list:' . AreaResolver::VOCABULARY,
    ]);
  }

}
