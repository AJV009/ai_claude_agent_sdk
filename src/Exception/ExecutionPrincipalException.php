<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Exception;

/**
 * Thrown when the execution principal is invalid or cannot be resolved.
 *
 * This exception indicates a hard failure in resolving which Drupal user
 * account should execute an agent operation. Common causes:
 * - No executor configured for background execution
 * - Executor user does not exist (deleted)
 * - Executor user is blocked
 * - Anonymous user in interactive modality
 */
class ExecutionPrincipalException extends \RuntimeException {}
