<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Process\Process;

final class ClaudeAgentSdkProcessLimiter {

  private const DEFAULT_LIMIT = 8;

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  public function getLimit(): int {
    $config = $this->configFactory->get('ai_claude_agent_sdk.settings');
    $value = $config ? $config->get('max_concurrent_cli') : null;
    if ($value === null || $value === '') {
      return self::DEFAULT_LIMIT;
    }
    return max(0, (int) $value);
  }

  public function getRunningCount(): int {
    try {
      $process = new Process(['pgrep', '-xc', 'claude']);
      $process->setTimeout(3);
      $process->run();
      if (!$process->isSuccessful()) {
        return 0;
      }
      $count = (int) trim($process->getOutput());
      return $count >= 0 ? $count : 0;
    }
    catch (\Throwable) {
      return 0;
    }
  }

  public function canStart(): bool {
    $limit = $this->getLimit();
    if ($limit <= 0) {
      return true;
    }
    return $this->getRunningCount() < $limit;
  }

  public function getStatus(): array {
    $limit = $this->getLimit();
    $running = $this->getRunningCount();
    return [
      'limit' => $limit,
      'running' => $running,
      'available' => $limit <= 0 ? null : max(0, $limit - $running),
      'can_start' => $limit <= 0 ? true : $running < $limit,
    ];
  }

}
