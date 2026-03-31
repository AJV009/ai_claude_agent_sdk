<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Agent Profile config entity.
 *
 * @ConfigEntityType(
 *   id = "agent_profile",
 *   label = @Translation("Agent Profile"),
 *   label_collection = @Translation("Agent Profiles"),
 *   label_singular = @Translation("agent profile"),
 *   label_plural = @Translation("agent profiles"),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_claude_agent_sdk\AgentProfileListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_claude_agent_sdk\Form\AgentProfileForm",
 *       "edit" = "Drupal\ai_claude_agent_sdk\Form\AgentProfileForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "agent_profile",
 *   admin_permission = "administer claude agent sdk",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "description",
 *     "system_prompt",
 *     "model",
 *     "permission_mode",
 *     "security_tier",
 *     "max_turns",
 *     "allowed_tools",
 *     "denied_tools",
 *     "mcp_servers",
 *     "working_directory",
 *     "sandbox",
 *     "sandbox_network",
 *     "hook_mode",
 *     "disable_bypass_mode",
 *     "managed_rules_only",
 *     "bash_allow_patterns",
 *     "bash_deny_patterns",
 *     "allowed_directories",
 *     "executor_uid",
 *     "execution_modality",
 *     "extra_args",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/claude-agent-sdk/profiles",
 *     "add-form" = "/admin/config/ai/claude-agent-sdk/profiles/add",
 *     "edit-form" = "/admin/config/ai/claude-agent-sdk/profiles/{agent_profile}/edit",
 *     "delete-form" = "/admin/config/ai/claude-agent-sdk/profiles/{agent_profile}/delete",
 *   },
 * )
 */
class AgentProfile extends ConfigEntityBase implements AgentProfileInterface {

  /**
   * The machine name.
   */
  protected string $id = '';

  /**
   * The human-readable label.
   */
  protected string $label = '';

  /**
   * The profile description.
   */
  protected string $description = '';

  /**
   * The system prompt.
   */
  protected string $system_prompt = '';

  /**
   * The model name.
   */
  protected string $model = 'sonnet';

  /**
   * The permission mode.
   */
  protected string $permission_mode = 'default';

  /**
   * The security tier.
   */
  protected string $security_tier = 'strict';

  /**
   * The max turns.
   */
  protected int $max_turns = 25;

  /**
   * The allowed tools.
   *
   * @var string[]
   */
  protected array $allowed_tools = [];

  /**
   * The denied tools.
   *
   * @var string[]
   */
  protected array $denied_tools = [];

  /**
   * The MCP server configurations.
   *
   * @var array<int, array{name: string, transport: string, url: string}>
   */
  protected array $mcp_servers = [];

  /**
   * The working directory.
   */
  protected string $working_directory = '';

  /**
   * Whether sandbox mode is enabled.
   */
  protected bool $sandbox = FALSE;

  /**
   * Whether sandbox network isolation is enabled.
   */
  protected bool $sandbox_network = FALSE;

  /**
   * The HTTP policy hook mode.
   */
  protected string $hook_mode = '';

  /**
   * Whether bypass permissions mode is disabled.
   */
  protected bool $disable_bypass_mode = FALSE;

  /**
   * Whether only managed permission rules are allowed.
   */
  protected bool $managed_rules_only = FALSE;

  /**
   * Bash allow patterns.
   *
   * @var string[]
   */
  protected array $bash_allow_patterns = [];

  /**
   * Bash deny patterns.
   *
   * @var string[]
   */
  protected array $bash_deny_patterns = [];

  /**
   * The allowed directories.
   *
   * @var string[]
   */
  protected array $allowed_directories = [];

  /**
   * The executor UID.
   */
  protected int $executor_uid = 0;

  /**
   * The execution modality.
   */
  protected string $execution_modality = 'interactive';

  /**
   * Extra CLI arguments.
   *
   * @var string[]
   */
  protected array $extra_args = [];

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getSystemPrompt(): string {
    return $this->system_prompt;
  }

  /**
   * {@inheritdoc}
   */
  public function getModel(): string {
    return $this->model;
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissionMode(): string {
    return $this->permission_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getSecurityTier(): string {
    return $this->security_tier;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxTurns(): int {
    return $this->max_turns;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedTools(): array {
    return $this->allowed_tools;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeniedTools(): array {
    return $this->denied_tools;
  }

  /**
   * {@inheritdoc}
   */
  public function getMcpServers(): array {
    return $this->mcp_servers;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkingDirectory(): string {
    return $this->working_directory;
  }

  /**
   * {@inheritdoc}
   */
  public function getSandbox(): bool {
    return $this->sandbox;
  }

  /**
   * {@inheritdoc}
   */
  public function getSandboxNetwork(): bool {
    return $this->sandbox_network;
  }

  /**
   * {@inheritdoc}
   */
  public function getHookMode(): string {
    return $this->hook_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisableBypassMode(): bool {
    return $this->disable_bypass_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getManagedRulesOnly(): bool {
    return $this->managed_rules_only;
  }

  /**
   * {@inheritdoc}
   */
  public function getBashAllowPatterns(): array {
    return $this->bash_allow_patterns;
  }

  /**
   * {@inheritdoc}
   */
  public function getBashDenyPatterns(): array {
    return $this->bash_deny_patterns;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedDirectories(): array {
    return $this->allowed_directories;
  }

  /**
   * {@inheritdoc}
   */
  public function getExecutorUid(): int {
    return $this->executor_uid;
  }

  /**
   * {@inheritdoc}
   */
  public function getExecutionModality(): string {
    return $this->execution_modality;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtraArgs(): array {
    return $this->extra_args;
  }

  /**
   * {@inheritdoc}
   */
  public function toSidecarFormat(): array {
    return [
      'profile_id' => $this->id(),
      'system_prompt' => $this->system_prompt,
      'model' => $this->model,
      'permission_mode' => $this->permission_mode,
      'security_tier' => $this->security_tier,
      'max_turns' => $this->max_turns,
      'allowed_tools' => $this->allowed_tools,
      'denied_tools' => $this->denied_tools,
      'mcp_servers' => $this->mcp_servers,
      'working_directory' => $this->working_directory,
      'sandbox' => $this->sandbox,
      'sandbox_network' => $this->sandbox_network,
      'hook_mode' => $this->hook_mode,
      'disable_bypass_mode' => $this->disable_bypass_mode,
      'managed_rules_only' => $this->managed_rules_only,
      'bash_allow_patterns' => $this->bash_allow_patterns,
      'bash_deny_patterns' => $this->bash_deny_patterns,
      'allowed_directories' => $this->allowed_directories,
      'extra_args' => $this->extra_args,
    ];
  }

}
