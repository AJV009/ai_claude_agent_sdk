<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillFileSync;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
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
    $header['source'] = $this->t('Source');
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
    $row['source'] = $this->t('Managed');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $operations['run'] = [
      'title' => $this->t('Run'),
      'url' => Url::fromRoute('ai_claude_agent_sdk.skill_run', ['agent_skill' => $entity->id()]),
      'weight' => 50,
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();

    // Check for unmanaged project-scope skills.
    $unmanagedSkills = $this->skillFileSync->getUnmanagedSkills();
    if (!empty($unmanagedSkills)) {
      $build['sync_banner'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#weight' => -10,
        'message' => [
          '#markup' => $this->t('@count unmanaged skill(s) found on the filesystem.', [
            '@count' => count($unmanagedSkills),
          ]),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Sync from filesystem'),
          '#url' => new Url('ai_claude_agent_sdk.skills_sync'),
          '#prefix' => ' ',
        ],
      ];
    }

    // Append user-scope skills as read-only rows.
    $filesystemSkills = $this->skillFileSync->discoverFilesystemSkills();
    $userSkills = array_filter(
      $filesystemSkills,
      fn(array $skill): bool => $skill['source'] === 'user',
    );

    if (!empty($userSkills)) {
      foreach ($userSkills as $dirName => $skill) {
        $description = $skill['description'];
        $build['table']['#rows'][] = [
          'label' => $skill['name'],
          'id' => $dirName,
          'description' => mb_strlen($description) > 80 ? mb_substr($description, 0, 80) . '...' : $description,
          'source' => $this->t('User'),
          'operations' => '',
        ];
      }
    }

    return $build;
  }

}
