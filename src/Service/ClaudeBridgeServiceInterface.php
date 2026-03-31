<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

/**
 * Interface for the Claude bridge service.
 */
interface ClaudeBridgeServiceInterface {

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
  public function stream(array $profile, string $prompt, ?string $resume, callable $onChunk, array $mcpHeaders = [], bool $interactivePermissions = FALSE, int $permissionTimeoutMs = 120000, ?int $executorUid = NULL): void;

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
  public function collectResponse(array $profile, string $prompt, ?string $resume = NULL, array $mcpHeaders = [], ?int $executorUid = NULL, array &$metadata = []): string;

  /**
   * Fire-and-forget a background query to the sidecar.
   *
   * @param array $profile
   *   Profile data from AgentProfile::toSidecarFormat().
   * @param string $prompt
   *   The user prompt.
   * @param string|null $resume
   *   Optional session ID to resume.
   * @param array $mcpHeaders
   *   Optional headers to inject into MCP server configs.
   * @param array $metadata
   *   Optional metadata (skillId, initiatorUid, callbackUrl, etc.).
   * @param int|null $executorUid
   *   Optional executor user ID.
   *
   * @return string
   *   The queryId assigned by the sidecar.
   */
  public function fireAndForget(array $profile, string $prompt, ?string $resume, array $mcpHeaders = [], array $metadata = [], ?int $executorUid = NULL): string;

}
