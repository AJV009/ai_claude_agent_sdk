<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Element\UserReferenceAutocomplete;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the UserReferenceAutocomplete form element.
 *
 * @group ai_claude_agent_sdk
 */
class UserReferenceAutocompleteElementTest extends TestCase {

  /**
   * Tests valueCallback returns composite with int from hidden field input.
   */
  public function testValueCallbackReturnsCompositeFromInput(): void {
    $element = ['#default_value' => 0];
    $input = ['autocomplete_display' => 'admin (1)', 'value' => '42'];
    $form_state = $this->createMock(FormStateInterface::class);

    $result = UserReferenceAutocomplete::valueCallback($element, $input, $form_state);

    $this->assertIsArray($result);
    $this->assertSame(42, $result['value']);
    $this->assertSame('admin (1)', $result['autocomplete_display']);
  }

  /**
   * Tests valueCallback returns composite with default value when no input.
   */
  public function testValueCallbackReturnsCompositeFromDefault(): void {
    $element = ['#default_value' => 42];
    $input = FALSE;
    $form_state = $this->createMock(FormStateInterface::class);

    $result = UserReferenceAutocomplete::valueCallback($element, $input, $form_state);

    $this->assertIsArray($result);
    $this->assertSame(42, $result['value']);
    $this->assertSame('', $result['autocomplete_display']);
  }

  /**
   * Tests valueCallback returns zero when hidden field is empty.
   */
  public function testValueCallbackReturnsZeroWhenEmpty(): void {
    $element = ['#default_value' => 0];
    $input = ['autocomplete_display' => '', 'value' => ''];
    $form_state = $this->createMock(FormStateInterface::class);

    $result = UserReferenceAutocomplete::valueCallback($element, $input, $form_state);

    $this->assertSame(0, $result['value']);
  }

  /**
   * Tests valueCallback returns zero when hidden field is '0'.
   */
  public function testValueCallbackReturnsZeroFromZeroString(): void {
    $element = ['#default_value' => 0];
    $input = ['autocomplete_display' => '', 'value' => '0'];
    $form_state = $this->createMock(FormStateInterface::class);

    $result = UserReferenceAutocomplete::valueCallback($element, $input, $form_state);

    $this->assertSame(0, $result['value']);
  }

  /**
   * Tests valueCallback returns zero when default_value is not set.
   */
  public function testValueCallbackReturnsZeroWhenNoDefault(): void {
    $element = [];
    $input = FALSE;
    $form_state = $this->createMock(FormStateInterface::class);

    $result = UserReferenceAutocomplete::valueCallback($element, $input, $form_state);

    $this->assertSame(0, $result['value']);
    $this->assertSame('', $result['autocomplete_display']);
  }

  /**
   * Tests getInfo returns expected defaults.
   */
  public function testGetInfoDefaults(): void {
    $element = new UserReferenceAutocomplete([], 'user_reference_autocomplete', []);
    $info = $element->getInfo();

    $this->assertTrue($info['#input']);
    $this->assertTrue($info['#tree']);
    $this->assertSame(0, $info['#default_value']);
    $this->assertSame('user', $info['#target_type']);
    $this->assertSame('default', $info['#selection_handler']);
    $this->assertSame([], $info['#selection_settings']);
    $this->assertNull($info['#autocomplete_route_name']);
    $this->assertSame([[UserReferenceAutocomplete::class, 'buildElement']], $info['#process']);
    // No #element_validate — normalization is handled in copyFormValuesToEntity
    // due to EntityForm calling buildEntity() before element validators run.
    $this->assertArrayNotHasKey('#element_validate', $info);
    $this->assertSame(['library' => ['ai_claude_agent_sdk/user-reference-autocomplete']], $info['#attached']);
  }

  /**
   * Sets up a mock Drupal container with a user storage mock.
   */
  private function setupDrupalContainer(?UserInterface $user = NULL): void {
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->willReturn($user);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests buildElement creates autocomplete_display and value sub-elements.
   */
  public function testBuildElementCreatesSubElements(): void {
    $mockUser = $this->createMock(UserInterface::class);
    $this->setupDrupalContainer($mockUser);

    $element = [
      '#value' => ['autocomplete_display' => '', 'value' => 42],
      '#default_value' => 42,
      '#target_type' => 'user',
      '#selection_handler' => 'executor_user_selection',
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#autocomplete_route_name' => 'ai_claude_agent_sdk.executor_autocomplete',
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = UserReferenceAutocomplete::buildElement($element, $form_state, $complete_form);

    // Autocomplete display sub-element.
    $this->assertSame('entity_autocomplete', $result['autocomplete_display']['#type']);
    $this->assertSame('user', $result['autocomplete_display']['#target_type']);
    $this->assertSame('executor_user_selection', $result['autocomplete_display']['#selection_handler']);
    $this->assertSame(['include_anonymous' => FALSE], $result['autocomplete_display']['#selection_settings']);
    $this->assertSame($mockUser, $result['autocomplete_display']['#default_value']);
    $this->assertFalse($result['autocomplete_display']['#validate_reference']);
    $this->assertTrue($result['autocomplete_display']['#attributes']['data-user-ref-display']);
    $this->assertSame('ai_claude_agent_sdk.executor_autocomplete', $result['autocomplete_display']['#autocomplete_route_name']);

    // Hidden value sub-element.
    $this->assertSame('hidden', $result['value']['#type']);
    $this->assertSame(42, $result['value']['#default_value']);
    $this->assertTrue($result['value']['#attributes']['data-user-ref-value']);
  }

  /**
   * Tests buildElement sets NULL default when UID is 0.
   */
  public function testBuildElementNullUserWhenUidZero(): void {
    $this->setupDrupalContainer(NULL);

    $element = [
      '#value' => ['autocomplete_display' => '', 'value' => 0],
      '#default_value' => 0,
      '#target_type' => 'user',
      '#selection_handler' => 'default',
      '#selection_settings' => [],
      '#autocomplete_route_name' => NULL,
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = UserReferenceAutocomplete::buildElement($element, $form_state, $complete_form);

    $this->assertNull($result['autocomplete_display']['#default_value']);
    $this->assertSame(0, $result['value']['#default_value']);
  }

  /**
   * Tests buildElement transfers AJAX from parent to autocomplete display.
   */
  public function testBuildElementTransfersAjax(): void {
    $this->setupDrupalContainer(NULL);

    $ajax = [
      'callback' => '::previewCallback',
      'wrapper' => 'preview-wrapper',
      'event' => 'change',
    ];
    $element = [
      '#value' => ['autocomplete_display' => '', 'value' => 0],
      '#default_value' => 0,
      '#target_type' => 'user',
      '#selection_handler' => 'default',
      '#selection_settings' => [],
      '#autocomplete_route_name' => NULL,
      '#ajax' => $ajax,
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = UserReferenceAutocomplete::buildElement($element, $form_state, $complete_form);

    // AJAX moved to autocomplete_display with autocompleteclose prepended.
    $expectedAjax = $ajax;
    $expectedAjax['event'] = 'autocompleteclose change';
    $this->assertSame($expectedAjax, $result['autocomplete_display']['#ajax']);
    // AJAX cleared from parent.
    $this->assertArrayNotHasKey('#ajax', $result);
    // Hidden field has no AJAX.
    $this->assertArrayNotHasKey('#ajax', $result['value']);
  }

}
