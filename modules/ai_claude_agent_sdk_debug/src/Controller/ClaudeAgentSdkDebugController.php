<?php

declare(strict_types=1);

namespace Drupal\claude_agent_sdk_debug\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkDebugController extends ControllerBase {

  public function page(string $mode = 'client'): array {
    return $this->formBuilder()->getForm('Drupal\\claude_agent_sdk_debug\\Form\\ClaudeAgentSdkDebugForm', $mode);
  }

}
