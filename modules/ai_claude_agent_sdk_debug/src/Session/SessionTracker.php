<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Session;

use Drupal\Core\State\StateInterface;

final class SessionTracker {

  private const STATE_KEY = 'ai_claude_agent_sdk_debug.sessions';
  private const MAX_SESSIONS = 100;

  public function __construct(private readonly StateInterface $state) {}

  public function record(string $sessionId, array $metadata = []): void {
    $sessionId = trim($sessionId);
    if ($sessionId === '' || $sessionId === 'default') {
      return;
    }

    $sessions = $this->state->get(self::STATE_KEY, []);
    if (!is_array($sessions)) {
      $sessions = [];
    }

    $now = time();
    $existing = $sessions[$sessionId] ?? [];

    $sessions[$sessionId] = [
      'session_id' => $sessionId,
      'first_seen' => $existing['first_seen'] ?? $now,
      'last_seen' => $now,
      'mode' => $metadata['mode'] ?? ($existing['mode'] ?? null),
      'source' => $metadata['source'] ?? ($existing['source'] ?? null),
      'resume' => $metadata['resume'] ?? ($existing['resume'] ?? null),
    ];

    $sessions = $this->prune($sessions);
    $this->state->set(self::STATE_KEY, $sessions);
  }

  public function all(): array {
    $sessions = $this->state->get(self::STATE_KEY, []);
    if (!is_array($sessions)) {
      return [];
    }

    uasort($sessions, function (array $a, array $b): int {
      return ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0);
    });

    return array_values($sessions);
  }

  private function prune(array $sessions): array {
    if (count($sessions) <= self::MAX_SESSIONS) {
      return $sessions;
    }

    uasort($sessions, function (array $a, array $b): int {
      return ($a['last_seen'] ?? 0) <=> ($b['last_seen'] ?? 0);
    });

    $excess = count($sessions) - self::MAX_SESSIONS;
    foreach (array_keys($sessions) as $sessionId) {
      if ($excess <= 0) {
        break;
      }
      unset($sessions[$sessionId]);
      $excess--;
    }

    return $sessions;
  }

}
