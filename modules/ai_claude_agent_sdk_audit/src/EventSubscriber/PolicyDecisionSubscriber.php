<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_audit\EventSubscriber;

use Drupal\ai_claude_agent_sdk\Event\PolicyDecisionEvent;
use Drupal\ai_claude_agent_sdk_audit\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to policy decision events and writes to the audit log table.
 */
final class PolicyDecisionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AuditLogger $auditLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PolicyDecisionEvent::class => 'onPolicyDecision'];
  }

  /**
   * Records a policy decision in the audit log.
   */
  public function onPolicyDecision(PolicyDecisionEvent $event): void {
    $this->auditLogger->log([
      'profile_id' => $event->getProfileId(),
      'query_id' => $event->getQueryId(),
      'run_id' => $event->getRunId(),
      'executor_uid' => $event->getExecutorUid(),
      'tool_name' => $event->getToolName(),
      'tool_input' => $event->getToolInput(),
      'decision' => $event->getDecision(),
      'reason' => $event->getReason(),
      'tier' => $event->getTier(),
    ]);
  }

}
