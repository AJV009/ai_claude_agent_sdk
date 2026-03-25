<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Controller\ClaudePolicyController;
use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Bridge service that communicates with the sidecar's /api/query SSE endpoint.
 *
 * Uses the JS SDK's query() function for structured JSON message streaming.
 */
final class ClaudeBridgeService implements ClaudeBridgeServiceInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    private readonly ClaudeAgentSdkAuthEnvResolver $authEnvResolver,
    private readonly SecurityTierManager $tierManager,
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
  public function stream(array $profile, string $prompt, ?string $resume, callable $onChunk, array $mcpHeaders = [], bool $interactivePermissions = FALSE, int $permissionTimeoutMs = 120000, ?int $executorUid = NULL): void {
    $url = $this->getSidecarUrl() . '/api/query';
    $payload = json_encode($this->buildRequestPayload($profile, $prompt, $resume, $mcpHeaders, $interactivePermissions, $permissionTimeoutMs, $executorUid), JSON_THROW_ON_ERROR);

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
  public function collectResponse(array $profile, string $prompt, ?string $resume = NULL, array $mcpHeaders = [], ?int $executorUid = NULL, array &$metadata = []): string {
    $resultText = '';
    $buffer = '';

    $this->stream($profile, $prompt, $resume, function (string $data) use (&$resultText, &$buffer, &$metadata) {
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

        // Capture session_id for multi-turn conversation resumption.
        if (isset($decoded['session_id']) && is_string($decoded['session_id']) && $decoded['session_id'] !== '') {
          $metadata['session_id'] = $decoded['session_id'];
        }

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
    }, $mcpHeaders, FALSE, 120000, $executorUid);

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
  private function buildRequestPayload(array $profile, string $prompt, ?string $resume, array $mcpHeaders = [], bool $interactivePermissions = FALSE, int $permissionTimeoutMs = 120000, ?int $executorUid = NULL): array {
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

    // Inject executor UID for tool bridge user context.
    if ($executorUid !== NULL && $executorUid > 0) {
      $options['env'] = ($options['env'] ?? []);
      $options['env']['AI_CLAUDE_AGENT_SDK_BRIDGE_UID'] = (string) $executorUid;
    }

    // Interactive permission propagation.
    if ($interactivePermissions) {
      $options['interactivePermissions'] = TRUE;
      $options['permissionTimeoutMs'] = $permissionTimeoutMs;
    }

    // Inject security tier settings when a security_tier is present.
    $securityTier = $profile['security_tier'] ?? '';
    if ($securityTier !== '' && $securityTier !== 'custom') {
      // Generate the policy endpoint URL.
      $baseUrl = $this->configFactory->get('ai_claude_agent_sdk.settings')->get('site_base_url') ?: '';
      if ($baseUrl === '') {
        // Fall back to constructing from request context if available.
        if (\Drupal::hasRequest()) {
          $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
        }
      }

      // Validate tier requirements — strict tier refuses to run without hooks.
      $validation = $this->tierManager->validateTierRequirements($securityTier, $baseUrl);
      if (!empty($validation['errors'])) {
        throw new \RuntimeException(implode(' ', $validation['errors']));
      }
      foreach ($validation['warnings'] as $warning) {
        \Drupal::logger('ai_claude_agent_sdk')->warning($warning);
      }

      if ($baseUrl !== '') {
        $policyUrl = rtrim($baseUrl, '/') . '/api/claude-policy/evaluate';

        // Build hook config.
        $hookConfig = $this->tierManager->generateHookConfig($securityTier, $policyUrl);
        if (!empty($hookConfig)) {
          // Generate HMAC token for hook authentication.
          $profileId = $profile['profile_id'] ?? '';
          $timestamp = \Drupal::time()->getRequestTime();
          $token = ClaudePolicyController::generatePolicyToken($profileId, $timestamp);

          // Add auth headers to hook config.
          foreach ($hookConfig as $hookType => &$hookConf) {
            $hookConf['headers'] = [
              'X-Policy-Token' => $token,
              'X-Policy-Profile' => $profileId,
            ];
          }
          unset($hookConf);

          $options['hooks'] = $hookConfig;
        }
      }

      // Inject managed settings.
      $managedSettings = $this->tierManager->generateManagedSettings($securityTier);
      if (!empty($managedSettings)) {
        $options['managedSettings'] = $managedSettings;
      }

      // Inject permission rules from tier.
      $tierSettings = $this->tierManager->buildTierSettings($securityTier, $this->createProfileStub($profile));
      if (!empty($tierSettings['permission_rules'])) {
        $rules = $tierSettings['permission_rules'];
        if (!empty($rules['allow'])) {
          $options['permissions'] = $options['permissions'] ?? [];
          $options['permissions']['allow'] = $rules['allow'];
        }
        if (!empty($rules['deny'])) {
          $options['permissions'] = $options['permissions'] ?? [];
          $options['permissions']['deny'] = $rules['deny'];
        }
      }

      // Override permission mode from tier (not custom).
      if (!empty($tierSettings['permission_mode'])) {
        $options['permissionMode'] = $tierSettings['permission_mode'];
      }

      // Override sandbox from tier.
      if (isset($tierSettings['sandbox']['enabled'])) {
        $options['sandbox'] = ['enabled' => $tierSettings['sandbox']['enabled']];
        if (!empty($tierSettings['sandbox']['network'])) {
          $options['sandbox']['network'] = TRUE;
        }
      }
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
  public function fireAndForget(array $profile, string $prompt, array $mcpHeaders = [], array $metadata = [], ?int $executorUid = NULL): string {
    $payload = $this->buildRequestPayload($profile, $prompt, NULL, $mcpHeaders, FALSE, 120000, $executorUid);
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

    // Webhook callback config -- forwarded to sidecar for WebhookEmitter.
    if (!empty($metadata['callbackUrl'])) {
      $payload['callbackUrl'] = $metadata['callbackUrl'];
    }
    if (!empty($metadata['callbackToken'])) {
      $payload['callbackToken'] = $metadata['callbackToken'];
    }
    if (!empty($metadata['callbackEvents'])) {
      $payload['callbackEvents'] = $metadata['callbackEvents'];
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

  /**
   * Creates a minimal profile stub from array data for tier settings.
   *
   * Used when buildRequestPayload() needs to call SecurityTierManager
   * but only has the array-format profile data.
   *
   * @param array $profile
   *   Profile data array from toSidecarFormat().
   *
   * @return \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface
   *   A stub implementing the interface.
   */
  private function createProfileStub(array $profile): AgentProfileInterface {
    // Load the real entity if profile_id is available.
    $profileId = $profile['profile_id'] ?? '';
    if ($profileId !== '') {
      $entity = \Drupal::entityTypeManager()->getStorage('agent_profile')->load($profileId);
      if ($entity instanceof AgentProfileInterface) {
        return $entity;
      }
    }

    // Fallback: create a transient entity from array data.
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $stub */
    $stub = \Drupal::entityTypeManager()->getStorage('agent_profile')->create([
      'id' => $profileId ?: 'transient',
      'permission_mode' => $profile['permission_mode'] ?? 'default',
      'sandbox' => $profile['sandbox'] ?? FALSE,
      'allowed_tools' => $profile['allowed_tools'] ?? [],
      'denied_tools' => $profile['denied_tools'] ?? [],
      'security_tier' => $profile['security_tier'] ?? 'strict',
    ]);
    return $stub;
  }

}
