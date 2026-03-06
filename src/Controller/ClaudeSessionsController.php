<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Controller for the Claude Sessions page.
 */
class ClaudeSessionsController extends ControllerBase {

  /**
   * Renders the Claude Sessions page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $config = $this->config('ai_claude_agent_sdk.settings');
    $sidecarUrl = $config->get('sidecar_url') ?: 'http://localhost:3000';
    $apiBase = rtrim($sidecarUrl, '/');

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'claude-sessions-app'],
      '#attached' => [
        'library' => ['ai_claude_agent_sdk/sessions'],
        'drupalSettings' => [
          'claudeSessions' => [
            'apiBase' => $apiBase,
            'terminalUrl' => Url::fromRoute('ai_claude_agent_sdk.terminal')->toString(),
          ],
        ],
      ],
    ];
  }

}
