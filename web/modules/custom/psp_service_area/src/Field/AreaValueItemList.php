<?php

namespace Drupal\psp_service_area\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\taxonomy\TermInterface;

/**
 * Computed field item list for area-derived node values.
 *
 * Handles psp_area_phone_uri, psp_area_phone_display and
 * psp_area_booking_url. The value comes from the node's field_service_area
 * term; when the node has no area (or the term field is empty) it falls
 * back to psp_service_area.settings defaults, so global content always has
 * working CTAs.
 */
class AreaValueItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $entity = $this->getEntity();
    $term = NULL;
    if ($entity->hasField('field_service_area') && !$entity->get('field_service_area')->isEmpty()) {
      $candidate = $entity->get('field_service_area')->entity;
      if ($candidate instanceof TermInterface) {
        $term = $candidate;
      }
    }

    $settings = \Drupal::config('psp_service_area.settings');
    $value = NULL;

    switch ($this->getFieldDefinition()->getName()) {
      case 'psp_area_phone_display':
        $value = $this->termValue($term, 'field_phone') ?? $settings->get('default_phone_display');
        break;

      case 'psp_area_phone_uri':
        $phone = $this->termValue($term, 'field_phone');
        $value = $phone !== NULL
          ? 'tel:' . preg_replace('/[^0-9+\-]/', '', $phone)
          : $settings->get('default_phone_uri');
        break;

      case 'psp_area_booking_url':
        $value = $this->termValue($term, 'field_booking_url', 'uri') ?? $settings->get('default_booking_url');
        break;
    }

    if ($value !== NULL && $value !== '') {
      $this->list[0] = $this->createItem(0, $value);
    }
  }

  /**
   * Reads a property from a term field, NULL when missing or empty.
   */
  protected function termValue(?TermInterface $term, string $field_name, string $property = 'value'): ?string {
    if (!$term || !$term->hasField($field_name) || $term->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $term->get($field_name)->first()->get($property)->getValue();
    return ($value === NULL || $value === '') ? NULL : (string) $value;
  }

}
