<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillFileSync;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for syncing filesystem skills into config entities.
 */
class AgentSkillSyncForm extends ConfirmFormBase {

  public function __construct(
    protected readonly AgentSkillFileSync $skillFileSync,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.skill_file_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agent_skill_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Sync skills from filesystem?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('entity.agent_skill.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $unmanaged = $this->skillFileSync->getUnmanagedSkills();
    if (empty($unmanaged)) {
      return $this->t('All filesystem skills are already managed.');
    }
    $names = implode(', ', array_keys($unmanaged));
    return $this->t('The following @count skill(s) will be imported as managed config entities: @names', [
      '@count' => count($unmanaged),
      '@names' => $names,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $unmanaged = $this->skillFileSync->getUnmanagedSkills();

    if (empty($unmanaged)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('All filesystem skills are already managed. Nothing to sync.') . '</p>',
      ];
      $form['actions'] = [
        '#type' => 'actions',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to skills'),
          '#url' => $this->getCancelUrl(),
          '#attributes' => ['class' => ['button']],
        ],
      ];
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Sync skills');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->skillFileSync->syncAllFromFilesystem();

    if (!empty($result['created'])) {
      $this->messenger()->addStatus($this->t('Synced @count skill(s): @names', [
        '@count' => count($result['created']),
        '@names' => implode(', ', $result['created']),
      ]));
    }

    if (!empty($result['skipped'])) {
      $this->messenger()->addWarning($this->t('Skipped @count skill(s) (already exist): @names', [
        '@count' => count($result['skipped']),
        '@names' => implode(', ', $result['skipped']),
      ]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
