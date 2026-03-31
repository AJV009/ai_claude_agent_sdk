<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Service;

use Drupal\ai_claude_agent_sdk_runner\Entity\AgentRunnerSkillInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Syncs AgentRunnerSkill entities to the filesystem as SKILL.md files.
 *
 * Mirrors AgentSkillFileSync but writes to _agent_{agent_id}/ directories
 * to namespace runner skills separately from manually-created skills.
 */
class AgentRunnerSkillFileSync {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Writes a runner skill to the filesystem.
   */
  public function syncToFilesystem(AgentRunnerSkillInterface $skill): void {
    $dir = $this->getSkillDir($skill);
    if (!$dir) {
      return;
    }

    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }

    file_put_contents($dir . '/SKILL.md', $skill->toSkillMd());
  }

  /**
   * Removes a runner skill's directory from the filesystem.
   */
  public function removeFromFilesystem(AgentRunnerSkillInterface $skill): void {
    $dir = $this->getSkillDir($skill);
    if (!$dir) {
      return;
    }

    if (is_dir($dir)) {
      $this->fileSystem->deleteRecursive($dir);
    }

    // Remove parent _agent_{id}/ dir if empty.
    $parentDir = dirname($dir);
    if (is_dir($parentDir) && count(scandir($parentDir)) <= 2) {
      rmdir($parentDir);
    }
  }

  /**
   * Removes all skill directories for a given agent.
   */
  public function removeAgentDirectory(string $agentId): void {
    $workingDir = $this->getWorkingDir();
    if (!$workingDir) {
      return;
    }

    $agentDir = $workingDir . '/.claude/skills/_agent_' . $agentId;
    if (is_dir($agentDir)) {
      $this->fileSystem->deleteRecursive($agentDir);
    }
  }

  /**
   * Builds the filesystem directory path for a runner skill.
   */
  protected function getSkillDir(AgentRunnerSkillInterface $skill): string {
    $workingDir = $this->getWorkingDir();
    if (!$workingDir) {
      return '';
    }

    $agentId = $skill->get('agent_id');
    $toolId = $skill->get('source_tool_id');
    $safeToolId = str_replace([':', '.', '-'], '_', $toolId);

    return $workingDir . '/.claude/skills/_agent_' . $agentId . '/' . $safeToolId;
  }

  /**
   * Resolves the working directory.
   */
  protected function getWorkingDir(): string {
    $workingDir = getenv('WORKING_DIR') ?: '';
    if (!$workingDir) {
      $workingDir = $this->configFactory
        ->get('ai_claude_agent_sdk.settings')
        ->get('working_directory') ?: '';
    }
    if (!$workingDir) {
      $workingDir = '/var/www/html';
    }
    return $workingDir;
  }

}
