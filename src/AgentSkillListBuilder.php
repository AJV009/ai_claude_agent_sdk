<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillFileSync;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Agent Skill config entities.
 */
final class AgentSkillListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly AgentSkillFileSync $skillFileSync,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('ai_claude_agent_sdk.skill_file_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['description'] = $this->t('Description');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface $entity */
    $description = $entity->getDescription();
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['description'] = mb_strlen($description) > 80 ? mb_substr($description, 0, 80) . '...' : $description;
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();

    // Find filesystem skills not managed as config entities.
    $filesystemSkills = $this->skillFileSync->discoverFilesystemSkills();
    $entityIds = array_map(fn($entity) => $entity->id(), $this->load());
    $unmanagedSkills = array_diff_key($filesystemSkills, array_flip($entityIds));

    if ($unmanagedSkills) {
      $rows = [];
      foreach ($unmanagedSkills as $dirName => $skill) {
        $description = $skill['description'];
        $rows[] = [
          $skill['name'],
          $dirName,
          mb_strlen($description) > 80 ? mb_substr($description, 0, 80) . '...' : $description,
          $skill['source'] === 'project' ? $this->t('Project') : $this->t('User'),
        ];
      }

      $build['filesystem_skills'] = [
        '#type' => 'details',
        '#title' => $this->t('Discovered filesystem skills (@count)', ['@count' => count($unmanagedSkills)]),
        '#open' => TRUE,
        '#weight' => 50,
        '#description' => $this->t('Skills found in <code>.claude/skills/</code> directories that are not managed through this admin UI. These are available to Claude Code directly.'),
      ];
      $build['filesystem_skills']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Directory'),
          $this->t('Description'),
          $this->t('Source'),
        ],
        '#rows' => $rows,
      ];
    }

    return $build;
  }

}
