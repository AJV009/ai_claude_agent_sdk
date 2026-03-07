<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_console\Plugin\AiAgent;

use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
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

  private ?ClaudeAgentSdkProcessLimiter $processLimiter = NULL;

  private ?ClaudeBridgeService $bridge = NULL;

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
   * Send a prompt via the sidecar bridge service.
   */
  private function sendPrompt(): string {
    $task = $this->getTask();
    $prompt = $task ? trim($task->getDescription()) : '';

    if ($prompt === '') {
      return (string) $this->t('No prompt provided.');
    }

    $this->processLimiter ??= \Drupal::service('ai_claude_agent_sdk.process_limiter');
    $this->bridge ??= \Drupal::service('ai_claude_agent_sdk.bridge');

    if ($this->processLimiter && !$this->processLimiter->canStart()) {
      $status = $this->processLimiter->getStatus();
      return (string) $this->t('Claude CLI limit reached (@running running, limit @limit). Try again later.', [
        '@running' => $status['running'],
        '@limit' => $status['limit'],
      ]);
    }

    // Load the default agent profile.
    $profileId = 'default';
    $profile = $this->entityTypeManager->getStorage('agent_profile')->load($profileId);
    if (!$profile) {
      return (string) $this->t('Agent profile %id not found.', ['%id' => $profileId]);
    }

    try {
      $responseText = $this->bridge->collectResponse($profile->toSidecarFormat(), $prompt);

      if ($responseText === '') {
        return (string) $this->t('No response received from Claude Code.');
      }

      return $responseText;
    }
    catch (Throwable $e) {
      return (string) $this->t('Claude SDK error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
