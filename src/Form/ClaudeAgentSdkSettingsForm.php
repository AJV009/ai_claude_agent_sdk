<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkSettingsForm extends ConfigFormBase {

  protected KeyRepositoryInterface $keyRepository;

  public function __construct(KeyRepositoryInterface $keyRepository) {
    $this->keyRepository = $keyRepository;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('key.repository'),
    );
  }

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

    $form['authentication'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#open' => TRUE,
    ];

    $form['authentication']['api_key_source'] = [
      '#type' => 'select',
      '#title' => $this->t('API key source'),
      '#description' => $this->t('Choose where Claude Code should read ANTHROPIC_API_KEY from. DDEV setup defaults to environment variable mode.'),
      '#options' => [
        'environment' => $this->t('Environment variable'),
        'key' => $this->t('Key module'),
      ],
      '#default_value' => $config->get('api_key_source') ?? 'environment',
      '#required' => TRUE,
    ];

    $form['authentication']['api_key_env_var'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environment variable name'),
      '#description' => $this->t('Defaults to ANTHROPIC_API_KEY.'),
      '#default_value' => $config->get('api_key_env_var') ?? 'ANTHROPIC_API_KEY',
      '#states' => [
        'visible' => [
          ':input[name="api_key_source"]' => ['value' => 'environment'],
        ],
      ],
    ];

    $form['authentication']['api_key_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Key module API key'),
      '#description' => $this->t('Select a Key entity containing the Anthropic API key value.'),
      '#default_value' => $config->get('api_key_key') ?? '',
      '#empty_option' => $this->t('- Select a key -'),
      '#states' => [
        'visible' => [
          ':input[name="api_key_source"]' => ['value' => 'key'],
        ],
      ],
    ];

    $form['sidecar'] = [
      '#type' => 'details',
      '#title' => $this->t('Sidecar'),
      '#open' => TRUE,
    ];

    $form['sidecar']['sidecar_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Sidecar URL'),
      '#description' => $this->t('Base URL of the Node.js PTY sidecar. DDEV default: <code>http://localhost:3000</code>. The WebSocket endpoint is at <code>/ws</code> relative to this.'),
      '#default_value' => $config->get('sidecar_url') ?: 'http://localhost:3000',
    ];

    $form['sidecar']['sidecar_ws_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sidecar WebSocket URL (browser)'),
      '#description' => $this->t('WebSocket URL for the browser to reach the sidecar. Leave empty to auto-detect (works for DDEV). Example: <code>wss://mysite.ddev.site:3100/ws</code>'),
      '#default_value' => $config->get('sidecar_ws_url') ?: '',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $source = (string) $form_state->getValue('api_key_source');
    if ($source === 'environment') {
      $envVar = trim((string) $form_state->getValue('api_key_env_var'));
      if ($envVar === '') {
        $form_state->setErrorByName('api_key_env_var', $this->t('Environment variable name is required.'));
      }
    }

    if ($source === 'key') {
      $keyId = trim((string) $form_state->getValue('api_key_key'));
      if ($keyId === '') {
        $form_state->setErrorByName('api_key_key', $this->t('Please select a key.'));
      }
      else {
        $key = $this->keyRepository->getKey($keyId);
        if (!$key || !is_string($key->getKeyValue()) || $key->getKeyValue() === '') {
          $form_state->setErrorByName('api_key_key', $this->t('The selected key has no value.'));
        }
      }
    }

    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_claude_agent_sdk.settings')
      ->set('cli_path', (string) $form_state->getValue('cli_path'))
      ->set('default_model', (string) $form_state->getValue('default_model'))
      ->set('working_directory', (string) $form_state->getValue('working_directory'))
      ->set('max_concurrent_cli', (int) $form_state->getValue('max_concurrent_cli'))
      ->set('api_key_source', (string) $form_state->getValue('api_key_source'))
      ->set('api_key_key', (string) $form_state->getValue('api_key_key'))
      ->set('api_key_env_var', (string) $form_state->getValue('api_key_env_var'))
      ->set('sidecar_url', (string) $form_state->getValue('sidecar_url'))
      ->set('sidecar_ws_url', (string) $form_state->getValue('sidecar_ws_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
