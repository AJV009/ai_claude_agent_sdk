<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class ClaudeAgentSdkSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'ai_claude_agent_sdk_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['ai_claude_agent_sdk.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_claude_agent_sdk.settings');

    $form['description'] = [
      '#type' => 'item',
      '#markup' => $this->t('Configure defaults for the Claude Agent SDK integration. These are used as fallbacks when the debug UI or future integrations do not provide explicit values.'),
    ];

    $form['cli_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Claude CLI path'),
      '#description' => $this->t('Optional path to the Claude CLI binary. Leave empty to use the system default (claude).'),
      '#default_value' => $config->get('cli_path') ?? '',
    ];

    $form['default_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default model'),
      '#description' => $this->t('Optional default model name to use when none is provided.'),
      '#default_value' => $config->get('default_model') ?? '',
    ];

    $form['working_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Working directory'),
      '#description' => $this->t('Optional working directory for Claude Code operations. Leave empty to use the site default.'),
      '#default_value' => $config->get('working_directory') ?? '',
    ];

    $form['max_concurrent_cli'] = [
      '#type' => 'number',
      '#title' => $this->t('Max concurrent Claude CLI processes'),
      '#description' => $this->t('Global safety limit for running Claude CLI processes. Set to 0 for unlimited. Default: 8.'),
      '#default_value' => $config->get('max_concurrent_cli') ?? 8,
      '#min' => 0,
      '#step' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_claude_agent_sdk.settings')
      ->set('cli_path', (string) $form_state->getValue('cli_path'))
      ->set('default_model', (string) $form_state->getValue('default_model'))
      ->set('working_directory', (string) $form_state->getValue('working_directory'))
      ->set('max_concurrent_cli', (int) $form_state->getValue('max_concurrent_cli'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
