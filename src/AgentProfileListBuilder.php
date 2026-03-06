<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for Agent Profile config entities.
 */
final class AgentProfileListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['model'] = $this->t('Model');
    $header['permission_mode'] = $this->t('Permission mode');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['model'] = $entity->getModel();
    $row['permission_mode'] = $entity->getPermissionMode();
    return $row + parent::buildRow($entity);
  }

}
