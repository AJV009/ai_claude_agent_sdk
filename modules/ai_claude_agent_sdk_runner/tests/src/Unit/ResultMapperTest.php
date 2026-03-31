<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\ai_claude_agent_sdk_runner\Service\ResultMapper;
use Drupal\ai_claude_agent_sdk_runner\Value\ExecutionResult;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the ResultMapper service.
 *
 * @group ai_claude_agent_sdk_runner
 */
class ResultMapperTest extends TestCase {

  /**
   * Tests mapToChatOutput creates valid ChatOutput from result.
   */
  public function testMapToChatOutputSuccess(): void {
    $executionStore = $this->createMock(ExecutionStore::class);
    $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $pluginManager = $this->createMock(AiAgentManager::class);

    $mapper = new ResultMapper($executionStore, $eventDispatcher, $pluginManager);

    $result = new ExecutionResult(
      queryId: 'q1',
      agentId: 'content_creator',
      profileId: 'developer',
      jobId: 'job-1',
      status: 'completed',
      data: ['response' => 'I created the article for you.'],
    );

    $chatOutput = $mapper->mapToChatOutput($result);

    $this->assertSame('I created the article for you.', $chatOutput->getNormalized()->getText());
  }

  /**
   * Tests mapToChatOutput handles error result.
   */
  public function testMapToChatOutputError(): void {
    $executionStore = $this->createMock(ExecutionStore::class);
    $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $pluginManager = $this->createMock(AiAgentManager::class);

    $mapper = new ResultMapper($executionStore, $eventDispatcher, $pluginManager);

    $result = new ExecutionResult(
      queryId: 'q2',
      agentId: 'content_creator',
      profileId: 'developer',
      jobId: 'job-2',
      status: 'error',
      data: ['error_type' => 'runtime', 'error_message' => 'Model overloaded'],
    );

    $chatOutput = $mapper->mapToChatOutput($result);

    $this->assertStringContainsString('failed', strtolower($chatOutput->getNormalized()->getText()));
  }

  /**
   * Tests mapToChatOutput with no response returns default text.
   */
  public function testMapToChatOutputNoResponse(): void {
    $executionStore = $this->createMock(ExecutionStore::class);
    $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $pluginManager = $this->createMock(AiAgentManager::class);

    $mapper = new ResultMapper($executionStore, $eventDispatcher, $pluginManager);

    $result = new ExecutionResult(
      queryId: 'q3',
      agentId: 'test',
      profileId: 'dev',
      jobId: 'job-3',
      status: 'completed',
      data: [],
    );

    $chatOutput = $mapper->mapToChatOutput($result);

    $this->assertStringContainsString('no response', strtolower($chatOutput->getNormalized()->getText()));
  }

}
