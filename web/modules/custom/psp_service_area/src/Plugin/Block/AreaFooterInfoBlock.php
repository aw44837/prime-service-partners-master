<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Footer info: company name, active area's address, phone, map link and
 * license numbers, with site-wide defaults outside any service area.
 *
 * Markup mirrors the "Footer Address" basic block this replaces.
 *
 * @Block(
 *   id = "psp_area_footer_info",
 *   admin_label = @Translation("Service area footer info"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaFooterInfoBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $settings = \Drupal::config('psp_service_area.settings');

    $company = $settings->get('default_company_name');
    $address = $this->areaResolver->areaValue($term, 'field_address', 'default_address');
    $phone_display = $this->areaResolver->areaValue($term, 'field_phone', 'default_phone_display');
    $phone_uri = $phone_display !== NULL ? 'tel:' . preg_replace('/[^0-9+\-]/', '', $phone_display) : NULL;
    $map_url = $this->areaResolver->areaValue($term, 'field_map_link', 'default_map_url', 'uri');

    $licenses = [];
    if ($term && $term->hasField('field_license_numbers')) {
      foreach ($term->get('field_license_numbers') as $item) {
        if ($item->value !== NULL && $item->value !== '') {
          $licenses[] = $item->value;
        }
      }
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<p><strong>{{ company }}</strong>{% for line in address_lines %}<br>{{ line }}{% endfor %}{% if phone_display %}<br><a href="{{ phone_uri }}">{{ phone_display }}</a>{% endif %}</p>{% if map_url %}<p><a href="{{ map_url }}" target="_blank"><strong>{{ "Map & Directions"|t }}</strong></a></p>{% endif %}{% if licenses %}<p class="footer-licenses">{% for license in licenses %}{{ license }}{% if not loop.last %}<br>{% endif %}{% endfor %}</p>{% endif %}',
      '#context' => [
        'company' => $company,
        'address_lines' => $address !== NULL ? preg_split('/\r\n|\r|\n/', $address) : [],
        'phone_display' => $phone_display,
        'phone_uri' => $phone_uri,
        'map_url' => $map_url,
        'licenses' => $licenses,
      ],
    ];
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
    return Cache::mergeTags(parent::getCacheTags(), [
      'taxonomy_term_list:' . AreaResolver::VOCABULARY,
      'config:psp_service_area.settings',
    ]);
  }

}
