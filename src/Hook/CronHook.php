<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Hook;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillScheduler;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Implements hook_cron().
 */
#[Hook('cron')]
class CronHook {

  public function __construct(
    #[Autowire(service: 'ai_claude_agent_sdk.scheduler')]
    private readonly AgentSkillScheduler $scheduler,
  ) {}

  /**
   * Implements hook_cron().
   */
  public function __invoke(): void {
    $this->scheduler->runCron();
  }

}
