<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_claude_agent_sdk_runner\Service\ToolTransitionLayer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ToolTransitionLayer service.
 *
 * @group ai_claude_agent_sdk_runner
 */
class ToolTransitionLayerTest extends TestCase {

  /**
   * Tests buildSkillId sanitization.
   */
  public function testBuildSkillId(): void {
    $toolManager = $this->createMock(DefaultPluginManager::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $service = new ToolTransitionLayer($toolManager, $entityTypeManager);

    $method = new \ReflectionMethod($service, 'buildSkillId');
    $method->setAccessible(TRUE);

    $this->assertSame(
      '_agent_content_creator__drupal_search_content',
      $method->invoke($service, 'content_creator', 'drupal:search_content')
    );

    $this->assertSame(
      '_agent_taxonomy_mgr__ai_agents_manage_terms',
      $method->invoke($service, 'taxonomy_mgr', 'ai_agents.manage-terms')
    );
  }

  /**
   * Tests generateSkillBody produces valid markdown with drush command.
   */
  public function testGenerateSkillBody(): void {
    $toolManager = $this->createMock(DefaultPluginManager::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $service = new ToolTransitionLayer($toolManager, $entityTypeManager);

    $method = new \ReflectionMethod($service, 'generateSkillBody');
    $method->setAccessible(TRUE);

    $definition = [
      'label' => 'Search Content',
      'description' => 'Searches Drupal content by keyword.',
      'input_schema' => [
        'type' => 'object',
        'properties' => [
          'query' => ['type' => 'string', 'description' => 'Search query'],
          'limit' => ['type' => 'integer', 'description' => 'Max results'],
        ],
        'required' => ['query'],
      ],
    ];

    $body = $method->invoke($service, 'drupal:search_content', $definition);

    $this->assertStringContainsString('Search Content', $body);
    $this->assertStringContainsString('drush ai-claude-agent-sdk:tool-run', $body);
    $this->assertStringContainsString('drupal:search_content', $body);
    $this->assertStringContainsString('query', $body);
  }

}
