<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
   * Discovers skills from the filesystem that are not managed as config entities.
   *
   * @return array<string, array{name: string, description: string, source: string}>
   *   Keyed by skill directory name.
   */
  public function discoverFilesystemSkills(): array {
    $workingDir = $this->getWorkingDir();
    if (!$workingDir) {
      return [];
    }

    $skills = [];
    $dirs = [
      $workingDir . '/.claude/skills' => 'project',
      ($_SERVER['HOME'] ?? getenv('HOME') ?: '') . '/.claude/skills' => 'user',
    ];

    foreach ($dirs as $baseDir => $source) {
      if (!$baseDir || !is_dir($baseDir)) {
        continue;
      }
      $entries = @scandir($baseDir);
      if ($entries === FALSE) {
        continue;
      }
      foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
          continue;
        }
        $skillFile = $baseDir . '/' . $entry . '/SKILL.md';
        if (!is_file($skillFile)) {
          continue;
        }
        $content = @file_get_contents($skillFile);
        if ($content === FALSE) {
          continue;
        }
        $parsed = $this->parseFrontmatter($content);
        $skills[$entry] = [
          'name' => $parsed['name'] ?? $entry,
          'description' => $parsed['description'] ?? '',
          'source' => $source,
        ];
      }
    }

    return $skills;
  }

  /**
   * Parses YAML frontmatter from SKILL.md content.
   */
  protected function parseFrontmatter(string $content): array {
    if (!preg_match('/\A---\r?\n(.+?)\r?\n---/s', $content, $matches)) {
      return [];
    }
    try {
      $data = Yaml::parse($matches[1]);
      return is_array($data) ? $data : [];
    }
    catch (ParseException) {
      return [];
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
