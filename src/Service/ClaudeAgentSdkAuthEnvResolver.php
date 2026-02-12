<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Resolves ANTHROPIC_API_KEY environment values for Claude SDK execution.
 */
final class ClaudeAgentSdkAuthEnvResolver {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Build env values for Claude CLI, merged with any caller overrides.
   *
   * @param array $overrides
   *   Explicit runtime overrides from callers (for example debug form JSON).
   *
   * @return array
   *   Environment values to pass to the SDK transport.
   */
  public function buildEnv(array $overrides = []): array {
    $config = $this->configFactory->get('ai_claude_agent_sdk.settings');
    $source = (string) ($config->get('api_key_source') ?? 'environment');
    $envVar = (string) ($config->get('api_key_env_var') ?? 'ANTHROPIC_API_KEY');
    if ($envVar === '') {
      $envVar = 'ANTHROPIC_API_KEY';
    }

    $resolved = [];

    if ($source === 'key') {
      $keyId = (string) ($config->get('api_key_key') ?? '');
      if ($keyId !== '') {
        $key = $this->keyRepository->getKey($keyId);
        if ($key) {
          $value = $key->getKeyValue();
          if (is_string($value) && $value !== '') {
            $resolved['ANTHROPIC_API_KEY'] = $value;
          }
        }
      }
    }
    else {
      $value = getenv($envVar);
      if (is_string($value) && $value !== '') {
        // Claude CLI expects ANTHROPIC_API_KEY; copy from configured env var.
        $resolved['ANTHROPIC_API_KEY'] = $value;
        if ($envVar !== 'ANTHROPIC_API_KEY') {
          $resolved[$envVar] = $value;
        }
      }
    }

    return array_merge($resolved, $overrides);
  }

}
