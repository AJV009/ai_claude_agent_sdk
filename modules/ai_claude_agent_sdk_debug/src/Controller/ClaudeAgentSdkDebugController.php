<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

final class ClaudeAgentSdkDebugController extends ControllerBase {

  private const VALID_MODES = ['client', 'query', 'session_query', 'terminal'];

  public function page(Request $request): array {
    $mode = $request->query->get('mode', 'client');
    if (!in_array($mode, self::VALID_MODES, TRUE)) {
      $mode = 'client';
    }
    return $this->formBuilder()->getForm('Drupal\\ai_claude_agent_sdk_debug\\Form\\ClaudeAgentSdkDebugForm', $mode);
  }

}
