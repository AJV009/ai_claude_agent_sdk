<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
   * @return array<string, array{name: string, description: string, source: string, content: string}>
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
          'content' => $content,
        ];
      }
    }

    return $skills;
  }

  /**
   * Returns unmanaged project-scope filesystem skills.
   *
   * @return array<string, array{name: string, description: string, source: string, content: string}>
   *   Only project-scope skills not already managed as config entities.
   */
  public function getUnmanagedSkills(): array {
    $filesystemSkills = $this->discoverFilesystemSkills();
    $entityIds = array_keys($this->entityTypeManager->getStorage('agent_skill')->loadMultiple());

    return array_filter(
      array_diff_key($filesystemSkills, array_flip($entityIds)),
      fn(array $skill): bool => $skill['source'] === 'project',
    );
  }

  /**
   * Syncs all unmanaged project-scope filesystem skills into config entities.
   *
   * @return array{created: string[], skipped: string[]}
   */
  public function syncAllFromFilesystem(): array {
    $result = ['created' => [], 'skipped' => []];
    $unmanagedSkills = $this->getUnmanagedSkills();

    foreach ($unmanagedSkills as $dirName => $skill) {
      $entity = $this->createEntityFromContent($skill['content'], $dirName, $dirName);
      if ($entity) {
        $result['created'][] = $dirName;
      }
      else {
        $result['skipped'][] = $dirName;
      }
    }

    return $result;
  }

  /**
   * Parse SKILL.md content and create an AgentSkill entity.
   *
   * @param string $content
   *   The raw SKILL.md file content.
   * @param string $fallbackName
   *   Fallback name if frontmatter has no name key.
   * @param string|null $forceId
   *   If provided, use this as the entity ID directly (for sync).
   *
   * @return \Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface|null
   *   The created entity, or NULL if it already exists.
   */
  public function createEntityFromContent(string $content, string $fallbackName = '', ?string $forceId = NULL): ?AgentSkillInterface {
    $meta = [];
    $body = $content;

    if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)/s', $content, $matches)) {
      try {
        $meta = Yaml::parse($matches[1]) ?: [];
      }
      catch (\Exception $e) {
        // Malformed frontmatter — use full content as body.
      }
      $body = $matches[2];
    }

    if ($forceId !== NULL) {
      $id = $forceId;
    }
    else {
      $name = $meta['name'] ?? $fallbackName ?: 'imported-skill-' . substr(uniqid(), -6);
      $id = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
      $id = trim($id, '-');
    }

    $storage = $this->entityTypeManager->getStorage('agent_skill');

    if ($storage->load($id)) {
      return NULL;
    }

    $name = $meta['name'] ?? $fallbackName ?: $id;

    $values = [
      'id' => $id,
      'label' => $meta['name'] ?? ucwords(str_replace(['-', '_'], ' ', $name)),
      'description' => $meta['description'] ?? '',
      'skill_body' => trim($body),
      'disable_model_invocation' => !empty($meta['disable-model-invocation']),
      'user_invocable' => $meta['user-invocable'] ?? TRUE,
      'argument_hint' => $meta['argument-hint'] ?? '',
      'allowed_tools' => $meta['allowed-tools'] ?? [],
      'context_mode' => $meta['context-mode'] ?? 'inline',
      'agent_type' => $meta['agent-type'] ?? '',
    ];

    $entity = $storage->create($values);
    $entity->save();

    return $entity;
  }

  /**
   * Parses YAML frontmatter from SKILL.md content.
   */
  public function parseFrontmatter(string $content): array {
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
