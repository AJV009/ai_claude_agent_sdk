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
   * Stream SSE chunks from the sidecar using raw SDK options (camelCase).
   *
   * Unlike stream(), this accepts options in SDK format directly rather than
   * profile data. Used by the debug module which already builds SDK-format
   * options.
   *
   * @param string $prompt
   *   The user prompt.
   * @param array $sdkOptions
   *   SDK options in camelCase format (e.g. maxTurns, systemPrompt).
   * @param callable $onChunk
   *   Called with each raw SSE chunk.
   */
  public function streamDirect(string $prompt, array $sdkOptions, callable $onChunk): void {
    $url = $this->getSidecarUrl() . '/api/query';

    // Inject authentication env vars.
    $authEnv = $this->authEnvResolver->buildEnv($sdkOptions['env'] ?? []);
    if (!empty($authEnv)) {
      $sdkOptions['env'] = $authEnv;
    }

    $payload = json_encode(array_filter([
      'prompt' => $prompt,
      'options' => $sdkOptions ?: NULL,
    ], fn($v) => $v !== NULL && $v !== ''), JSON_THROW_ON_ERROR);

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
   * Collect the full response text using raw SDK options (camelCase).
   *
   * @param string $prompt
   *   The user prompt.
   * @param array $sdkOptions
   *   SDK options in camelCase format.
   *
   * @return string
   *   The accumulated response text.
   */
  public function collectResponseDirect(string $prompt, array $sdkOptions = [], array &$metadata = []): string {
    $resultText = '';
    $buffer = '';

    $this->streamDirect($prompt, $sdkOptions, function (string $data) use (&$resultText, &$buffer, &$metadata) {
      $buffer .= $data;
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

        if (isset($decoded['session_id']) && is_string($decoded['session_id']) && $decoded['session_id'] !== '') {
          $metadata['session_id'] = $decoded['session_id'];
        }

        if ($type === 'result' && $subtype === 'success' && isset($decoded['result'])) {
          $resultText = $decoded['result'];
        }

        if ($type === 'result' && str_starts_with($subtype, 'error_')) {
          $errors = $decoded['errors'] ?? [$subtype];
          throw new \RuntimeException('Claude query error: ' . implode('; ', $errors));
        }

        if ($type === 'error' && isset($decoded['message'])) {
          throw new \RuntimeException('Sidecar error: ' . $decoded['message']);
        }
      }
    });

    return trim($resultText);
  }

  /**
   * Fire-and-forget a background query to the sidecar.
   *
   * @param array $profile
   *   Profile data from AgentProfile::toSidecarFormat().
   * @param string $prompt
   *   The user prompt.
   * @param array $mcpHeaders
   *   Optional headers to inject into MCP server configs.
   * @param array $metadata
   *   Optional metadata (skillId, initiatorUid) passed to sidecar.
   *
   * @return string
   *   The queryId assigned by the sidecar.
   */
  public function fireAndForget(array $profile, string $prompt, array $mcpHeaders = [], array $metadata = []): string {
    $payload = $this->buildRequestPayload($profile, $prompt, NULL, $mcpHeaders);
    $payload['background'] = TRUE;
    if (!empty($metadata['skillId'])) {
      $payload['skillId'] = $metadata['skillId'];
    }
    if (!empty($metadata['initiatorUid'])) {
      $payload['initiatorUid'] = $metadata['initiatorUid'];
    }
    if (!empty($metadata['taskId'])) {
      $payload['taskId'] = $metadata['taskId'];
    }

    $url = $this->getSidecarUrl() . '/api/query';
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $encoded,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream'],
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === FALSE && $errno !== 0) {
      throw new \RuntimeException('Sidecar connection failed: ' . $error);
    }

    // Parse SSE response to extract queryId from query_start event.
    $queryId = '';
    $lines = explode("\n", (string) $result);
    foreach ($lines as $line) {
      if (!str_starts_with($line, 'data: ')) {
        continue;
      }
      $data = substr($line, 6);
      if ($data === '[DONE]') {
        continue;
      }
      $decoded = json_decode($data, TRUE);
      if (is_array($decoded) && ($decoded['type'] ?? '') === 'query_start' && !empty($decoded['queryId'])) {
        $queryId = $decoded['queryId'];
        break;
      }
    }

    if ($queryId === '') {
      throw new \RuntimeException('Failed to get queryId from sidecar background query');
    }

    return $queryId;
  }

  /**
   * Abort a running background query.
   *
   * @param string $queryId
   *   The query ID to abort.
   *
   * @return bool
   *   TRUE if the query was aborted successfully.
   */
  public function abortQuery(string $queryId): bool {
    $result = $this->sidecarPost('/api/queries/' . urlencode($queryId) . '/abort');
    return !empty($result['ok']);
  }

  /**
   * Fetch sessions from the sidecar.
   *
   * @param int $limit
   *   Maximum number of sessions to return.
   *
   * @return array
   *   Decoded JSON response with sessions.
   */
  public function fetchSessions(int $limit = 50): array {
    return $this->sidecarGet('/api/sessions?limit=' . $limit);
  }

  /**
   * Fetch active sessions from the sidecar.
   *
   * @return array
   *   Decoded JSON response with active sessions.
   */
  public function fetchActiveSessions(): array {
    return $this->sidecarGet('/api/sessions/active');
  }

  /**
   * Fetch messages for a specific session.
   *
   * @param string $sessionId
   *   The session ID.
   *
   * @return array
   *   Decoded JSON response with session messages.
   */
  public function fetchSessionMessages(string $sessionId): array {
    return $this->sidecarGet('/api/sessions/' . urlencode($sessionId));
  }

  /**
   * Fetch background queries from the sidecar.
   *
   * @return array
   *   Decoded JSON response with queries.
   */
  public function fetchQueries(): array {
    $response = $this->sidecarGet('/api/queries');
    return $response['queries'] ?? [];
  }

  /**
   * Perform a GET request to the sidecar.
   *
   * @param string $path
   *   The API path (e.g. '/api/sessions').
   *
   * @return array
   *   Decoded JSON response or empty array on failure.
   */
  private function sidecarGet(string $path): array {
    $url = $this->getSidecarUrl() . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($result === FALSE || $errno) {
      throw new \RuntimeException('Sidecar connection failed');
    }

    return json_decode((string) $result, TRUE) ?: [];
  }

  /**
   * Perform a POST request to the sidecar.
   *
   * @param string $path
   *   The API path.
   * @param array $body
   *   Optional request body.
   *
   * @return array
   *   Decoded JSON response or empty array on failure.
   */
  private function sidecarPost(string $path, array $body = []): array {
    $url = $this->getSidecarUrl() . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => json_encode($body),
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === FALSE) {
      return [];
    }

    return json_decode((string) $result, TRUE) ?: [];
  }

  /**
   * Get the sidecar base URL from configuration.
   */
  private function getSidecarUrl(): string {
    $config = $this->configFactory->get('ai_claude_agent_sdk.settings');
    return $config->get('sidecar_url') ?: 'http://localhost:3000';
  }

}
