<?php

namespace Drupal\psp_service_area\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\psp_service_area\AreaResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Call CTA showing the active service area's phone number.
 *
 * Renders through the dripyard_base:button SDC — identical markup to the
 * dripyard_button block_content it replaces. Button text is the phone
 * number itself, matching the previous header CTA.
 *
 * @Block(
 *   id = "psp_area_call_button",
 *   admin_label = @Translation("Service area call button"),
 *   category = @Translation("Prime Service Partners"),
 * )
 */
class AreaCallButtonBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  public function defaultConfiguration(): array {
    return [
      'style' => 'secondary',
      'size' => 'small',
      'suffix_icon' => 'phone',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Button style'),
      '#options' => [
        'default' => $this->t('Default'),
        'primary' => $this->t('Primary'),
        'secondary' => $this->t('Secondary'),
        'outline' => $this->t('Outline'),
      ],
      '#default_value' => $this->configuration['style'],
    ];
    $form['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Button size'),
      '#options' => [
        'small' => $this->t('Small'),
        'medium' => $this->t('Medium'),
        'large' => $this->t('Large'),
      ],
      '#default_value' => $this->configuration['size'],
    ];
    $form['suffix_icon'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix icon'),
      '#default_value' => $this->configuration['suffix_icon'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['style'] = $form_state->getValue('style');
    $this->configuration['size'] = $form_state->getValue('size');
    $this->configuration['suffix_icon'] = $form_state->getValue('suffix_icon');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $term = $this->areaResolver->resolve();
    $display = $this->areaResolver->areaValue($term, 'field_phone', 'default_phone_display');
    if ($display === NULL) {
      return [];
    }
    $href = $term && !$term->get('field_phone')->isEmpty()
      ? 'tel:' . preg_replace('/[^0-9+\-]/', '', $display)
      : \Drupal::config('psp_service_area.settings')->get('default_phone_uri');

    return [
      '#type' => 'inline_template',
      '#template' => "{{ include('dripyard_base:button', { href: href, text: text, style: style, size: size, suffix_icon: suffix_icon }, with_context = false) }}",
      '#context' => [
        'href' => $href,
        'text' => $display,
        'style' => $this->configuration['style'],
        'size' => $this->configuration['size'],
        'suffix_icon' => $this->configuration['suffix_icon'],
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
