<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for Claude Agent Profile config entities.
 */
interface AgentProfileInterface extends ConfigEntityInterface {

  /**
   * Gets the profile description.
   */
  public function getDescription(): string;

  /**
   * Gets the system prompt.
   */
  public function getSystemPrompt(): string;

  /**
   * Gets the model name.
   */
  public function getModel(): string;

  /**
   * Gets the permission mode.
   */
  public function getPermissionMode(): string;

  /**
   * Gets the max turns.
   */
  public function getMaxTurns(): int;

  /**
   * Gets the allowed tools list.
   *
   * @return string[]
   */
  public function getAllowedTools(): array;

  /**
   * Gets the denied tools list.
   *
   * @return string[]
   */
  public function getDeniedTools(): array;

  /**
   * Gets the MCP server configurations.
   *
   * @return array<int, array{name: string, transport: string, url: string}>
   */
  public function getMcpServers(): array;

  /**
   * Gets the working directory.
   */
  public function getWorkingDirectory(): string;

  /**
   * Gets whether sandbox mode is enabled.
   */
  public function getSandbox(): bool;

  /**
   * Gets the allowed directories list.
   *
   * @return string[]
   */
  public function getAllowedDirectories(): array;

  /**
   * Gets the executor UID.
   */
  public function getExecutorUid(): int;

  /**
   * Gets the execution modality.
   */
  public function getExecutionModality(): string;

  /**
   * Gets the extra CLI arguments.
   *
   * @return string[]
   */
  public function getExtraArgs(): array;

  /**
   * Serializes the profile to the format expected by the Node.js sidecar.
   *
   * @return array<string, mixed>
   */
  public function toSidecarFormat(): array;

}
