<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;

/**
 * Manages security tier definitions and generates settings bundles.
 *
 * Each tier (strict, standard, permissive, custom) produces a full
 * configuration bundle controlling permission_mode, sandbox, HTTP hooks,
 * managed settings, and permission rules. The ClaudeBridgeService uses
 * these bundles when building sidecar request payloads.
 */
final class SecurityTierManager {

  /**
   * Bridge-only bash permission patterns.
   *
   * @var string[]
   */
  private const BRIDGE_PATTERNS = [
    'Bash(drush:ai-claude-agent-sdk:tool-run*)',
    'Bash(drush ai-claude-agent-sdk:tool-run *)',
    'Bash(ddev:drush ai-claude-agent-sdk:tool-run*)',
    'Bash(ddev drush ai-claude-agent-sdk:tool-run *)',
    'Bash(ddev:exec drush ai-claude-agent-sdk:tool-run*)',
    'Bash(ddev exec drush ai-claude-agent-sdk:tool-run *)',
  ];

  /**
   * Read helper bash patterns (added to standard tier).
   *
   * @var string[]
   */
  private const READ_PATTERNS = [
    'Bash(ls:*)',
    'Bash(pwd)',
    'Bash(cat:*)',
  ];

  /**
   * Dangerous command deny patterns (used by permissive tier).
   *
   * @var string[]
   */
  private const DANGEROUS_PATTERNS = [
    'Bash(rm -rf *)',
    'Bash(rm -r /*)',
    'Bash(git push --force*)',
    'Bash(git reset --hard*)',
    'Bash(chmod -R 777*)',
    'Bash(dd if=*)',
    'Bash(mkfs*)',
    'Bash(:(){ :|:& };:*)',
    'Bash(> /dev/sd*)',
    'Bash(drush sql-drop*)',
    'Bash(drush site:install*)',
  ];

  /**
   * Valid tier names.
   *
   * @var string[]
   */
  public const VALID_TIERS = ['strict', 'standard', 'permissive', 'custom'];

  /**
   * Returns the full settings bundle for a security tier.
   *
   * Used by ClaudeBridgeService when building request payloads.
   *
   * @param string $tier
   *   One of: 'strict', 'standard', 'permissive', 'custom'.
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile entity.
   *
   * @return array
   *   Settings bundle with keys: permission_mode, sandbox, hooks,
   *   managed_settings, permission_rules, and optionally allowed_tools,
   *   denied_tools.
   */
  public function buildTierSettings(string $tier, AgentProfileInterface $profile): array {
    if (!in_array($tier, self::VALID_TIERS, TRUE)) {
      $tier = 'strict';
    }

    return match ($tier) {
      'strict' => $this->buildStrictSettings($profile),
      'standard' => $this->buildStandardSettings($profile),
      'permissive' => $this->buildPermissiveSettings($profile),
      'custom' => $this->buildCustomSettings($profile),
    };
  }

  /**
   * Generates Claude Code managed settings for a tier.
   *
   * @param string $tier
   *   The security tier.
   * @param bool $disableBypassMode
   *   For custom tier: whether to disable bypass mode. Ignored for predefined.
   * @param bool $managedRulesOnly
   *   For custom tier: whether to enforce managed rules only. Ignored for predefined.
   *
   * @return array
   *   Managed settings array.
   */
  public function generateManagedSettings(string $tier, bool $disableBypassMode = FALSE, bool $managedRulesOnly = FALSE): array {
    if (!in_array($tier, self::VALID_TIERS, TRUE)) {
      $tier = 'strict';
    }

    if ($tier === 'custom') {
      $settings = [];
      if ($disableBypassMode) {
        $settings['disableBypassPermissionsMode'] = TRUE;
      }
      if ($managedRulesOnly) {
        $settings['allowManagedPermissionRulesOnly'] = TRUE;
      }
      return $settings;
    }

    return match ($tier) {
      'strict' => [
        'disableBypassPermissionsMode' => TRUE,
        'allowManagedPermissionRulesOnly' => TRUE,
      ],
      'standard' => [
        'disableBypassPermissionsMode' => TRUE,
        'allowManagedPermissionRulesOnly' => TRUE,
      ],
      'permissive' => [
        'disableBypassPermissionsMode' => TRUE,
      ],
    };
  }

  /**
   * Generates hook configuration for the HTTP policy endpoint.
   *
   * @param string $tier
   *   The security tier.
   * @param string $policyEndpointUrl
   *   The full URL of the policy evaluation endpoint.
   * @param string $hookMode
   *   For custom tier: the hook mode from profile ('', 'logging', 'enforced').
   *   Ignored for predefined tiers.
   *
   * @return array
   *   Hook config array. Empty when hooks are disabled.
   */
  public function generateHookConfig(string $tier, string $policyEndpointUrl, string $hookMode = ''): array {
    if (!in_array($tier, self::VALID_TIERS, TRUE)) {
      $tier = 'strict';
    }

    if ($tier === 'custom') {
      if ($hookMode === '') {
        return [];
      }
      $mode = $hookMode;
    }
    else {
      $mode = match ($tier) {
        'strict', 'standard' => 'enforced',
        'permissive' => 'logging',
        default => 'enforced',
      };
    }

    return [
      'PreToolUse' => [
        'url' => $policyEndpointUrl,
        'mode' => $mode,
      ],
      'PostToolUse' => [
        'url' => $policyEndpointUrl,
        'mode' => 'logging',
      ],
    ];
  }

  /**
   * Validates whether tier requirements can be met in the current context.
   *
   * @param string $tier
   *   The security tier.
   * @param string $baseUrl
   *   The resolved site base URL (may be empty string).
   * @param string $hookMode
   *   For custom tier: the hook mode from profile. Ignored for predefined.
   *
   * @return array{errors: string[], warnings: string[]}
   *   Arrays of error and warning messages.
   */
  public function validateTierRequirements(string $tier, string $baseUrl, string $hookMode = ''): array {
    if (!in_array($tier, self::VALID_TIERS, TRUE)) {
      $tier = 'strict';
    }

    $errors = [];
    $warnings = [];

    if ($tier === 'custom') {
      if ($hookMode !== '' && $baseUrl === '') {
        $warnings[] = sprintf(
          'Policy hooks cannot be activated: site_base_url is not configured. '
          . 'The custom tier hook mode "%s" will not be active. '
          . 'Configure site_base_url at /admin/config/ai/claude-agent-sdk.',
          $hookMode
        );
      }
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    if ($baseUrl === '') {
      $message = sprintf(
        'Policy hooks cannot be activated: site_base_url is not configured and no HTTP request context is available. '
        . 'Per-tool-call policy evaluation will not be active for the "%s" tier. '
        . 'Configure site_base_url at /admin/config/ai/claude-agent-sdk.',
        $tier
      );

      if ($tier === 'strict') {
        $errors[] = $message;
      }
      else {
        $warnings[] = $message;
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Returns human-readable description of what a tier allows.
   *
   * Used in the profile form to show the "Generated Settings" preview.
   *
   * @param string $tier
   *   The security tier.
   *
   * @return array
   *   Associative array of setting labels => human-readable values.
   */
  public function describeTier(string $tier): array {
    if (!in_array($tier, self::VALID_TIERS, TRUE)) {
      $tier = 'strict';
    }

    return match ($tier) {
      'strict' => [
        'Permission mode' => 'plan (read-only, no mutations)',
        'Sandbox' => 'Full: filesystem + network isolation',
        'HTTP policy hook' => 'Enforced (every tool call evaluated)',
        'Bypass mode' => 'Disabled',
        'Managed rules only' => 'Yes',
        'Bash permissions' => 'Bridge-only (drush ai-claude-agent-sdk:tool-run)',
      ],
      'standard' => [
        'Permission mode' => 'default (prompts for novel actions)',
        'Sandbox' => 'Filesystem isolation (network open)',
        'HTTP policy hook' => 'Enforced (every tool call evaluated)',
        'Bypass mode' => 'Disabled',
        'Managed rules only' => 'Yes',
        'Bash permissions' => 'Bridge + read helpers (ls, pwd, cat)',
      ],
      'permissive' => [
        'Permission mode' => 'acceptEdits (auto-approves file edits)',
        'Sandbox' => 'Optional (not enforced)',
        'HTTP policy hook' => 'Logging only (records activity, does not block)',
        'Bypass mode' => 'Disabled',
        'Managed rules only' => 'No',
        'Bash permissions' => 'Broad, with deny rules for destructive commands',
      ],
      'custom' => [
        'Permission mode' => 'Manual configuration',
        'Sandbox' => 'Manual configuration',
        'HTTP policy hook' => 'Optional',
        'Bypass mode' => 'Manual configuration',
        'Managed rules only' => 'Manual configuration',
        'Bash permissions' => 'Manual allowlist/denylist',
      ],
    };
  }

  /**
   * Strict tier: maximum lockdown.
   */
  private function buildStrictSettings(AgentProfileInterface $profile): array {
    return [
      'permission_mode' => 'plan',
      'sandbox' => [
        'enabled' => TRUE,
        'network' => TRUE,
      ],
      'hooks' => [
        'mode' => 'enforced',
      ],
      'managed_settings' => [
        'disableBypassPermissionsMode' => TRUE,
        'allowManagedPermissionRulesOnly' => TRUE,
      ],
      'permission_rules' => [
        'allow' => self::BRIDGE_PATTERNS,
        'deny' => [],
      ],
    ];
  }

  /**
   * Standard tier: balanced security for supervised use.
   */
  private function buildStandardSettings(AgentProfileInterface $profile): array {
    return [
      'permission_mode' => 'default',
      'sandbox' => [
        'enabled' => TRUE,
        'network' => FALSE,
      ],
      'hooks' => [
        'mode' => 'enforced',
      ],
      'managed_settings' => [
        'disableBypassPermissionsMode' => TRUE,
        'allowManagedPermissionRulesOnly' => TRUE,
      ],
      'permission_rules' => [
        'allow' => array_merge(self::BRIDGE_PATTERNS, self::READ_PATTERNS),
        'deny' => [],
      ],
    ];
  }

  /**
   * Permissive tier: broad access with logging.
   */
  private function buildPermissiveSettings(AgentProfileInterface $profile): array {
    return [
      'permission_mode' => 'acceptEdits',
      'sandbox' => [
        'enabled' => FALSE,
      ],
      'hooks' => [
        'mode' => 'logging',
      ],
      'managed_settings' => [
        'disableBypassPermissionsMode' => TRUE,
      ],
      'permission_rules' => [
        'allow' => [],
        'deny' => self::DANGEROUS_PATTERNS,
      ],
    ];
  }

  /**
   * Custom tier: read all settings from profile's manual configuration.
   */
  private function buildCustomSettings(AgentProfileInterface $profile): array {
    $hookMode = $profile->getHookMode();
    return [
      'permission_mode' => $profile->getPermissionMode(),
      'sandbox' => [
        'enabled' => $profile->getSandbox(),
        'network' => $profile->getSandboxNetwork(),
      ],
      'hooks' => $hookMode !== '' ? ['mode' => $hookMode] : [],
      'managed_settings' => [
        'disableBypassPermissionsMode' => $profile->getDisableBypassMode(),
        'allowManagedPermissionRulesOnly' => $profile->getManagedRulesOnly(),
      ],
      'permission_rules' => [
        'allow' => $profile->getBashAllowPatterns(),
        'deny' => $profile->getBashDenyPatterns(),
      ],
      'allowed_tools' => $profile->getAllowedTools(),
      'denied_tools' => $profile->getDeniedTools(),
    ];
  }

}
