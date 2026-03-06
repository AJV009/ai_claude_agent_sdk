<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Drupal\Core\Controller\ControllerBase;

final class ClaudeAgentSdkDebugController extends ControllerBase {

  public function page(string $mode = 'client'): array {
    return $this->formBuilder()->getForm('Drupal\\ai_claude_agent_sdk_debug\\Form\\ClaudeAgentSdkDebugForm', $mode);
  }

}
