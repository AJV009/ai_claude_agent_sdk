<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Drupal\Core\Access\CsrfTokenGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;

final class ClaudeAgentSdkDebugProcessController extends ControllerBase {

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly CsrfTokenGenerator $csrfTokenGenerator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('csrf_token')
    );
  }

  public function page(): array {
    $token = $this->csrfTokenGenerator->get('ai_claude_agent_sdk_debug.kill');

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['claude-agent-sdk-processes-page']],
      'intro' => [
        '#type' => 'markup',
        '#markup' => $this->t('This page shows running <code>claude</code> CLI processes inside the container, plus sessions captured from debug requests. Sessions are tracked from SDK responses and may not map to a specific OS process.'),
      ],
      'table' => [
        '#type' => 'container',
        '#attributes' => ['id' => 'claude-agent-sdk-processes-table'],
      ],
      '#attached' => [
        'library' => [
          'ai_claude_agent_sdk_debug/processes',
        ],
        'drupalSettings' => [
          'claudeAgentSdkProcesses' => [
            'dataUrl' => Url::fromRoute('ai_claude_agent_sdk_debug.processes_data')->toString(),
            'killUrl' => Url::fromRoute('ai_claude_agent_sdk_debug.processes_kill')->toString(),
            'killToken' => $token,
          ],
        ],
      ],
    ];
  }

  public function data(): JsonResponse {
    $rows = [];
    $count = 0;
    try {
      $process = new Process(['ps', '-eo', 'pid,etime,pcpu,pmem,command']);
      $process->setTimeout(3);
      $process->run();

      if (!$process->isSuccessful()) {
        return new JsonResponse([
          'error' => 'Failed to run ps command.',
        ], 500);
      }

      $lines = preg_split('/\r\n|\r|\n/', trim($process->getOutput()));
      foreach ($lines as $index => $line) {
        if ($index === 0) {
          continue;
        }
        if (stripos($line, 'claude') === false) {
          continue;
        }
        if (stripos($line, 'grep') !== false) {
          continue;
        }

        $parts = preg_split('/\s+/', trim($line), 5);
        if (count($parts) < 5) {
          continue;
        }

        $rows[] = [
          'pid' => $parts[0],
          'etime' => $parts[1],
          'cpu' => $parts[2],
          'mem' => $parts[3],
          'command' => $parts[4],
        ];
      }

      $count = count($rows);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }

    $sessions = $this->sessionTracker->all();
    $sessions = array_map(function (array $session): array {
      $sessionId = (string) ($session['session_id'] ?? '');
      if ($sessionId !== '') {
        $session['view_url'] = Url::fromRoute('ai_claude_agent_sdk_debug.session_view', ['session_id' => $sessionId])->toString();
        $session['delete_url'] = Url::fromRoute('ai_claude_agent_sdk_debug.session_delete_confirm', ['session_id' => $sessionId])->toString();
      }
      return $session;
    }, $sessions);

    return new JsonResponse([
      'rows' => $rows,
      'count' => $count,
      'timestamp' => time(),
      'sessions' => $sessions,
      'sessions_count' => count($sessions),
    ]);
  }

  public function kill(): JsonResponse {
    $before = $this->countClaudeProcesses();
    $killed = 0;
    try {
      $process = new Process(['pkill', '-x', 'claude']);
      $process->setTimeout(3);
      $process->run();
      $killed = $process->isSuccessful() ? $before : 0;
    }
    catch (\Throwable) {
      $killed = 0;
    }

    $after = $this->countClaudeProcesses();

    return new JsonResponse([
      'killed' => $killed,
      'before' => $before,
      'after' => $after,
    ]);
  }

  private function countClaudeProcesses(): int {
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

}
