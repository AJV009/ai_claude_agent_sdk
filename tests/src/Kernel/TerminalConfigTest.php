<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests sidecar_ws_url configuration defaults and save/load.
 *
 * @group ai_claude_agent_sdk
 */
class TerminalConfigTest extends KernelTestBase {

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
   * Tests that sidecar_ws_url defaults to an empty string.
   */
  public function testSidecarWsUrlDefault(): void {
    $config = $this->config('ai_claude_agent_sdk.settings');
    $this->assertSame('', $config->get('sidecar_ws_url'));
  }

  /**
   * Tests that sidecar_ws_url can be saved and retrieved.
   */
  public function testSidecarWsUrlSaveAndLoad(): void {
    $config = $this->config('ai_claude_agent_sdk.settings');
    $config->set('sidecar_ws_url', 'wss://mysite.ddev.site:3100/ws')->save();

    $reloaded = $this->config('ai_claude_agent_sdk.settings');
    $this->assertSame('wss://mysite.ddev.site:3100/ws', $reloaded->get('sidecar_ws_url'));
  }

}
