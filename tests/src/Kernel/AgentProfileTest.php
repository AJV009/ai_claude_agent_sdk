<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Kernel;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfile;
use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests for the AgentProfile config entity.
 *
 * @group ai_claude_agent_sdk
 */
class AgentProfileTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'ai',
    'ai_claude_agent_sdk',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_claude_agent_sdk']);
  }

  /**
   * Tests that the default profile is installed.
   */
  public function testDefaultProfileInstalled(): void {
    $profile = AgentProfile::load('default');
    $this->assertNotNull($profile);
    $this->assertInstanceOf(AgentProfileInterface::class, $profile);
    $this->assertEquals('Default Agent', $profile->label());
    $this->assertEquals('sonnet', $profile->getModel());
    $this->assertEquals('plan', $profile->getPermissionMode());
    $this->assertEquals(25, $profile->getMaxTurns());
    $this->assertEquals(['Bash'], $profile->getDeniedTools());
    $this->assertCount(1, $profile->getMcpServers());
    $this->assertEquals('drupal', $profile->getMcpServers()[0]['name']);
  }

  /**
   * Tests creating a new profile.
   */
  public function testCreateProfile(): void {
    $profile = AgentProfile::create([
      'id' => 'content_editor',
      'label' => 'Content Editor',
      'description' => 'Profile for content editing tasks.',
      'system_prompt' => 'You are a content editor.',
      'model' => 'haiku',
      'permission_mode' => 'default',
      'max_turns' => 10,
      'allowed_tools' => ['Read', 'Write'],
      'denied_tools' => [],
      'mcp_servers' => [
        ['name' => 'drupal', 'transport' => 'http', 'url' => '/_mcp'],
      ],
      'working_directory' => '/var/www/html',
      'sandbox' => TRUE,
      'allowed_directories' => ['/var/www/html/web'],
      'executor_uid' => 1,
      'execution_modality' => 'background',
      'extra_args' => ['--verbose'],
    ]);
    $profile->save();

    $loaded = AgentProfile::load('content_editor');
    $this->assertNotNull($loaded);
    $this->assertEquals('Content Editor', $loaded->label());
    $this->assertEquals('Profile for content editing tasks.', $loaded->getDescription());
    $this->assertEquals('You are a content editor.', $loaded->getSystemPrompt());
    $this->assertEquals('haiku', $loaded->getModel());
    $this->assertEquals('default', $loaded->getPermissionMode());
    $this->assertEquals(10, $loaded->getMaxTurns());
    $this->assertEquals(['Read', 'Write'], $loaded->getAllowedTools());
    $this->assertEmpty($loaded->getDeniedTools());
    $this->assertEquals('/var/www/html', $loaded->getWorkingDirectory());
    $this->assertTrue($loaded->getSandbox());
    $this->assertEquals(['/var/www/html/web'], $loaded->getAllowedDirectories());
    $this->assertEquals(1, $loaded->getExecutorUid());
    $this->assertEquals('background', $loaded->getExecutionModality());
    $this->assertEquals(['--verbose'], $loaded->getExtraArgs());
  }

  /**
   * Tests deleting a profile.
   */
  public function testDeleteProfile(): void {
    $profile = AgentProfile::create([
      'id' => 'to_delete',
      'label' => 'To Delete',
    ]);
    $profile->save();
    $this->assertNotNull(AgentProfile::load('to_delete'));

    $profile->delete();
    $this->assertNull(AgentProfile::load('to_delete'));
  }

  /**
   * Tests the toSidecarFormat() method.
   */
  public function testToSidecarFormat(): void {
    $profile = AgentProfile::create([
      'id' => 'sidecar_test',
      'label' => 'Sidecar Test',
      'system_prompt' => 'Test prompt',
      'model' => 'opus',
      'permission_mode' => 'bypassPermissions',
      'max_turns' => 50,
      'allowed_tools' => ['Read'],
      'denied_tools' => ['Bash'],
      'mcp_servers' => [
        ['name' => 'test', 'transport' => 'stdio', 'url' => '/bin/test'],
      ],
      'working_directory' => '/tmp',
      'sandbox' => TRUE,
      'allowed_directories' => ['/tmp'],
      'extra_args' => ['--debug'],
    ]);

    $format = $profile->toSidecarFormat();

    $this->assertEquals('Test prompt', $format['system_prompt']);
    $this->assertEquals('opus', $format['model']);
    $this->assertEquals('bypassPermissions', $format['permission_mode']);
    $this->assertEquals(50, $format['max_turns']);
    $this->assertEquals(['Read'], $format['allowed_tools']);
    $this->assertEquals(['Bash'], $format['denied_tools']);
    $this->assertCount(1, $format['mcp_servers']);
    $this->assertEquals('test', $format['mcp_servers'][0]['name']);
    $this->assertEquals('/tmp', $format['working_directory']);
    $this->assertTrue($format['sandbox']);
    $this->assertEquals(['/tmp'], $format['allowed_directories']);
    $this->assertEquals(['--debug'], $format['extra_args']);

    // Verify keys that should NOT be in sidecar format.
    $this->assertArrayNotHasKey('id', $format);
    $this->assertArrayNotHasKey('label', $format);
    $this->assertArrayNotHasKey('uuid', $format);
    $this->assertArrayNotHasKey('description', $format);
    $this->assertArrayNotHasKey('executor_uid', $format);
    $this->assertArrayNotHasKey('execution_modality', $format);
  }

  /**
   * Tests updating an existing profile.
   */
  public function testUpdateProfile(): void {
    $profile = AgentProfile::load('default');
    $this->assertNotNull($profile);

    $profile->set('model', 'opus');
    $profile->set('max_turns', 50);
    $profile->save();

    $reloaded = AgentProfile::load('default');
    $this->assertEquals('opus', $reloaded->getModel());
    $this->assertEquals(50, $reloaded->getMaxTurns());
  }

  /**
   * Tests listing all profiles.
   */
  public function testListProfiles(): void {
    AgentProfile::create([
      'id' => 'second',
      'label' => 'Second Profile',
    ])->save();

    $storage = \Drupal::entityTypeManager()->getStorage('agent_profile');
    $profiles = $storage->loadMultiple();
    $this->assertCount(2, $profiles);
    $this->assertArrayHasKey('default', $profiles);
    $this->assertArrayHasKey('second', $profiles);
  }

}
