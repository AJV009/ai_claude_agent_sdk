<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests sidecar URL configuration defaults and schema.
 *
 * @group ai_claude_agent_sdk
 */
class SidecarConfigTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'ai',
    'ai_claude_agent_sdk',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_claude_agent_sdk']);
  }

  /**
   * Tests that sidecar_url has the correct default value.
   */
  public function testSidecarUrlDefault(): void {
    $config = $this->config('ai_claude_agent_sdk.settings');
    $this->assertEquals('http://localhost:3000', $config->get('sidecar_url'));
  }

  /**
   * Tests that sidecar_url can be saved and retrieved.
   */
  public function testSidecarUrlSaveAndLoad(): void {
    $config = $this->config('ai_claude_agent_sdk.settings');
    $config->set('sidecar_url', 'http://sidecar.example.com:8080')->save();

    $reloaded = $this->config('ai_claude_agent_sdk.settings');
    $this->assertEquals('http://sidecar.example.com:8080', $reloaded->get('sidecar_url'));
  }

  /**
   * Tests the config fallback chain used in hook_requirements().
   */
  public function testSidecarUrlFallbackChain(): void {
    // 1. Config value takes priority.
    $config = $this->config('ai_claude_agent_sdk.settings');
    $config->set('sidecar_url', 'http://from-config:3000')->save();

    $resolved = $this->resolveSidecarUrl();
    $this->assertEquals('http://from-config:3000', $resolved);

    // 2. Empty config falls back to env var.
    $config->set('sidecar_url', '')->save();
    putenv('CLAUDE_SIDECAR_URL=http://from-env:4000');

    $resolved = $this->resolveSidecarUrl();
    $this->assertEquals('http://from-env:4000', $resolved);

    // 3. No config and no env var falls back to default.
    putenv('CLAUDE_SIDECAR_URL');
    $resolved = $this->resolveSidecarUrl();
    $this->assertEquals('http://localhost:3000', $resolved);
  }

  /**
   * Resolves sidecar URL using the same logic as hook_requirements().
   */
  private function resolveSidecarUrl(): string {
    $config = \Drupal::config('ai_claude_agent_sdk.settings');
    return $config->get('sidecar_url')
      ?: getenv('CLAUDE_SIDECAR_URL')
      ?: 'http://localhost:3000';
  }

}
