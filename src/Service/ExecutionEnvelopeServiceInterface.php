<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;

/**
 * Interface for the execution envelope service.
 */
interface ExecutionEnvelopeServiceInterface {

  /**
   * Creates an execution envelope for a profile run.
   *
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile.
   * @param int|null $initiatorUid
   *   Optional initiator user ID.
   * @param string $modality
   *   Execution modality (interactive, background, outside_in).
   *
   * @return array
   *   The envelope data array.
   */
  public function create(AgentProfileInterface $profile, ?int $initiatorUid = NULL, string $modality = 'interactive'): array;

  /**
   * Gets an envelope by run ID from temp store.
   *
   * @param string $runId
   *   The run ID.
   *
   * @return array|null
   *   The envelope or NULL.
   */
  public function get(string $runId): ?array;

  /**
   * Builds MCP headers from an envelope.
   *
   * @param array $envelope
   *   The envelope data.
   *
   * @return array
   *   Headers array.
   */
  public function buildMcpHeaders(array $envelope): array;

}
