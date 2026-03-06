<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Kernel;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfile;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeService;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests for the ExecutionEnvelopeService.
 *
 * @group ai_claude_agent_sdk
 */
class ExecutionEnvelopeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_claude_agent_sdk',
  ];

  /**
   * The envelope service under test.
   */
  private ExecutionEnvelopeService $envelopeService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_claude_agent_sdk']);
    $this->installEntitySchema('user');
    $this->envelopeService = $this->container->get('ai_claude_agent_sdk.execution_envelope');
  }

  /**
   * Tests that executor_uid=0 resolves to the current user.
   */
  public function testCreateEnvelopeWithDefaultExecutor(): void {
    $profile = AgentProfile::create([
      'id' => 'test_default_executor',
      'label' => 'Test Default Executor',
      'executor_uid' => 0,
      'execution_modality' => 'interactive',
    ]);
    $profile->save();

    $envelope = $this->envelopeService->create($profile);

    $this->assertNotEmpty($envelope['run_id']);
    $this->assertEquals('test_default_executor', $envelope['profile_id']);
    // executor_uid=0 on profile should resolve to current user (anonymous = 0 in kernel tests).
    $this->assertEquals((int) \Drupal::currentUser()->id(), $envelope['executor_uid']);
    $this->assertEquals((int) \Drupal::currentUser()->id(), $envelope['initiator_uid']);
    $this->assertEquals('interactive', $envelope['modality']);
    $this->assertNotEmpty($envelope['created']);
  }

  /**
   * Tests that an explicit executor_uid is preserved.
   */
  public function testCreateEnvelopeWithExplicitExecutor(): void {
    $profile = AgentProfile::create([
      'id' => 'test_explicit_executor',
      'label' => 'Test Explicit Executor',
      'executor_uid' => 42,
      'execution_modality' => 'background',
    ]);
    $profile->save();

    $envelope = $this->envelopeService->create($profile);

    $this->assertEquals(42, $envelope['executor_uid']);
    $this->assertEquals('background', $envelope['modality']);
  }

  /**
   * Tests envelope storage and retrieval via tempstore.
   */
  public function testEnvelopeStorageAndRetrieval(): void {
    $profile = AgentProfile::create([
      'id' => 'test_storage',
      'label' => 'Test Storage',
      'executor_uid' => 1,
      'execution_modality' => 'interactive',
    ]);
    $profile->save();

    $envelope = $this->envelopeService->create($profile);
    $retrieved = $this->envelopeService->get($envelope['run_id']);

    $this->assertNotNull($retrieved);
    $this->assertEquals($envelope['run_id'], $retrieved['run_id']);
    $this->assertEquals('test_storage', $retrieved['profile_id']);
    $this->assertEquals(1, $retrieved['executor_uid']);
    $this->assertEquals('interactive', $retrieved['modality']);
  }

  /**
   * Tests that get() returns NULL for unknown run IDs.
   */
  public function testGetReturnsNullForUnknown(): void {
    $this->assertNull($this->envelopeService->get('nonexistent-run-id'));
  }

  /**
   * Tests buildMcpHeaders returns all required headers.
   */
  public function testBuildMcpHeaders(): void {
    $profile = AgentProfile::create([
      'id' => 'test_headers',
      'label' => 'Test Headers',
      'executor_uid' => 5,
      'execution_modality' => 'background',
    ]);
    $profile->save();

    $envelope = $this->envelopeService->create($profile, 10);
    $headers = $this->envelopeService->buildMcpHeaders($envelope);

    $this->assertArrayHasKey('X-AI-Run-ID', $headers);
    $this->assertArrayHasKey('X-AI-Executor-UID', $headers);
    $this->assertArrayHasKey('X-AI-Initiator-UID', $headers);
    $this->assertArrayHasKey('X-AI-Modality', $headers);

    $this->assertEquals($envelope['run_id'], $headers['X-AI-Run-ID']);
    $this->assertEquals('5', $headers['X-AI-Executor-UID']);
    $this->assertEquals('10', $headers['X-AI-Initiator-UID']);
    $this->assertEquals('background', $headers['X-AI-Modality']);
  }

  /**
   * Tests various execution modality values.
   */
  public function testEnvelopeModalities(): void {
    foreach (['interactive', 'background', 'outside_in'] as $modality) {
      $profile = AgentProfile::create([
        'id' => 'test_modality_' . $modality,
        'label' => 'Test ' . $modality,
        'executor_uid' => 0,
        'execution_modality' => $modality,
      ]);
      $profile->save();

      $envelope = $this->envelopeService->create($profile);
      $this->assertEquals($modality, $envelope['modality']);
    }
  }

}
