<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Bridge service that communicates with the sidecar's /api/query SSE endpoint.
 *
 * Uses the JS SDK's query() function for structured JSON message streaming.
 */
final class ClaudeBridgeService {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    private readonly ClaudeAgentSdkAuthEnvResolver $authEnvResolver,
  ) {}

  /**
   * Stream SSE chunks from the sidecar, passing each to a callback.
   *
   * @param array $profile
   *   Profile data from AgentProfile::toSidecarFormat().
   * @param string $prompt
   *   The user prompt.
   * @param string|null $resume
   *   Optional session ID to resume.
   * @param callable $onChunk
   *   Called with each raw SSE line (including "data: " prefix).
   * @param array $mcpHeaders
   *   Optional headers to inject into MCP server configs.
   * @param bool $interactivePermissions
   *   Whether to enable interactive permission prompts via SSE.
   * @param int $permissionTimeoutMs
   *   Timeout in milliseconds for permission requests.
   */
  public function stream(array $profile, string $prompt, ?string $resume, callable $onChunk, array $mcpHeaders = [], bool $interactivePermissions = FALSE, int $permissionTimeoutMs = 120000): void {
    $url = $this->getSidecarUrl() . '/api/query';
    $payload = json_encode($this->buildRequestPayload($profile, $prompt, $resume, $mcpHeaders, $interactivePermissions, $permissionTimeoutMs), JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream'],
      CURLOPT_RETURNTRANSFER => FALSE,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
        $written = strlen($data);
        $onChunk($data);
        if (connection_aborted()) {
          return 0;
        }
        return $written;
      },
    ]);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === FALSE && $errno !== 0) {
      throw new \RuntimeException('Sidecar connection failed: ' . $error);
    }
  }

  /**
   * Collect the full response text from a streaming session.
   *
   * @param array $profile
   *   Profile data from AgentProfile::toSidecarFormat().
   * @param string $prompt
   *   The user prompt.
   * @param string|null $resume
   *   Optional session ID to resume.
   * @param array $mcpHeaders
   *   Optional headers to inject into MCP server configs.
   *
   * @return string
   *   The accumulated response text.
   */
  public function collectResponse(array $profile, string $prompt, ?string $resume = NULL, array $mcpHeaders = []): string {
    $resultText = '';
    $buffer = '';

    $this->stream($profile, $prompt, $resume, function (string $data) use (&$resultText, &$buffer) {
      $buffer .= $data;
      // Process complete SSE lines.
      while (($pos = strpos($buffer, "\n")) !== FALSE) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);

        if (!str_starts_with($line, 'data: ')) {
          continue;
        }
        $payload = substr($line, 6);
        if ($payload === '[DONE]') {
          continue;
        }
        $decoded = json_decode($payload, TRUE);
        if (!is_array($decoded)) {
          continue;
        }

        $type = $decoded['type'] ?? '';
        $subtype = $decoded['subtype'] ?? '';

        // SDK result message with success — extract the result text.
        if ($type === 'result' && $subtype === 'success' && isset($decoded['result'])) {
          $resultText = $decoded['result'];
        }

        // SDK result message with error — throw.
        if ($type === 'result' && str_starts_with($subtype, 'error_')) {
          $errors = $decoded['errors'] ?? [$subtype];
          throw new \RuntimeException('Claude query error: ' . implode('; ', $errors));
        }

        // Sidecar-level error (before query starts).
        if ($type === 'error' && isset($decoded['message'])) {
          throw new \RuntimeException('Sidecar error: ' . $decoded['message']);
        }
      }
    }, $mcpHeaders);

    return trim($resultText);
  }

  /**
   * Send a permission response to the sidecar.
   *
   * @param string $queryId
   *   The query ID from the query_start SSE event.
   * @param string $requestId
   *   The request ID from the permission_request SSE event.
   * @param string $behavior
   *   Either 'allow' or 'deny'.
   * @param string|null $message
   *   Optional message for the permission decision.
   *
   * @return array
   *   Array with keys: success, http_code, body.
   */
  public function sendPermissionResponse(string $queryId, string $requestId, string $behavior, ?string $message = NULL): array {
    $url = $this->getSidecarUrl() . '/api/query/permission-response';
    $payload = json_encode(array_filter([
      'queryId' => $queryId,
      'requestId' => $requestId,
      'behavior' => $behavior,
      'message' => $message,
    ], fn($v) => $v !== NULL), JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = is_string($result) ? (json_decode($result, TRUE) ?? []) : [];

    return [
      'success' => $httpCode === 200,
      'http_code' => $httpCode,
      'body' => $body,
    ];
  }

  /**
   * Build the request payload for the sidecar /api/query endpoint.
   *
   * @param array $profile
   *   Profile data from AgentProfile::toSidecarFormat().
   * @param string $prompt
   *   The user prompt.
   * @param string|null $resume
   *   Optional session ID to resume.
   *
   * @return array
   *   The request payload.
   */
  private function buildRequestPayload(array $profile, string $prompt, ?string $resume, array $mcpHeaders = [], bool $interactivePermissions = FALSE, int $permissionTimeoutMs = 120000): array {
    $options = [];

    // Map profile fields to SDK option names.
    $fieldMap = [
      'system_prompt' => 'systemPrompt',
      'model' => 'model',
      'permission_mode' => 'permissionMode',
      'max_turns' => 'maxTurns',
      'allowed_tools' => 'allowedTools',
      'denied_tools' => 'disallowedTools',
      'working_directory' => 'cwd',
      'allowed_directories' => 'additionalDirectories',
    ];

    foreach ($fieldMap as $profileKey => $sdkKey) {
      if (!empty($profile[$profileKey])) {
        $options[$sdkKey] = $profile[$profileKey];
      }
    }

    // Sandbox: bool -> { enabled: true }.
    if (!empty($profile['sandbox'])) {
      $options['sandbox'] = ['enabled' => TRUE];
    }

    // MCP servers: [{name, transport, url}] -> Record<string, {type, url}>.
    if (!empty($profile['mcp_servers']) && is_array($profile['mcp_servers'])) {
      $mcpServers = [];
      foreach ($profile['mcp_servers'] as $server) {
        if (isset($server['name'], $server['url'])) {
          $config = [
            'type' => $server['transport'] ?? 'http',
            'url' => $server['url'],
          ];
          $existingHeaders = $server['headers'] ?? [];
          if (!empty($mcpHeaders)) {
            $existingHeaders = array_merge($existingHeaders, $mcpHeaders);
          }
          if (!empty($existingHeaders)) {
            $config['headers'] = $existingHeaders;
          }
          $mcpServers[$server['name']] = $config;
        }
      }
      if (!empty($mcpServers)) {
        $options['mcpServers'] = $mcpServers;
      }
    }

    if ($resume !== NULL && $resume !== '') {
      $options['resume'] = $resume;
    }

    // Inject authentication env vars.
    $authEnv = $this->authEnvResolver->buildEnv();
    if (!empty($authEnv)) {
      $options['env'] = $authEnv;
    }

    // Interactive permission propagation.
    if ($interactivePermissions) {
      $options['interactivePermissions'] = TRUE;
      $options['permissionTimeoutMs'] = $permissionTimeoutMs;
    }

    return array_filter([
      'prompt' => $prompt,
      'options' => $options ?: NULL,
    ], fn($v) => $v !== NULL && $v !== '');
  }

  /**
   * Get the sidecar base URL from configuration.
   */
  private function getSidecarUrl(): string {
    $config = $this->configFactory->get('ai_claude_agent_sdk.settings');
    return $config->get('sidecar_url') ?: 'http://localhost:3000';
  }

}
