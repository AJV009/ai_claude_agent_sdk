<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_claude_agent_sdk_runner\Entity\AgentRunnerSkillInterface;
use Drupal\ai_claude_agent_sdk_runner\Service\AgentRunnerSkillFileSync;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the AgentRunnerSkillFileSync service.
 *
 * @group ai_claude_agent_sdk_runner
 */
class AgentRunnerSkillFileSyncTest extends TestCase {

  /**
   * Tests that getSkillDir builds the correct path.
   */
  public function testSkillDirPath(): void {
    $skill = $this->createMock(AgentRunnerSkillInterface::class);
    $skill->method('get')->willReturnMap([
      ['agent_id', 'content_creator'],
      ['source_tool_id', 'drupal:search_content'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('working_directory')->willReturn('/var/www/html');
    $configFactory->method('get')->with('ai_claude_agent_sdk.settings')->willReturn($config);

    $fileSystem = $this->createMock(FileSystemInterface::class);

    $service = new AgentRunnerSkillFileSync($configFactory, $fileSystem);

    // Use reflection to test the protected method.
    $method = new \ReflectionMethod($service, 'getSkillDir');
    $method->setAccessible(TRUE);
    $dir = $method->invoke($service, $skill);

    $this->assertSame('/var/www/html/.claude/skills/_agent_content_creator/drupal_search_content', $dir);
  }

}
