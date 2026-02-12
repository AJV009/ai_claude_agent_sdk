<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_console\Plugin\AiAgent;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Types\AssistantMessage;
use Claude\AgentSdk\Types\ResultMessage;
use Claude\AgentSdk\Types\TextBlock;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkAuthEnvResolver;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agents\Attribute\AiAgent;
use Drupal\ai_agents\PluginBase\AiAgentBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Throwable;

/**
 * Claude Agent SDK console agent.
 */
#[AiAgent(
  id: 'claude_agent_sdk_console',
  label: new TranslatableMarkup('Claude Agent SDK Console'),
  module_dependencies: ['ai_claude_agent_sdk', 'ai_console'],
)]
final class ClaudeAgentSdkConsoleAgent extends AiAgentBase {

  use DependencySerializationTrait;

  private ?ClaudeAgentSdkProcessLimiter $processLimiter = null;

  private ?ClaudeAgentSdkAuthEnvResolver $authEnvResolver = null;

  /**
   * {@inheritDoc}
   */
  public function agentsNames() {
    return [
      'Claude Agent SDK Console',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function agentsCapabilities() {
    return [
      'claude_agent_sdk_console' => [
        'name' => 'Claude Agent SDK Console',
        'description' => 'Uses the Claude Agent SDK (Claude Code CLI) to answer console prompts via a persistent client session.',
        'inputs' => [
          'free_text' => [
            'name' => 'Prompt',
            'type' => 'string',
            'description' => 'A prompt to send to Claude Code.',
            'default_value' => '',
          ],
        ],
        'outputs' => [
          'answers' => [
            'description' => 'The response returned by Claude Code.',
            'type' => 'string',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function determineSolvability() {
    $this->agentHelper->setupRunner($this);
    return AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION;
  }

  /**
   * {@inheritDoc}
   */
  public function answerQuestion() {
    return $this->sendPrompt();
  }

  /**
   * {@inheritDoc}
   */
  public function solve() {
    return $this->sendPrompt();
  }

  /**
   * Send a prompt via the Claude Agent SDK client (non-query helper).
   */
  private function sendPrompt(): string {
    $this->agentHelper->setupRunner($this);

    $task = $this->getTask();
    $prompt = $task ? trim($task->getDescription()) : '';

    if ($prompt === '') {
      return (string) $this->t('No prompt provided.');
    }

    $this->processLimiter ??= \Drupal::service('ai_claude_agent_sdk.process_limiter');
    $this->authEnvResolver ??= \Drupal::service('ai_claude_agent_sdk.auth_env_resolver');
    if ($this->processLimiter && !$this->processLimiter->canStart()) {
      $status = $this->processLimiter->getStatus();
      return (string) $this->t('Claude CLI limit reached (@running running, limit @limit). Try again later.', [
        '@running' => $status['running'],
        '@limit' => $status['limit'],
      ]);
    }

    $config = $this->config->get('ai_claude_agent_sdk.settings');
    $cliPath = (string) ($config->get('cli_path') ?? '');
    $model = (string) ($config->get('default_model') ?? '');
    $cwd = (string) ($config->get('working_directory') ?? '');
    $systemPrompt = '';

    $agentEntity = $this->entityTypeManager->getStorage('ai_agent')->load($this->getId());
    if ($agentEntity) {
      $systemPrompt = (string) ($agentEntity->get('system_prompt') ?? '');
    }

    $options = new ClaudeAgentOptions(
      cliPath: $cliPath !== '' ? $cliPath : null,
      cwd: $cwd !== '' ? $cwd : null,
      model: $model !== '' ? $model : null,
      systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
      env: $this->authEnvResolver?->buildEnv([]) ?? [],
      hooks: [],
    );

    $client = new Client($options);

    try {
      // Connect in streaming mode, but do not send any initial messages.
      $client->connect(new \ArrayIterator([]));

      $client->query($prompt, $this->getRunnerId());

      $responseText = '';
      foreach ($client->receiveResponse() as $message) {
        if ($message instanceof AssistantMessage) {
          $responseText .= $this->renderAssistantMessage($message);
        }
        if ($message instanceof ResultMessage && $responseText === '') {
          if (is_string($message->result)) {
            $responseText = $message->result;
          }
        }
      }

      $client->closeInput();
      $client->close();

      $responseText = trim($responseText);
      if ($responseText === '') {
        return (string) $this->t('No response received from Claude Code.');
      }

      return $responseText;
    }
    catch (Throwable $e) {
      try {
        $client->close();
      }
      catch (Throwable) {
        // Ignore close failures.
      }

      return (string) $this->t('Claude SDK error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Render assistant message content to plain text.
   */
  private function renderAssistantMessage(AssistantMessage $message): string {
    $text = '';
    foreach ($message->content as $block) {
      if ($block instanceof TextBlock) {
        $text .= $block->text;
      }
    }

    return $text;
  }

}
