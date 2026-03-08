<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to abort a running background query.
 */
class QueryAbortConfirmForm extends ConfirmFormBase {

  protected string $queryId = '';

  public function __construct(
    protected readonly ClaudeBridgeService $bridge,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.bridge'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'query_abort_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Interrupt running query %id?', [
      '%id' => substr($this->queryId, 0, 8),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('ai_claude_agent_sdk.sessions');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Interrupt');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $query_id = NULL): array {
    $this->queryId = $query_id ?? '';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->queryId) {
      $result = $this->bridge->abortQuery($this->queryId);
      if ($result) {
        $this->messenger()->addStatus($this->t('Query interrupted successfully.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Query could not be interrupted (may have already completed).'));
      }
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
