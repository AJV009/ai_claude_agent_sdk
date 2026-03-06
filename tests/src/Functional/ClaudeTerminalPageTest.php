<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Claude Terminal admin page.
 *
 * @group ai_claude_agent_sdk
 */
class ClaudeTerminalPageTest extends BrowserTestBase {

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
   * Tests that anonymous users cannot access the terminal page.
   */
  public function testAnonymousAccess(): void {
    $this->drupalGet('/admin/config/ai/claude-agent-sdk/terminal');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that users with the correct permission can access the page.
   */
  public function testAuthorizedAccess(): void {
    $user = $this->drupalCreateUser(['use claude terminal']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/ai/claude-agent-sdk/terminal');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that the page contains the expected terminal elements.
   */
  public function testPageElements(): void {
    $user = $this->drupalCreateUser(['use claude terminal']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/ai/claude-agent-sdk/terminal');

    $this->assertSession()->elementExists('css', '#claude-terminal-wrapper');
    $this->assertSession()->elementExists('css', '#claude-terminal');
    $this->assertSession()->elementExists('css', '#claude-terminal-toolbar');
    $this->assertSession()->elementExists('css', '#claude-terminal-status');
    $this->assertSession()->elementExists('css', '.claude-terminal-fullpage');
  }

  /**
   * Tests that drupalSettings contains terminal configuration.
   */
  public function testDrupalSettings(): void {
    $user = $this->drupalCreateUser(['use claude terminal']);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/ai/claude-agent-sdk/terminal');

    // The page should contain drupalSettings with claudeTerminal data.
    $this->assertSession()->responseContains('claudeTerminal');
  }

}
