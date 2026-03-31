<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_claude_agent_sdk_runner\Value\ExecutionResult;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Maps completed execution results to AI Agents ecosystem objects.
 *
 * Converts webhook data stored in ExecutionStore into:
 * - ChatOutput (response text for the assistant API)
 * - AI Agents events (for debugger, status poller, subscribers)
 */
class ResultMapper {

  public function __construct(
    protected readonly ExecutionStore $executionStore,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly AiAgentManager $pluginManager,
  ) {}

  /**
   * Maps a completed execution to ChatOutput.
   */
  public function mapToChatOutput(ExecutionResult $result): ChatOutput {
    if ($result->isError()) {
      $text = 'Claude Code execution failed: '
        . ($result->getErrorMessage() ?: $result->getErrorType());
    }
    else {
      $text = $result->getResponse() ?: 'Execution completed with no response.';
    }

    $message = new ChatMessage('assistant', $text);
    return new ChatOutput($message, [$text], [
      'query_id' => $result->getQueryId(),
      'session_id' => $result->getSessionId(),
      'total_turns' => $result->getTotalTurns(),
      'runner' => 'claude_code',
    ]);
  }

  /**
   * Dispatches AI Agents ecosystem events from stored webhook data.
   *
   * Creates a ConfigAiAgentInterface plugin instance via the plugin manager
   * purely for event dispatching context.
   */
  public function dispatchAgentEvents(
    ExecutionResult $result,
    string $agentPluginId,
    array $chatHistory,
    ChatOutput $chatOutput,
  ): void {
    try {
      $plugin = $this->pluginManager->createInstance($agentPluginId);
    }
    catch (\Exception) {
      // Cannot create plugin instance -- skip event dispatch.
      return;
    }

    if (!$plugin instanceof ConfigAiAgentInterface) {
      return;
    }

    $runnerId = 'claude_code_runner:' . $result->getProfileId();

    // Dispatch started event.
    $this->eventDispatcher->dispatch(
      new AgentStartedExecutionEvent(
        $plugin,
        $agentPluginId,
        $chatHistory,
        $runnerId,
        0,
      ),
      AgentStartedExecutionEvent::EVENT_NAME,
    );

    // Dispatch finished event.
    $systemPrompt = '';
    if (method_exists($plugin, 'getSystemPrompt')) {
      $systemPrompt = (string) $plugin->getSystemPrompt();
    }

    $this->eventDispatcher->dispatch(
      new AgentFinishedExecutionEvent(
        $plugin,
        $systemPrompt,
        $agentPluginId,
        '',
        $chatHistory,
        $chatOutput,
        $result->getTotalTurns(),
        $runnerId,
      ),
      AgentFinishedExecutionEvent::EVENT_NAME,
    );
  }

  /**
   * Dispatches AI Agents events from a synchronous ChatOutput response.
   *
   * Used by the synchronous (interactive) execution path where we have
   * a ChatOutput but not an ExecutionResult.
   */
  public function dispatchAgentEventsFromChatOutput(
    ChatOutput $chatOutput,
    string $agentPluginId,
    array $chatHistory,
    string $profileId,
  ): void {
    try {
      $plugin = $this->pluginManager->createInstance($agentPluginId);
    }
    catch (\Exception) {
      return;
    }

    if (!$plugin instanceof ConfigAiAgentInterface) {
      return;
    }

    $runnerId = 'claude_code_runner:' . $profileId;

    $this->eventDispatcher->dispatch(
      new AgentStartedExecutionEvent(
        $plugin,
        $agentPluginId,
        $chatHistory,
        $runnerId,
        0,
      ),
      AgentStartedExecutionEvent::EVENT_NAME,
    );

    $systemPrompt = '';
    if (method_exists($plugin, 'getSystemPrompt')) {
      $systemPrompt = (string) $plugin->getSystemPrompt();
    }

    $this->eventDispatcher->dispatch(
      new AgentFinishedExecutionEvent(
        $plugin,
        $systemPrompt,
        $agentPluginId,
        '',
        $chatHistory,
        $chatOutput,
        0,
        $runnerId,
      ),
      AgentFinishedExecutionEvent::EVENT_NAME,
    );
  }

}
