<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding/editing Agent Skill config entities.
 */
class AgentSkillForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface $skill */
    $skill = $this->entity;

    // Basic settings.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $skill->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $skill->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_claude_agent_sdk\Entity\AgentSkill::load',
      ],
      '#disabled' => !$skill->isNew(),
      '#description' => $this->t('Lowercase letters, numbers, and underscores only. This becomes the skill directory name.'),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Describes what the skill does and when to use it.'),
      '#default_value' => $skill->getDescription(),
      '#required' => TRUE,
      '#maxlength' => 1024,
      '#rows' => 3,
    ];

    // Instructions.
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Instructions'),
      '#open' => TRUE,
    ];

    $form['instructions']['skill_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Skill body'),
      '#description' => $this->t('Markdown instructions that Claude follows when this skill is invoked.'),
      '#default_value' => $skill->getSkillBody(),
      '#rows' => 15,
    ];

    // Behavior.
    $form['behavior'] = [
      '#type' => 'details',
      '#title' => $this->t('Behavior'),
    ];

    $form['behavior']['disable_model_invocation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable model invocation'),
      '#description' => $this->t('Only allow manual /invocation, prevents Claude from auto-loading this skill.'),
      '#default_value' => $skill->getDisableModelInvocation(),
    ];

    $form['behavior']['user_invocable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('User invocable'),
      '#description' => $this->t('Show this skill in the / menu.'),
      '#default_value' => $skill->getUserInvocable(),
    ];

    $form['behavior']['argument_hint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Argument hint'),
      '#description' => $this->t('Hint shown after the skill name, e.g., [issue-number].'),
      '#default_value' => $skill->getArgumentHint(),
      '#placeholder' => '[filename]',
    ];

    $form['behavior']['allowed_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed tools'),
      '#description' => $this->t('One tool name per line. Leave empty to allow all.'),
      '#default_value' => implode("\n", $skill->getAllowedTools()),
      '#rows' => 4,
    ];

    $form['behavior']['context_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Context mode'),
      '#options' => [
        'inline' => $this->t('Inline'),
        'fork' => $this->t('Fork'),
      ],
      '#default_value' => $skill->getContextMode(),
    ];

    $form['behavior']['agent_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subagent type'),
      '#description' => $this->t('Only applicable when context mode is "fork".'),
      '#default_value' => $skill->getAgentType(),
      '#states' => [
        'visible' => [
          ':input[name="context_mode"]' => ['value' => 'fork'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('allowed_tools');
    $form_state->setValue('allowed_tools', $this->textareaToArray($value));

    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface $skill */
    $skill = $this->entity;
    $status = $skill->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Agent skill %label created.', [
        '%label' => $skill->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Agent skill %label updated.', [
        '%label' => $skill->label(),
      ]));
    }

    $form_state->setRedirectUrl($skill->toUrl('collection'));
    return $status;
  }

  /**
   * Converts a textarea value to an array of non-empty trimmed lines.
   */
  protected function textareaToArray(string|array|null $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    if (is_array($value)) {
      return $value;
    }
    return array_values(array_filter(
      array_map('trim', explode("\n", $value)),
      fn(string $line): bool => $line !== '',
    ));
  }

}
