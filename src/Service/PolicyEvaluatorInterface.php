<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;

/**
 * Evaluates tool calls against agent profile security tier policies.
 *
 * Implementations can be swapped via Drupal service decoration to customize
 * evaluation logic for contrib modules or specific site requirements.
 */
interface PolicyEvaluatorInterface {

  /**
   * Evaluates a tool call against a profile's security tier policy.
   *
   * @param string $toolName
   *   The tool being invoked (e.g., 'Bash', 'Read', 'Edit').
   * @param array $toolInput
   *   The tool's input parameters.
   * @param string $hookType
   *   'PreToolUse' or 'PostToolUse'.
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile (provides tier + overrides).
   *
   * @return \Drupal\ai_claude_agent_sdk\Service\PolicyDecision
   *   Immutable decision with reason.
   */
  public function evaluate(string $toolName, array $toolInput, string $hookType, AgentProfileInterface $profile): PolicyDecision;

}
