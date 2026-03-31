<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;

/**
 * Composite form element: entity_autocomplete display + hidden int value.
 *
 * Always returns an integer UID from $form_state->getValue(). Modeled after
 * Drupal core's PasswordConfirm element (composite with value collapsing).
 *
 * Properties:
 * - #default_value: (int) The user UID, or 0 for none.
 * - #target_type: (string) Entity type, defaults to 'user'.
 * - #selection_handler: (string) Selection handler plugin ID.
 * - #selection_settings: (array) Settings for the selection handler.
 * - #autocomplete_route_name: (string|null) Custom autocomplete route.
 *
 * Usage:
 * @code
 * $form['executor_uid'] = [
 *   '#type' => 'user_reference_autocomplete',
 *   '#title' => $this->t('Executor account'),
 *   '#default_value' => 42,
 * ];
 * @endcode
 */
#[FormElement('user_reference_autocomplete')]
class UserReferenceAutocomplete extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#default_value' => 0,
      '#target_type' => 'user',
      '#selection_handler' => 'default',
      '#selection_settings' => [],
      '#autocomplete_route_name' => NULL,
      '#process' => [
        [static::class, 'buildElement'],
      ],
      // Note: no #element_validate here. EntityForm::validateForm() calls
      // buildEntity() → copyFormValuesToEntity() BEFORE element validators
      // run, which corrupts the composite value. Normalization to int is
      // handled in AgentProfileForm::copyFormValuesToEntity() instead.
      '#attached' => [
        'library' => ['ai_claude_agent_sdk/user-reference-autocomplete'],
      ],
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input === FALSE) {
      // Initial build: return composite structure with the default UID.
      return [
        'autocomplete_display' => '',
        'value' => (int) ($element['#default_value'] ?? 0),
      ];
    }

    // Form submission / AJAX: extract from hidden field.
    return [
      'autocomplete_display' => $input['autocomplete_display'] ?? '',
      'value' => (int) ($input['value'] ?? 0),
    ];
  }

  /**
   * Process callback: builds the autocomplete display + hidden value fields.
   */
  public static function buildElement(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $uid = (int) ($element['#value']['value'] ?? $element['#default_value'] ?? 0);

    // Load the user entity for the autocomplete display default value.
    $userEntity = NULL;
    if ($uid > 0) {
      $userEntity = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    }

    // Visible autocomplete field (display only — value is not used).
    // Disable all validation: this field is for display/selection UX only.
    // The hidden 'value' field is the source of truth.
    $element['autocomplete_display'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => $element['#target_type'],
      '#selection_handler' => $element['#selection_handler'],
      '#selection_settings' => $element['#selection_settings'],
      '#default_value' => $userEntity,
      '#validate_reference' => FALSE,
      '#process_default_value' => TRUE,
      '#element_validate' => [],
      '#attributes' => [
        'data-user-ref-display' => TRUE,
      ],
    ];

    // Pass through custom autocomplete route if set.
    if (!empty($element['#autocomplete_route_name'])) {
      $element['autocomplete_display']['#autocomplete_route_name'] = $element['#autocomplete_route_name'];
    }

    // Hidden field: the single source of truth for the UID value.
    $element['value'] = [
      '#type' => 'hidden',
      '#default_value' => $uid,
      '#attributes' => [
        'data-user-ref-value' => TRUE,
      ],
    ];

    // Transfer AJAX from parent to the autocomplete display field.
    // Drupal's AJAX system does not bind to #type 'hidden' elements, so
    // AJAX must be on the visible autocomplete instead. The JS behavior
    // syncs the hidden field on 'autocompleteclose' before AJAX fires.
    if (!empty($element['#ajax'])) {
      $ajax = $element['#ajax'];
      // Ensure autocompleteclose is in the event list so AJAX fires on selection.
      if (!empty($ajax['event']) && strpos($ajax['event'], 'autocompleteclose') === FALSE) {
        $ajax['event'] = 'autocompleteclose ' . $ajax['event'];
      }
      $element['autocomplete_display']['#ajax'] = $ajax;
      unset($element['#ajax']);
    }

    return $element;
  }

  /**
   * Element validate: collapses composite value to a bare int.
   *
   * After this runs, $form_state->getValue('element_name') returns int.
   */
  public static function validateElement(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $value = $form_state->getValue($element['#parents']);
    // Handle both composite array (normal) and already-collapsed int
    // (if copyFormValuesToEntity ran before validation during form rebuild).
    $uid = is_array($value) ? (int) ($value['value'] ?? 0) : (int) $value;

    // Nullify sub-element values so they don't leak into form state.
    $form_state->setValueForElement($element['autocomplete_display'], NULL);
    $form_state->setValueForElement($element['value'], NULL);

    // Set the parent element's value to a bare int.
    $form_state->setValueForElement($element, $uid);
  }

}
