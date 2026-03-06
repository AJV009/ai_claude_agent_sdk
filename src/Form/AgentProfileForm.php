<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding/editing Agent Profile config entities.
 */
class AgentProfileForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile */
    $profile = $this->entity;

    // Basic settings.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $profile->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $profile->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_claude_agent_sdk\Entity\AgentProfile::load',
      ],
      '#disabled' => !$profile->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $profile->getDescription(),
    ];

    // Agent behavior.
    $form['behavior'] = [
      '#type' => 'details',
      '#title' => $this->t('Agent behavior'),
      '#open' => TRUE,
    ];

    $form['behavior']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $profile->getSystemPrompt(),
      '#rows' => 6,
    ];

    $form['behavior']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'opus' => $this->t('Opus'),
        'sonnet' => $this->t('Sonnet'),
        'haiku' => $this->t('Haiku'),
      ],
      '#default_value' => $profile->getModel(),
      '#required' => TRUE,
    ];

    $form['behavior']['permission_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission mode'),
      '#options' => [
        'default' => $this->t('Default'),
        'plan' => $this->t('Plan'),
        'bypassPermissions' => $this->t('Bypass permissions'),
      ],
      '#default_value' => $profile->getPermissionMode(),
      '#required' => TRUE,
    ];

    $form['behavior']['max_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Max turns'),
      '#default_value' => $profile->getMaxTurns(),
      '#min' => 1,
      '#step' => 1,
    ];

    // Tools.
    $form['tools'] = [
      '#type' => 'details',
      '#title' => $this->t('Tools'),
    ];

    $form['tools']['allowed_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed tools'),
      '#description' => $this->t('One tool name per line. Leave empty to allow all.'),
      '#default_value' => implode("\n", $profile->getAllowedTools()),
      '#rows' => 4,
    ];

    $form['tools']['denied_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Denied tools'),
      '#description' => $this->t('One tool name per line.'),
      '#default_value' => implode("\n", $profile->getDeniedTools()),
      '#rows' => 4,
    ];

    // MCP Servers.
    $form['mcp'] = [
      '#type' => 'details',
      '#title' => $this->t('MCP Servers'),
    ];

    $mcp_lines = [];
    foreach ($profile->getMcpServers() as $server) {
      $mcp_lines[] = $server['name'] . '|' . $server['transport'] . '|' . $server['url'];
    }

    $form['mcp']['mcp_servers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP server connections'),
      '#description' => $this->t('One server per line in format: <code>name|transport|url</code> (e.g. <code>drupal|http|/_mcp</code>).'),
      '#default_value' => implode("\n", $mcp_lines),
      '#rows' => 4,
    ];

    // Environment.
    $form['environment'] = [
      '#type' => 'details',
      '#title' => $this->t('Environment'),
    ];

    $form['environment']['working_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Working directory'),
      '#default_value' => $profile->getWorkingDirectory(),
    ];

    $form['environment']['sandbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable sandbox'),
      '#default_value' => $profile->getSandbox(),
    ];

    $form['environment']['allowed_directories'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed directories'),
      '#description' => $this->t('One directory path per line.'),
      '#default_value' => implode("\n", $profile->getAllowedDirectories()),
      '#rows' => 3,
    ];

    // Execution identity.
    $form['execution'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution identity'),
    ];

    $form['execution']['executor_uid'] = [
      '#type' => 'number',
      '#title' => $this->t('Executor UID'),
      '#description' => $this->t('The Drupal user ID to execute as. 0 = current user.'),
      '#default_value' => $profile->getExecutorUid(),
      '#min' => 0,
      '#step' => 1,
    ];

    $form['execution']['execution_modality'] = [
      '#type' => 'select',
      '#title' => $this->t('Execution modality'),
      '#options' => [
        'interactive' => $this->t('Interactive'),
        'background' => $this->t('Background'),
        'outside_in' => $this->t('Outside-in'),
      ],
      '#default_value' => $profile->getExecutionModality(),
    ];

    // Advanced.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];

    $form['advanced']['extra_args'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra CLI arguments'),
      '#description' => $this->t('One argument per line.'),
      '#default_value' => implode("\n", $profile->getExtraArgs()),
      '#rows' => 3,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // Parse textarea values into arrays before they are copied to the entity,
    // since the entity properties are typed as arrays.
    $textarea_fields = ['allowed_tools', 'denied_tools', 'allowed_directories', 'extra_args'];
    foreach ($textarea_fields as $field) {
      $form_state->setValue($field, $this->textareaToArray($form_state->getValue($field)));
    }

    // Parse MCP servers (skip if already parsed to array of mappings).
    $mcp_raw = $form_state->getValue('mcp_servers');
    if (is_string($mcp_raw)) {
      $mcp_servers = [];
      $lines = $this->textareaToArray($mcp_raw);
      foreach ($lines as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) === 3) {
          $mcp_servers[] = [
            'name' => trim($parts[0]),
            'transport' => trim($parts[1]),
            'url' => trim($parts[2]),
          ];
        }
      }
      $form_state->setValue('mcp_servers', $mcp_servers);
    }

    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile */
    $profile = $this->entity;

    $status = $profile->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Agent profile %label created.', [
        '%label' => $profile->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Agent profile %label updated.', [
        '%label' => $profile->label(),
      ]));
    }

    $form_state->setRedirectUrl($profile->toUrl('collection'));
    return $status;
  }

  /**
   * Converts a textarea value to an array of non-empty trimmed lines.
   *
   * @param string|null $value
   *   The textarea value.
   *
   * @return string[]
   *   Array of non-empty lines.
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
