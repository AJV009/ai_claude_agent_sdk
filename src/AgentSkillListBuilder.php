<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for Agent Skill config entities.
 */
final class AgentSkillListBuilder extends ConfigEntityListBuilder {

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

}
