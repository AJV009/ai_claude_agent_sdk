<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_audit\Form;

use Drupal\ai_claude_agent_sdk_audit\Service\AuditLogger;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form for clearing all audit log entries.
 */
class AuditLogClearForm extends ConfirmFormBase {

  public function __construct(
    private readonly AuditLogger $auditLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk_audit.audit_logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_claude_audit_log_clear_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete all audit log entries?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clear log');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('ai_claude_agent_sdk_audit.overview');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->auditLogger->clearAll();
    $this->messenger()->addStatus($this->t('Audit log cleared.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
