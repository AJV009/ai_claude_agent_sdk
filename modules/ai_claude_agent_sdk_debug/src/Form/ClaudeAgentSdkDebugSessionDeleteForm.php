<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Form;

use Drupal\ai_claude_agent_sdk_debug\Session\SessionFileStore;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkDebugSessionDeleteForm extends ConfirmFormBase {

  private string $sessionId = '';

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly SessionFileStore $sessionFileStore,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk_debug.session_file_store'),
    );
  }

  public function getFormId(): string {
    return 'ai_claude_agent_sdk_debug_session_delete_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Delete session @session?', ['@session' => $this->sessionId]);
  }

  public function getDescription(): string {
    return (string) $this->t('This removes JSONL session files from Claude Code storage and removes the session metadata from Drupal state.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('ai_claude_agent_sdk_debug.session_view', ['session_id' => $this->sessionId]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Delete session');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $session_id = NULL): array {
    $this->sessionId = (string) ($session_id ?? '');
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->sessionFileStore->deleteSessionFiles($this->sessionId);
    $this->sessionTracker->remove($this->sessionId);

    $this->messenger()->addStatus($this->t('Deleted session @session. Removed @count JSONL file(s).', [
      '@session' => $this->sessionId,
      '@count' => (string) ($result['deleted'] ?? 0),
    ]));

    $form_state->setRedirect('ai_claude_agent_sdk_debug.processes');
  }

}
