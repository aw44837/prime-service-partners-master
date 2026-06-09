<?php

namespace Drupal\jefco_menu_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configurable menu block.
 *
 * Supports any menu, configurable start level, depth, expand behavior,
 * and active-trail following for contextual sidebar navigation.
 *
 * @Block(
 *   id = "jefco_menu_block",
 *   admin_label = @Translation("Menu Block"),
 *   category = @Translation("JefCo"),
 * )
 */
class MenuBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected MenuLinkTreeInterface $menuLinkTree;

  /**
   * The active trail service.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected MenuActiveTrailInterface $activeTrail;

  /**
   * The menu entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $menuStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MenuLinkTreeInterface $menu_link_tree,
    MenuActiveTrailInterface $active_trail,
    EntityStorageInterface $menu_storage,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->menuLinkTree = $menu_link_tree;
    $this->activeTrail = $active_trail;
    $this->menuStorage = $menu_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree'),
      $container->get('menu.active_trail'),
      $container->get('entity_type.manager')->getStorage('menu'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'menu_name'           => 'main',
      'level'               => 1,
      'depth'               => 0,
      'expand_all_items'    => FALSE,
      'follow_active_trail' => FALSE,
      'columns'             => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $config = $this->configuration;

    // Build an alphabetical list of all menus.
    $menus = $this->menuStorage->loadMultiple();
    $menu_options = [];
    foreach ($menus as $menu) {
      $menu_options[$menu->id()] = $menu->label();
    }
    asort($menu_options);

    $form['menu_name'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Menu'),
      '#options'       => $menu_options,
      '#default_value' => $config['menu_name'],
      '#required'      => TRUE,
    ];

    $level_options = [];
    for ($i = 1; $i <= 9; $i++) {
      $level_options[$i] = $this->t('Level @level', ['@level' => $i]);
    }

    $form['level'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Starting level'),
      '#description'   => $this->t(
        'Which level of the menu to start rendering from. Level 1 = top-level items. '
        . 'When <em>Follow active trail</em> is on, this selects which ancestor in the '
        . 'current page\'s trail to use as the root (1 = top-level section, 2 = subsection, etc.).'
      ),
      '#options'       => $level_options,
      '#default_value' => $config['level'],
    ];

    $depth_options = [0 => $this->t('Unlimited')];
    for ($i = 1; $i <= 9; $i++) {
      $depth_options[$i] = $this->t('@depth level(s)', ['@depth' => $i]);
    }

    $form['depth'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Menu depth'),
      '#description'   => $this->t('How many levels to render below the starting point. 0 = show everything.'),
      '#options'       => $depth_options,
      '#default_value' => $config['depth'],
    ];

    $form['follow_active_trail'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Follow active trail'),
      '#description'   => $this->t(
        'Root the menu at the current page\'s ancestor at the configured starting level. '
        . 'Ideal for sidebar navigation — the block automatically shows the submenu for '
        . 'whatever section the visitor is browsing.'
      ),
      '#default_value' => $config['follow_active_trail'],
    ];

    $form['expand_all_items'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Expand all items'),
      '#description'   => $this->t('Show all child items regardless of whether they are in the active trail.'),
      '#default_value' => $config['expand_all_items'],
    ];

    $form['columns'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Maximum columns'),
      '#description'   => $this->t(
        'Maximum number of columns for top-level items. Uses container queries — '
        . 'the actual column count adapts to the available width, so a narrow sidebar '
        . 'will always stay one column regardless of this setting.'
      ),
      '#options'       => [
        1 => $this->t('1 column (always)'),
        2 => $this->t('Up to 2 columns'),
        3 => $this->t('Up to 3 columns'),
      ],
      '#default_value' => $config['columns'] ?? 1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['menu_name']           = $form_state->getValue('menu_name');
    $this->configuration['level']               = (int) $form_state->getValue('level');
    $this->configuration['depth']               = (int) $form_state->getValue('depth');
    $this->configuration['follow_active_trail'] = (bool) $form_state->getValue('follow_active_trail');
    $this->configuration['expand_all_items']    = (bool) $form_state->getValue('expand_all_items');
    $this->configuration['columns']             = (int) $form_state->getValue('columns');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $menu_name   = $this->configuration['menu_name'];
    $level       = $this->configuration['level'];
    $depth       = $this->configuration['depth'];
    $expand_all  = $this->configuration['expand_all_items'];
    $follow_trail = $this->configuration['follow_active_trail'];

    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();

    if ($follow_trail) {
      // Active trail is ordered deepest → root, with '' representing the
      // virtual menu root. Filter it out and work with real link IDs only.
      $raw_trail  = $this->activeTrail->getActiveTrailIds($menu_name);
      $trail_ids  = array_values(array_filter($raw_trail));
      $trail_depth = count($trail_ids);

      // Pick the ancestor at the requested level. Level 1 = top-level
      // section (last item in the trail array), level 2 = one level deeper, etc.
      $target_index = $trail_depth - $level;

      if ($target_index >= 0 && isset($trail_ids[$target_index])) {
        $parameters->setRoot($trail_ids[$target_index]);
        $parameters->setMinDepth(1);
        if ($depth > 0) {
          $parameters->setMaxDepth($depth);
        }
      }
      else {
        // Not deep enough in the trail — fall back to rendering from the
        // configured level so the block still shows something.
        $parameters->setMinDepth($level);
        if ($depth > 0) {
          $parameters->setMaxDepth(min($level + $depth - 1, 9));
        }
      }
    }
    else {
      $parameters->setMinDepth($level);
      if ($depth > 0) {
        $parameters->setMaxDepth(min($level + $depth - 1, 9));
      }
    }

    // Expand all items if requested.
    if ($expand_all) {
      $parameters->expandedParents = [];
    }

    $tree = $this->menuLinkTree->load($menu_name, $parameters);

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];

    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    // Extract items from the built menu tree. We use our own theme hook
    // (jefco_menu_block) instead of Drupal's 'menu' hook so the block renders
    // through the module's own template — completely bypassing theme menu
    // templates (e.g. menu--main.html.twig → meridian:primary-menu) and
    // Canvas's component rendering pipeline.
    $menu_build = $this->menuLinkTree->build($tree);
    $items = $menu_build['#items'] ?? [];

    $build = [
      '#theme'    => 'jefco_menu_block',
      '#items'    => $items,
      '#level'    => 1,
      '#columns'  => (int) ($this->configuration['columns'] ?? 1),
      '#cache'    => $menu_build['#cache'] ?? [],
      '#attached' => ['library' => ['jefco_menu_block/menu-block']],
    ];

    // Tag so the block re-renders if the menu config changes.
    $build['#cache']['tags'][] = 'config:system.menu.' . $menu_name;

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(
      parent::getCacheTags(),
      ['config:system.menu.' . $this->configuration['menu_name']]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(
      parent::getCacheContexts(),
      ['route.menu_active_trails:' . $this->configuration['menu_name']]
    );
  }

}
