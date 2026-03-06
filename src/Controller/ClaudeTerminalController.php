<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkAuthEnvResolver;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Claude Terminal page.
 */
class ClaudeTerminalController extends ControllerBase {

  public function __construct(
    private readonly ClaudeAgentSdkAuthEnvResolver $authEnvResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.auth_env_resolver'),
    );
  }

  /**
   * Renders the Claude Terminal page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $config = $this->config('ai_claude_agent_sdk.settings');

    $profiles = [];
    $defaultProfile = '';
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface[] $entities */
    $entities = $this->entityTypeManager()->getStorage('agent_profile')->loadMultiple();
    foreach ($entities as $profile) {
      $profiles[$profile->id()] = [
        'label' => $profile->label(),
        'config' => $profile->toSidecarFormat(),
      ];
      if ($defaultProfile === '') {
        $defaultProfile = $profile->id();
      }
    }

    $sidecarUrl = $config->get('sidecar_url') ?: 'http://localhost:3000';
    // Strip trailing slash for consistent JS usage.
    $apiBase = rtrim($sidecarUrl, '/');

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'claude-terminal-wrapper',
        'class' => ['claude-terminal-fullpage'],
      ],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'claude-terminal-toolbar',
        ],
      ],
      'terminal' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'claude-terminal',
        ],
      ],
      'status' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'claude-terminal-status',
        ],
        '#markup' => '<span class="claude-status-dot disconnected"></span> Disconnected',
      ],
      '#attached' => [
        'library' => ['ai_claude_agent_sdk/terminal'],
        'drupalSettings' => [
          'claudeTerminal' => [
            'wsUrl' => $config->get('sidecar_ws_url') ?: '',
            'apiBase' => $apiBase,
            'profiles' => $profiles,
            'defaultProfile' => $defaultProfile,
            'env' => $this->authEnvResolver->buildEnv(),
          ],
        ],
      ],
    ];
  }

}
