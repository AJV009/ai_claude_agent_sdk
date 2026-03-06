<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Creates and manages execution envelopes that track who owns MCP operations.
 */
final class ExecutionEnvelopeService {

  private const TEMPSTORE_COLLECTION = 'ai_claude_agent_sdk.execution';

  public function __construct(
    private readonly SharedTempStoreFactory $tempStoreFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Create an execution envelope for a Claude session.
   *
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile.
   * @param int|null $initiatorUid
   *   The UID of the user who initiated the request. Defaults to current user.
   *
   * @return array
   *   The envelope data with keys: run_id, profile_id, executor_uid,
   *   initiator_uid, modality, created.
   */
  public function create(AgentProfileInterface $profile, ?int $initiatorUid = NULL): array {
    $runId = $this->uuid->generate();
    $initiatorUid = $initiatorUid ?? (int) $this->currentUser->id();

    $executorUid = $profile->getExecutorUid();
    if ($executorUid === 0) {
      $executorUid = (int) $this->currentUser->id();
    }

    $envelope = [
      'run_id' => $runId,
      'profile_id' => $profile->id(),
      'executor_uid' => $executorUid,
      'initiator_uid' => $initiatorUid,
      'modality' => $profile->getExecutionModality() ?: 'interactive',
      'created' => time(),
    ];

    $store = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION);
    $store->set($runId, $envelope);

    return $envelope;
  }

  /**
   * Retrieve an execution envelope by run ID.
   *
   * @param string $runId
   *   The run ID.
   *
   * @return array|null
   *   The envelope data, or NULL if not found.
   */
  public function get(string $runId): ?array {
    $store = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION);
    $data = $store->get($runId);
    return is_array($data) ? $data : NULL;
  }

  /**
   * Build MCP headers from an execution envelope.
   *
   * @param array $envelope
   *   The envelope data from create().
   *
   * @return array
   *   Associative array of header name => value.
   */
  public function buildMcpHeaders(array $envelope): array {
    return [
      'X-AI-Run-ID' => $envelope['run_id'],
      'X-AI-Executor-UID' => (string) $envelope['executor_uid'],
      'X-AI-Initiator-UID' => (string) $envelope['initiator_uid'],
      'X-AI-Modality' => $envelope['modality'],
    ];
  }

}
