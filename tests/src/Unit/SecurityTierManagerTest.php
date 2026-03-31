<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Service\SecurityTierManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests SecurityTierManager tier settings generation.
 *
 * @group ai_claude_agent_sdk
 */
class SecurityTierManagerTest extends TestCase {

  private SecurityTierManager $manager;

  protected function setUp(): void {
    parent::setUp();
    $this->manager = new SecurityTierManager();
  }

  public function testStrictTierSettings(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('strict', $profile);

    $this->assertSame('plan', $settings['permission_mode']);
    $this->assertTrue($settings['sandbox']['enabled']);
    $this->assertTrue($settings['sandbox']['network']);
    $this->assertSame('enforced', $settings['hooks']['mode']);
    $this->assertTrue($settings['managed_settings']['disableBypassPermissionsMode']);
    $this->assertTrue($settings['managed_settings']['allowManagedPermissionRulesOnly']);
    // Strict: only bridge commands allowed.
    $this->assertNotEmpty($settings['permission_rules']['allow']);
    foreach ($settings['permission_rules']['allow'] as $pattern) {
      $this->assertStringContainsString('ai-claude-agent-sdk:tool-run', $pattern);
    }
  }

  public function testStandardTierSettings(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('standard', $profile);

    $this->assertSame('default', $settings['permission_mode']);
    $this->assertTrue($settings['sandbox']['enabled']);
    $this->assertFalse($settings['sandbox']['network']);
    $this->assertSame('enforced', $settings['hooks']['mode']);
    $this->assertTrue($settings['managed_settings']['disableBypassPermissionsMode']);
    // Standard: bridge + read tools.
    $hasReadPattern = FALSE;
    foreach ($settings['permission_rules']['allow'] as $pattern) {
      if (str_contains($pattern, 'ls') || str_contains($pattern, 'pwd')) {
        $hasReadPattern = TRUE;
        break;
      }
    }
    $this->assertTrue($hasReadPattern, 'Standard tier should allow read helper patterns');
  }

  public function testPermissiveTierSettings(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('permissive', $profile);

    $this->assertSame('acceptEdits', $settings['permission_mode']);
    $this->assertFalse($settings['sandbox']['enabled']);
    $this->assertSame('logging', $settings['hooks']['mode']);
    $this->assertTrue($settings['managed_settings']['disableBypassPermissionsMode']);
    // Permissive: deny list for dangerous commands.
    $this->assertNotEmpty($settings['permission_rules']['deny']);
  }

  public function testCustomTierUsesProfileValues(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getPermissionMode')->willReturn('delegate');
    $profile->method('getSandbox')->willReturn(TRUE);
    $profile->method('getSandboxNetwork')->willReturn(FALSE);
    $profile->method('getHookMode')->willReturn('');
    $profile->method('getDisableBypassMode')->willReturn(FALSE);
    $profile->method('getManagedRulesOnly')->willReturn(FALSE);
    $profile->method('getBashAllowPatterns')->willReturn([]);
    $profile->method('getBashDenyPatterns')->willReturn([]);
    $profile->method('getAllowedTools')->willReturn(['Read', 'Grep']);
    $profile->method('getDeniedTools')->willReturn(['Bash']);

    $settings = $this->manager->buildTierSettings('custom', $profile);

    $this->assertSame('delegate', $settings['permission_mode']);
    $this->assertTrue($settings['sandbox']['enabled']);
    $this->assertSame(['Read', 'Grep'], $settings['allowed_tools']);
    $this->assertSame(['Bash'], $settings['denied_tools']);
  }

  public function testUnknownTierDefaultsToStrict(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('nonexistent', $profile);

    $this->assertSame('plan', $settings['permission_mode'], 'Unknown tier should default to strict');
  }

  public function testGenerateManagedSettingsStrict(): void {
    $settings = $this->manager->generateManagedSettings('strict');

    $this->assertTrue($settings['disableBypassPermissionsMode']);
    $this->assertTrue($settings['allowManagedPermissionRulesOnly']);
  }

  public function testGenerateManagedSettingsPermissive(): void {
    $settings = $this->manager->generateManagedSettings('permissive');

    $this->assertTrue($settings['disableBypassPermissionsMode']);
    $this->assertArrayNotHasKey('allowManagedPermissionRulesOnly', $settings);
  }

  public function testGenerateHookConfigEnforced(): void {
    $hooks = $this->manager->generateHookConfig('strict', 'https://example.com/api/claude-policy/evaluate');

    $this->assertArrayHasKey('PreToolUse', $hooks);
    $this->assertSame('https://example.com/api/claude-policy/evaluate', $hooks['PreToolUse']['url']);
    $this->assertSame('enforced', $hooks['PreToolUse']['mode']);
  }

  public function testGenerateHookConfigLogging(): void {
    $hooks = $this->manager->generateHookConfig('permissive', 'https://example.com/api/claude-policy/evaluate');

    $this->assertArrayHasKey('PreToolUse', $hooks);
    $this->assertSame('logging', $hooks['PreToolUse']['mode']);
  }

  public function testGenerateHookConfigCustomReturnsEmpty(): void {
    $hooks = $this->manager->generateHookConfig('custom', 'https://example.com/api/claude-policy/evaluate', '');
    $this->assertEmpty($hooks);
  }

  public function testDescribeTierReturnsLabels(): void {
    $description = $this->manager->describeTier('strict');

    $this->assertIsArray($description);
    $this->assertArrayHasKey('Permission mode', $description);
    $this->assertArrayHasKey('Sandbox', $description);
    $this->assertArrayHasKey('HTTP policy hook', $description);
    $this->assertSame('plan (read-only, no mutations)', $description['Permission mode']);
  }

  public function testDescribeTierCustom(): void {
    $description = $this->manager->describeTier('custom');

    $this->assertArrayHasKey('Permission mode', $description);
    $this->assertSame('Manual configuration', $description['Permission mode']);
  }

  public function testStrictTierSandboxIncludesNetwork(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('strict', $profile);

    $this->assertTrue($settings['sandbox']['network'], 'Strict tier should enforce network isolation');
  }

  public function testStandardTierNoNetworkIsolation(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('standard', $profile);

    $this->assertFalse($settings['sandbox']['network'], 'Standard tier should not enforce network isolation');
  }

  public function testPermissiveTierHasDenyRules(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('permissive', $profile);

    $hasDangerousPattern = FALSE;
    foreach ($settings['permission_rules']['deny'] as $pattern) {
      if (str_contains($pattern, 'rm -rf') || str_contains($pattern, 'force')) {
        $hasDangerousPattern = TRUE;
        break;
      }
    }
    $this->assertTrue($hasDangerousPattern, 'Permissive tier should deny dangerous command patterns');
  }

  public function testAllNonCustomTiersDisableBypass(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    foreach (['strict', 'standard', 'permissive'] as $tier) {
      $settings = $this->manager->buildTierSettings($tier, $profile);
      $this->assertTrue(
        $settings['managed_settings']['disableBypassPermissionsMode'],
        "Tier '$tier' must disable bypass permissions mode"
      );
    }
  }

  public function testDescribeAllTiersReturnExpectedKeys(): void {
    $expectedKeys = ['Permission mode', 'Sandbox', 'HTTP policy hook'];

    foreach (SecurityTierManager::VALID_TIERS as $tier) {
      $description = $this->manager->describeTier($tier);
      foreach ($expectedKeys as $key) {
        $this->assertArrayHasKey($key, $description, "Tier '$tier' description missing key '$key'");
      }
    }
  }

  public function testEnforcedHookConfigHasBothHookTypes(): void {
    $hooks = $this->manager->generateHookConfig('strict', 'https://example.com/api');

    $this->assertArrayHasKey('PreToolUse', $hooks);
    $this->assertArrayHasKey('PostToolUse', $hooks);
    $this->assertSame('enforced', $hooks['PreToolUse']['mode']);
    $this->assertSame('logging', $hooks['PostToolUse']['mode'], 'PostToolUse should always be logging mode');
  }

  public function testValidateTierRequirementsStrictNoUrl(): void {
    $result = $this->manager->validateTierRequirements('strict', '');
    $this->assertCount(1, $result['errors']);
    $this->assertEmpty($result['warnings']);
    $this->assertStringContainsString('site_base_url', $result['errors'][0]);
  }

  public function testValidateTierRequirementsStrictWithUrl(): void {
    $result = $this->manager->validateTierRequirements('strict', 'http://web');
    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  public function testValidateTierRequirementsStandardNoUrl(): void {
    $result = $this->manager->validateTierRequirements('standard', '');
    $this->assertEmpty($result['errors']);
    $this->assertCount(1, $result['warnings']);
    $this->assertStringContainsString('site_base_url', $result['warnings'][0]);
  }

  public function testValidateTierRequirementsPermissiveNoUrl(): void {
    $result = $this->manager->validateTierRequirements('permissive', '');
    $this->assertEmpty($result['errors']);
    $this->assertCount(1, $result['warnings']);
    $this->assertStringContainsString('site_base_url', $result['warnings'][0]);
  }

  public function testValidateTierRequirementsCustomNoUrl(): void {
    $result = $this->manager->validateTierRequirements('custom', '');
    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  public function testValidateTierRequirementsStandardWithUrl(): void {
    $result = $this->manager->validateTierRequirements('standard', 'http://web');
    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

  public function testCustomTierReadsAllProperties(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getPermissionMode')->willReturn('acceptEdits');
    $profile->method('getSandbox')->willReturn(TRUE);
    $profile->method('getSandboxNetwork')->willReturn(TRUE);
    $profile->method('getHookMode')->willReturn('enforced');
    $profile->method('getDisableBypassMode')->willReturn(TRUE);
    $profile->method('getManagedRulesOnly')->willReturn(TRUE);
    $profile->method('getBashAllowPatterns')->willReturn(['Bash(ls:*)']);
    $profile->method('getBashDenyPatterns')->willReturn(['Bash(rm -rf *)']);
    $profile->method('getAllowedTools')->willReturn(['Read']);
    $profile->method('getDeniedTools')->willReturn(['Bash']);

    $settings = $this->manager->buildTierSettings('custom', $profile);

    $this->assertSame('acceptEdits', $settings['permission_mode']);
    $this->assertTrue($settings['sandbox']['enabled']);
    $this->assertTrue($settings['sandbox']['network']);
    $this->assertSame('enforced', $settings['hooks']['mode']);
    $this->assertTrue($settings['managed_settings']['disableBypassPermissionsMode']);
    $this->assertTrue($settings['managed_settings']['allowManagedPermissionRulesOnly']);
    $this->assertSame(['Bash(ls:*)'], $settings['permission_rules']['allow']);
    $this->assertSame(['Bash(rm -rf *)'], $settings['permission_rules']['deny']);
    $this->assertSame(['Read'], $settings['allowed_tools']);
    $this->assertSame(['Bash'], $settings['denied_tools']);
  }

  public function testCustomTierDisabledHookMode(): void {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getPermissionMode')->willReturn('default');
    $profile->method('getSandbox')->willReturn(FALSE);
    $profile->method('getSandboxNetwork')->willReturn(FALSE);
    $profile->method('getHookMode')->willReturn('');
    $profile->method('getDisableBypassMode')->willReturn(FALSE);
    $profile->method('getManagedRulesOnly')->willReturn(FALSE);
    $profile->method('getBashAllowPatterns')->willReturn([]);
    $profile->method('getBashDenyPatterns')->willReturn([]);
    $profile->method('getAllowedTools')->willReturn([]);
    $profile->method('getDeniedTools')->willReturn([]);

    $settings = $this->manager->buildTierSettings('custom', $profile);

    $this->assertEmpty($settings['hooks']);
    $this->assertFalse($settings['managed_settings']['disableBypassPermissionsMode']);
    $this->assertFalse($settings['managed_settings']['allowManagedPermissionRulesOnly']);
    $this->assertEmpty($settings['permission_rules']['allow']);
    $this->assertEmpty($settings['permission_rules']['deny']);
  }

  public function testGenerateHookConfigCustomWithMode(): void {
    $hooks = $this->manager->generateHookConfig('custom', 'https://example.com/api', 'enforced');
    $this->assertArrayHasKey('PreToolUse', $hooks);
    $this->assertSame('enforced', $hooks['PreToolUse']['mode']);
  }

  public function testGenerateHookConfigCustomDisabled(): void {
    $hooks = $this->manager->generateHookConfig('custom', 'https://example.com/api', '');
    $this->assertEmpty($hooks);
  }

  public function testGenerateManagedSettingsCustom(): void {
    $settings = $this->manager->generateManagedSettings('custom', TRUE, FALSE);
    $this->assertTrue($settings['disableBypassPermissionsMode']);
    $this->assertArrayNotHasKey('allowManagedPermissionRulesOnly', $settings);
  }

  public function testValidateCustomTierWithHookModeNoUrl(): void {
    $result = $this->manager->validateTierRequirements('custom', '', 'enforced');
    $this->assertCount(1, $result['warnings']);
  }

  public function testValidateCustomTierWithHookModeHasUrl(): void {
    $result = $this->manager->validateTierRequirements('custom', 'http://web', 'enforced');
    $this->assertEmpty($result['errors']);
    $this->assertEmpty($result['warnings']);
  }

}
