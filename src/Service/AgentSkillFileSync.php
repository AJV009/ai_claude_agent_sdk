<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Syncs Agent Skill entities to/from the filesystem as SKILL.md files.
 */
class AgentSkillFileSync {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Writes a skill entity to the filesystem as a SKILL.md file.
   */
  public function syncSkillToFilesystem(AgentSkillInterface $skill): void {
    $workingDir = $this->getWorkingDir();
    if (!$workingDir) {
      return;
    }

    $skillDir = $workingDir . '/.claude/skills/' . $skill->id();

    if (!is_dir($skillDir)) {
      mkdir($skillDir, 0755, TRUE);
    }

    file_put_contents($skillDir . '/SKILL.md', $skill->toSkillMd());
  }

  /**
   * Removes a skill directory from the filesystem.
   */
  public function removeSkillFromFilesystem(string $skillId): void {
    $workingDir = $this->getWorkingDir();
    if (!$workingDir) {
      return;
    }

    $skillDir = $workingDir . '/.claude/skills/' . $skillId;

    if (is_dir($skillDir)) {
      $this->fileSystem->deleteRecursive($skillDir);
    }
  }

  /**
   * Resolves the working directory.
   */
  protected function getWorkingDir(): string {
    // Check environment variable first, then config.
    $workingDir = getenv('WORKING_DIR') ?: '';
    if (!$workingDir) {
      $workingDir = $this->configFactory->get('ai_claude_agent_sdk.settings')->get('working_directory') ?: '';
    }
    if (!$workingDir) {
      $workingDir = '/var/www/html';
    }

    return $workingDir;
  }

}
