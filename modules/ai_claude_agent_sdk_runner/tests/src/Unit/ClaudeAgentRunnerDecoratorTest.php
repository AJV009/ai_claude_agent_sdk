<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Execution\ExecutionContext;
use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeServiceInterface;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeServiceInterface;
use Drupal\ai_claude_agent_sdk\Service\ExecutionPrincipalResolver;
use Drupal\ai_claude_agent_sdk_runner\Service\ClaudeAgentRunnerDecorator;
use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\ai_claude_agent_sdk_runner\Service\ResultMapper;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ClaudeAgentRunnerDecorator routing logic.
 *
 * @group ai_claude_agent_sdk_runner
 */
class ClaudeAgentRunnerDecoratorTest extends TestCase {

  private AgentRunner $innerRunner;
  private ClaudeBridgeServiceInterface $bridge;
  private EntityTypeManagerInterface $entityTypeManager;
  private AiAgentManager $pluginManager;
  private ExecutionStore $executionStore;
  private ResultMapper $resultMapper;
  private ExecutionEnvelopeServiceInterface $envelopeService;
  private ExecutionPrincipalResolver $principalResolver;
  private ConfigFactoryInterface $configFactory;
  private ImmutableConfig $config;
  private ClaudeAgentRunnerDecorator $decorator;

  protected function setUp(): void {
    parent::setUp();
    $this->innerRunner = $this->createMock(AgentRunner::class);
    $this->bridge = $this->createMock(ClaudeBridgeServiceInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->pluginManager = $this->createMock(AiAgentManager::class);
    $this->executionStore = $this->createMock(ExecutionStore::class);
    $this->resultMapper = $this->createMock(ResultMapper::class);
    $this->envelopeService = $this->createMock(ExecutionEnvelopeServiceInterface::class);
    $this->principalResolver = $this->createMock(ExecutionPrincipalResolver::class);

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->config->method('get')
      ->with('site_base_url')
      ->willReturn('http://mysite.test');

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('ai_claude_agent_sdk.settings')
      ->willReturn($this->config);

    $this->decorator = new ClaudeAgentRunnerDecorator(
      $this->innerRunner,
      $this->bridge,
      $this->entityTypeManager,
      $this->pluginManager,
      $this->executionStore,
      $this->resultMapper,
      $this->envelopeService,
      $this->principalResolver,
      $this->configFactory,
    );
  }

  /**
   * Test: Falls through when plugin is not a ConfigAiAgentInterface.
   */
  public function testFallthroughForNonConfigAgent(): void {
    $plugin = $this->createMock(AiAgentInterface::class);
    $this->pluginManager->method('createInstance')
      ->with('simple_agent')
      ->willReturn($plugin);

    $expectedOutput = new ChatOutput(new ChatMessage('assistant', 'inner response'), ['inner response'], []);
    $this->innerRunner->method('runAsAgent')
      ->willReturn($expectedOutput);

    $result = $this->decorator->runAsAgent('simple_agent', [], [], 'job-1');

    $this->assertSame($expectedOutput, $result);
  }

  /**
   * Test: Falls through when runner is not enabled on the agent.
   */
  public function testFallthroughWhenNotEnabled(): void {
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => FALSE, 'profile_id' => '']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('config_agent')
      ->willReturn($plugin);

    $expectedOutput = new ChatOutput(new ChatMessage('assistant', 'inner'), ['inner'], []);
    $this->innerRunner->method('runAsAgent')->willReturn($expectedOutput);

    $result = $this->decorator->runAsAgent('config_agent', [], [], 'job-2');

    $this->assertSame($expectedOutput, $result);
  }

  /**
   * Test: Async fire-and-forget returns marker HTML with query ID.
   */
  public function testRunAsAgentFiresAndForgetAndReturnsMarkerHtml(): void {
    // Set up Claude-enabled agent.
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => TRUE, 'profile_id' => 'developer']);
    $agentEntity->method('toArray')
      ->willReturn(['system_prompt' => '']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('content_agent')
      ->willReturn($plugin);

    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('toSidecarFormat')->willReturn(['model' => 'claude']);
    $profile->method('getSystemPrompt')->willReturn('You are helpful.');
    $profileStorage = $this->createMock(EntityStorageInterface::class);
    $profileStorage->method('load')->with('developer')->willReturn($profile);
    $this->entityTypeManager->method('getStorage')
      ->with('agent_profile')
      ->willReturn($profileStorage);

    // Not currently running.
    $this->executionStore->method('isRunning')->with('job-3')->willReturn(FALSE);
    $this->executionStore->method('getLastSessionId')->with('job-3')->willReturn(NULL);

    // Envelope service returns envelope and headers.
    $this->envelopeService->method('create')->willReturn(['run_id' => 'env-1']);
    $this->envelopeService->method('buildMcpHeaders')->willReturn(['X-Mcp' => 'test']);

    // principalResolver->executeAs runs the callback immediately.
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $ctx = new ExecutionContext(
      account: $account,
      previousUid: 1,
      profileId: 'developer',
      modality: 'interactive',
      switchedAt: time(),
    );
    $this->principalResolver->method('executeAs')
      ->willReturnCallback(function ($profile, $modality, $callback) use ($ctx) {
        return $callback($ctx);
      });

    // bridge->fireAndForget should be called once, returns queryId.
    $this->bridge->expects($this->once())
      ->method('fireAndForget')
      ->willReturn('query-abc-123');

    // executionStore->create should be called with the right params.
    $this->executionStore->expects($this->once())
      ->method('create')
      ->with(
        'query-abc-123',
        'content_agent',
        'developer',
        'job-3',
        $this->isType('string'),
        42,
      );

    $result = $this->decorator->runAsAgent(
      'content_agent',
      [['role' => 'user', 'message' => 'Hello']],
      [],
      'job-3',
    );

    $text = $result->getNormalized()->getText();
    $this->assertStringContainsString('claude-async-execution', $text);
    $this->assertStringContainsString('query-abc-123', $text);
  }

  /**
   * Test: Returns "already in progress" when an execution is running.
   */
  public function testRunAsAgentReturnsAlreadyInProgressWhenRunning(): void {
    // Set up Claude-enabled agent.
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => TRUE, 'profile_id' => 'developer']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('running_agent')
      ->willReturn($plugin);

    $profile = $this->createMock(AgentProfileInterface::class);
    $profileStorage = $this->createMock(EntityStorageInterface::class);
    $profileStorage->method('load')->with('developer')->willReturn($profile);
    $this->entityTypeManager->method('getStorage')
      ->with('agent_profile')
      ->willReturn($profileStorage);

    // Already running.
    $this->executionStore->method('isRunning')->with('job-running')->willReturn(TRUE);

    // fireAndForget should NEVER be called.
    $this->bridge->expects($this->never())->method('fireAndForget');

    $result = $this->decorator->runAsAgent('running_agent', [], [], 'job-running');

    $text = $result->getNormalized()->getText();
    $this->assertStringContainsString('already in progress', $text);
  }

  /**
   * Test: Passes resume session ID from execution store to bridge.
   */
  public function testRunAsAgentPassesResumeSessionId(): void {
    // Set up Claude-enabled agent.
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => TRUE, 'profile_id' => 'dev']);
    $agentEntity->method('toArray')
      ->willReturn(['system_prompt' => '']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('resume_agent')
      ->willReturn($plugin);

    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('toSidecarFormat')->willReturn(['model' => 'claude']);
    $profile->method('getSystemPrompt')->willReturn('System prompt.');
    $profileStorage = $this->createMock(EntityStorageInterface::class);
    $profileStorage->method('load')->with('dev')->willReturn($profile);
    $this->entityTypeManager->method('getStorage')
      ->with('agent_profile')
      ->willReturn($profileStorage);

    $this->executionStore->method('isRunning')->with('job-resume')->willReturn(FALSE);
    // Return a previous session ID for resumption.
    $this->executionStore->method('getLastSessionId')->with('job-resume')->willReturn('sess-prev');

    $this->envelopeService->method('create')->willReturn(['run_id' => 'env-2']);
    $this->envelopeService->method('buildMcpHeaders')->willReturn([]);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $ctx = new ExecutionContext(
      account: $account,
      previousUid: 1,
      profileId: 'dev',
      modality: 'interactive',
      switchedAt: time(),
    );
    $this->principalResolver->method('executeAs')
      ->willReturnCallback(function ($profile, $modality, $callback) use ($ctx) {
        return $callback($ctx);
      });

    // Assert that fireAndForget receives 'sess-prev' as the third arg (resume).
    $this->bridge->expects($this->once())
      ->method('fireAndForget')
      ->with(
        $this->anything(),
        $this->anything(),
        'sess-prev',
        $this->anything(),
        $this->anything(),
        $this->anything(),
      )
      ->willReturn('query-resume-456');

    $this->executionStore->expects($this->once())->method('create');

    $result = $this->decorator->runAsAgent(
      'resume_agent',
      [['role' => 'user', 'message' => 'Continue please']],
      [],
      'job-resume',
    );

    $text = $result->getNormalized()->getText();
    $this->assertStringContainsString('query-resume-456', $text);
  }

  /**
   * Test: Falls through when profile doesn't exist.
   */
  public function testFallthroughWhenProfileMissing(): void {
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => TRUE, 'profile_id' => 'nonexistent']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('broken_agent')
      ->willReturn($plugin);

    $profileStorage = $this->createMock(EntityStorageInterface::class);
    $profileStorage->method('load')
      ->with('nonexistent')
      ->willReturn(NULL);
    $this->entityTypeManager->method('getStorage')
      ->with('agent_profile')
      ->willReturn($profileStorage);

    $expectedOutput = new ChatOutput(new ChatMessage('assistant', 'fallback'), ['fallback'], []);
    $this->innerRunner->method('runAsAgent')->willReturn($expectedOutput);

    $result = $this->decorator->runAsAgent('broken_agent', [], [], 'job-4');

    $this->assertSame($expectedOutput, $result);
  }

  /**
   * Test: Principal error returns ChatOutput with error message.
   */
  public function testPrincipalErrorReturnsChatOutput(): void {
    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agentEntity->method('getThirdPartySetting')
      ->with('ai_claude_agent_sdk_runner', 'config', [])
      ->willReturn(['enabled' => TRUE, 'profile_id' => 'dev']);

    $plugin = $this->createMock(AiAgentEntityWrapper::class);
    $plugin->method('getAiAgentEntity')->willReturn($agentEntity);
    $this->pluginManager->method('createInstance')
      ->with('running_agent')
      ->willReturn($plugin);

    $profile = $this->createMock(AgentProfileInterface::class);
    $profileStorage = $this->createMock(EntityStorageInterface::class);
    $profileStorage->method('load')->with('dev')->willReturn($profile);
    $this->entityTypeManager->method('getStorage')
      ->with('agent_profile')
      ->willReturn($profileStorage);

    $this->executionStore->method('isRunning')->willReturn(FALSE);

    // Principal resolver throws — blocked user, no executor, etc.
    $this->principalResolver->method('executeAs')
      ->willThrowException(new \Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException('Executor user is blocked'));

    $result = $this->decorator->runAsAgent('running_agent', [], [], 'job-5');
    $this->assertStringContainsString('Execution principal error', $result->getNormalized()->getText());
    $this->assertStringContainsString('blocked', $result->getNormalized()->getText());
  }

}
