<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;

/**
 * Default policy evaluator for Claude Code tool calls.
 *
 * Evaluates tool calls against security tier policies with support for
 * per-profile command pattern overrides. Extend via service decoration.
 */
class PolicyEvaluator implements PolicyEvaluatorInterface {

  /**
   * Read-only tools allowed in all tiers.
   */
  protected const READ_ONLY_TOOLS = ['Read', 'Glob', 'Grep', 'LS'];

  /**
   * Write tools that require approval in standard tier.
   */
  protected const WRITE_TOOLS = ['Edit', 'Write', 'NotebookEdit'];

  /**
   * Bridge command prefixes (hardcoded, not profile-configurable).
   */
  protected const DEFAULT_BRIDGE_PREFIXES = [
    'drush ai-claude-agent-sdk:tool-run',
    'ddev drush ai-claude-agent-sdk:tool-run',
    'ddev exec drush ai-claude-agent-sdk:tool-run',
  ];

  /**
   * Default read helper command prefixes.
   */
  protected const DEFAULT_READ_HELPERS = [
    'ls', 'pwd', 'cat', 'head', 'tail', 'wc',
  ];

  /**
   * Default dangerous command patterns.
   */
  protected const DEFAULT_DANGEROUS_PATTERNS = [
    'rm -rf /',
    'rm -r /',
    'git push --force',
    'git reset --hard',
    'chmod -R 777',
    'dd if=',
    'mkfs',
    ':(){ :|:& };:',
    '> /dev/sd',
    'drush sql-drop',
    'drush site:install',
  ];

  /**
   * {@inheritdoc}
   */
  public function evaluate(string $toolName, array $toolInput, string $hookType, AgentProfileInterface $profile): PolicyDecision {
    if ($hookType === 'PostToolUse') {
      return PolicyDecision::allow('PostToolUse: logged for audit');
    }

    $tier = $profile->getSecurityTier();

    return match ($tier) {
      'strict' => $this->evaluateStrictPolicy($profile, $toolName, $toolInput),
      'standard' => $this->evaluateStandardPolicy($profile, $toolName, $toolInput),
      'permissive' => $this->evaluatePermissivePolicy($profile, $toolName, $toolInput),
      'custom' => $this->evaluateCustomPolicy($profile, $toolName, $toolInput),
      default => PolicyDecision::deny('Unknown tier: ' . $tier),
    };
  }

  /**
   * Strict policy: deny unless explicitly allowed.
   */
  protected function evaluateStrictPolicy(AgentProfileInterface $profile, string $toolName, array $toolInput): PolicyDecision {
    if (in_array($toolName, static::READ_ONLY_TOOLS, TRUE)) {
      return PolicyDecision::allow('Strict: read-only tool allowed');
    }

    if ($toolName === 'Bash') {
      $command = $toolInput['command'] ?? '';
      if ($this->isBridgeCommand($command)) {
        return PolicyDecision::allow('Strict: bridge command allowed');
      }
      return PolicyDecision::deny('Strict: only bridge Bash commands are allowed');
    }

    return PolicyDecision::deny('Strict: tool not in allowlist');
  }

  /**
   * Standard policy: allow reads, resolve writes by directory boundary.
   */
  protected function evaluateStandardPolicy(AgentProfileInterface $profile, string $toolName, array $toolInput): PolicyDecision {
    if (in_array($toolName, static::READ_ONLY_TOOLS, TRUE)) {
      return PolicyDecision::allow('Standard: read-only tool allowed');
    }

    if ($toolName === 'Bash') {
      $command = $toolInput['command'] ?? '';
      if ($this->isBridgeCommand($command)) {
        return PolicyDecision::allow('Standard: bridge command allowed');
      }
      if ($this->isReadHelper($command, $profile)) {
        return PolicyDecision::allow('Standard: read helper command allowed');
      }
      return $this->resolveAskByBoundary(
        $this->extractPathsFromCommand($command),
        $profile,
        'Standard: Bash command',
      );
    }

    if (in_array($toolName, static::WRITE_TOOLS, TRUE)) {
      $filePath = $toolInput['file_path'] ?? '';
      return $this->resolveAskByBoundary(
        $filePath !== '' ? [$filePath] : [],
        $profile,
        'Standard: write operation',
      );
    }

    return PolicyDecision::deny('Standard: unknown tool denied (no interactive channel)', wasAsk: TRUE);
  }

  /**
   * Permissive policy: allow and log, deny only dangerous commands.
   */
  protected function evaluatePermissivePolicy(AgentProfileInterface $profile, string $toolName, array $toolInput): PolicyDecision {
    if ($toolName === 'Bash') {
      $command = $toolInput['command'] ?? '';
      if ($this->isDangerousCommand($command, $profile)) {
        return PolicyDecision::deny('Permissive: dangerous command blocked');
      }
    }

    return PolicyDecision::allow('Permissive: allowed (logged)');
  }

  /**
   * Custom policy: uses profile's explicit allowlist/denylist.
   */
  protected function evaluateCustomPolicy(AgentProfileInterface $profile, string $toolName, array $toolInput): PolicyDecision {
    $allowed = $profile->getAllowedTools();
    $denied = $profile->getDeniedTools();

    if (!empty($denied) && in_array($toolName, $denied, TRUE)) {
      return PolicyDecision::deny('Custom: tool in deny list');
    }

    if (!empty($allowed) && !in_array($toolName, $allowed, TRUE)) {
      return PolicyDecision::deny('Custom: tool not in allow list');
    }

    return PolicyDecision::allow('Custom: tool allowed');
  }

  /**
   * Checks if a Bash command is a bridge command.
   *
   * Bridge commands are hardcoded — not configurable per profile.
   */
  protected function isBridgeCommand(string $command): bool {
    $trimmed = trim($command);
    foreach (static::DEFAULT_BRIDGE_PREFIXES as $prefix) {
      if (str_starts_with($trimmed, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if a Bash command is a safe read helper.
   *
   * Uses hardcoded defaults plus any freeform patterns from the profile's
   * bash_allow_patterns field.
   */
  protected function isReadHelper(string $command, AgentProfileInterface $profile): bool {
    $trimmed = trim($command);

    foreach (static::DEFAULT_READ_HELPERS as $prefix) {
      if ($trimmed === $prefix || str_starts_with($trimmed, $prefix . ' ')) {
        return TRUE;
      }
    }

    foreach ($profile->getBashAllowPatterns() as $pattern) {
      if ($trimmed === $pattern || str_starts_with($trimmed, $pattern . ' ') || str_contains($trimmed, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if a Bash command matches known dangerous patterns.
   *
   * Uses hardcoded defaults plus any freeform patterns from the profile's
   * bash_deny_patterns field.
   */
  protected function isDangerousCommand(string $command, AgentProfileInterface $profile): bool {
    $trimmed = trim($command);

    foreach (static::DEFAULT_DANGEROUS_PATTERNS as $pattern) {
      if (str_contains($trimmed, $pattern)) {
        return TRUE;
      }
    }

    foreach ($profile->getBashDenyPatterns() as $pattern) {
      if (str_contains($trimmed, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves an ask-equivalent decision by directory boundary.
   *
   * If all paths are within allowed boundaries, returns allow with wasAsk=TRUE.
   * If any path is outside, returns deny with wasAsk=TRUE.
   * If no paths are given, returns allow (assumes within working directory).
   */
  protected function resolveAskByBoundary(array $paths, AgentProfileInterface $profile, string $context): PolicyDecision {
    if (empty($paths)) {
      return PolicyDecision::allow($context . ' auto-approved: no paths outside working directory', wasAsk: TRUE);
    }
    foreach ($paths as $path) {
      if (!$this->isWithinAllowedBoundaries($path, $profile)) {
        return PolicyDecision::deny($context . ' auto-denied: path outside allowed directories (' . $path . ')', wasAsk: TRUE);
      }
    }
    return PolicyDecision::allow($context . ' auto-approved: within allowed directories', wasAsk: TRUE);
  }

  /**
   * Checks whether a path is within the profile's allowed boundaries.
   *
   * A path is allowed if it equals or is a subdirectory of the working
   * directory or any of the profile's explicitly allowed directories.
   */
  protected function isWithinAllowedBoundaries(string $path, AgentProfileInterface $profile): bool {
    // Reject paths with directory traversal segments.
    if (str_contains($path, '..')) {
      return FALSE;
    }

    $resolved = rtrim($path, '/');
    $workingDir = rtrim($profile->getWorkingDirectory(), '/');
    $boundaries = [$workingDir];
    foreach ($profile->getAllowedDirectories() as $dir) {
      $boundaries[] = rtrim($dir, '/');
    }
    foreach ($boundaries as $boundary) {
      if ($boundary === '') {
        continue;
      }
      if ($resolved === $boundary || str_starts_with($resolved, $boundary . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts absolute paths from a shell command string.
   *
   * Uses a simple regex to find tokens starting with '/' that are not
   * shell operators. Used to determine boundary compliance for Bash commands.
   */
  protected function extractPathsFromCommand(string $command): array {
    $paths = [];
    if (preg_match_all('#(?:^|\s)(/[^\s;|&>\'\"]+)#', $command, $matches)) {
      $paths = $matches[1];
    }
    return $paths;
  }

}
