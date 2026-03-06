<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the sidecar section in the settings form.
 *
 * @group ai_claude_agent_sdk
 */
class SidecarSettingsFormTest extends BrowserTestBase {

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
  protected $defaultTheme = 'stark';

  /**
   * Tests that the sidecar URL field appears and saves correctly.
   */
  public function testSidecarUrlField(): void {
    $admin = $this->drupalCreateUser(['administer claude agent sdk']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/ai/claude-agent-sdk');
    $this->assertSession()->statusCodeEquals(200);

    // Verify sidecar section and field exist.
    $this->assertSession()->pageTextContains('Sidecar');
    $this->assertSession()->fieldExists('sidecar_url');

    // Verify default value.
    $this->assertSession()->fieldValueEquals('sidecar_url', 'http://localhost:3000');

    // Submit a new value.
    $this->submitForm([
      'sidecar_url' => 'http://custom-sidecar:9000',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Verify it persisted.
    $config = $this->config('ai_claude_agent_sdk.settings');
    $this->assertEquals('http://custom-sidecar:9000', $config->get('sidecar_url'));

    // Reload and confirm field shows updated value.
    $this->drupalGet('/admin/config/ai/claude-agent-sdk');
    $this->assertSession()->fieldValueEquals('sidecar_url', 'http://custom-sidecar:9000');
  }

}
