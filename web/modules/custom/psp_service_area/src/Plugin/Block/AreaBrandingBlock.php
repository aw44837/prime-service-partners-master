<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site logo that links to the active service area's landing page.
 *
 * Replicates dripyard_base:header-logo markup; outside a service area the
 * logo links to the front page exactly like the stock branding block.
 *
 * @Block(
 *   id = "psp_area_branding",
 *   admin_label = @Translation("Service area site branding"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaBrandingBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected AreaResolver $areaResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->areaResolver = $container->get('psp_service_area.area_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $term = $this->areaResolver->resolve();

    $href = Url::fromRoute('<front>')->toString();
    if ($term && $term->hasField('field_landing_page') && !$term->get('field_landing_page')->isEmpty()) {
      $href = $term->get('field_landing_page')->first()->getUrl()->toString();
    }

    $logo = theme_get_setting('logo.url');

    $build = [
      '#type' => 'inline_template',
      '#template' => '<div class="header-logo"><a class="header-logo__link" href="{{ href }}" rel="home">{% if logo %}<img class="header-logo__image" src="{{ logo }}" alt="{{ "Home"|t }}" fetchpriority="high" />{% endif %}</a></div>',
      '#context' => [
        'href' => $href,
        'logo' => $logo,
      ],
    ];

    // The markup replicates the header-logo SDC, whose CSS (logo max-height,
    // hero/sticky brightness rules) ships as a component library that only
    // attaches when the SDC renders — attach it explicitly or the logo
    // renders uncapped and stretches the header bar.
    $discovery = \Drupal::service('library.discovery');
    foreach (['components.meridian--header-logo', 'components.dripyard_base--header-logo'] as $library) {
      if ($discovery->getLibraryByName('core', $library)) {
        $build['#attached']['library'][] = 'core/' . $library;
        break;
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['service_area']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['taxonomy_term_list:' . AreaResolver::VOCABULARY]);
  }

}
