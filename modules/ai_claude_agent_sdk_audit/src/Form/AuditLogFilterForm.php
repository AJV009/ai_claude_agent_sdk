<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_audit\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filter form for the audit log admin page.
 *
 * Stores selected filters in the session, matching the pattern used by
 * Drupal core's DbLogFilterForm.
 */
class AuditLogFilterForm extends FormBase {

  private const SESSION_KEY = 'ai_claude_audit_log_filter';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_claude_audit_log_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $session = $this->getRequest()->getSession();
    $filters = $session->get(self::SESSION_KEY, []);

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter log messages'),
      '#open' => !empty(array_filter($filters)),
    ];

    // Profile filter.
    $profiles = $this->entityTypeManager->getStorage('agent_profile')->loadMultiple();
    $profile_options = ['' => $this->t('- Any -')];
    foreach ($profiles as $profile) {
      $profile_options[$profile->id()] = $profile->label();
    }
    $form['filters']['profile_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Profile'),
      '#options' => $profile_options,
      '#default_value' => $filters['profile_id'] ?? '',
    ];

    // Decision filter.
    $form['filters']['decision'] = [
      '#type' => 'select',
      '#title' => $this->t('Decision'),
      '#options' => [
        '' => $this->t('- Any -'),
        'allow' => $this->t('Allow'),
        'deny' => $this->t('Deny'),
        'ask' => $this->t('Ask'),
      ],
      '#default_value' => $filters['decision'] ?? '',
    ];

    // Tier filter.
    $form['filters']['tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Tier'),
      '#options' => [
        '' => $this->t('- Any -'),
        'strict' => $this->t('Strict'),
        'standard' => $this->t('Standard'),
        'permissive' => $this->t('Permissive'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $filters['tier'] ?? '',
    ];

    // Tool name filter.
    $form['filters']['tool_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tool name'),
      '#size' => 30,
      '#default_value' => $filters['tool_name'] ?? '',
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];
    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    $form['filters']['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $session = $this->getRequest()->getSession();
    $session->set(self::SESSION_KEY, [
      'profile_id' => $form_state->getValue('profile_id'),
      'decision' => $form_state->getValue('decision'),
      'tier' => $form_state->getValue('tier'),
      'tool_name' => trim($form_state->getValue('tool_name') ?? ''),
    ]);
  }

  /**
   * Resets filters.
   */
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $session = $this->getRequest()->getSession();
    $session->remove(self::SESSION_KEY);
  }

  /**
   * Returns current filter values from the session.
   *
   * @return array
   *   Filter key-value pairs (empty strings removed).
   */
  public static function getSessionFilters(Request $request): array {
    $session = $request->getSession();
    $filters = $session->get(self::SESSION_KEY, []);
    return array_filter($filters, fn($v) => $v !== '' && $v !== NULL);
  }

}
