<?php

namespace Drupal\ClaudeSidecarScaffold;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Scaffolds Claude TUI sidecar DDEV files after composer install/update.
 *
 * Copies DDEV config and commands from the ai_claude_agent_sdk module into
 * the project's .ddev/ directory. Only runs when a .ddev/ directory exists
 * (i.e., the project uses DDEV). Idempotent — skips files that haven't changed.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Files to scaffold: destination (relative to project root) => source
   * (relative to module's sidecar/ directory).
   */
  private const FILE_MAP = [
    '.ddev/config.claude-sidecar.yaml' => 'ddev/config.claude-sidecar.yaml',
    '.ddev/commands/web/claude-sidecar' => 'ddev/commands/web/claude-sidecar',
  ];

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {
    $projectRoot = getcwd();

    if (!is_dir($projectRoot . '/.ddev')) {
      return;
    }

    $removed = FALSE;
    foreach (self::FILE_MAP as $dest => $source) {
      $destPath = $projectRoot . '/' . $dest;

      if (!file_exists($destPath)) {
        continue;
      }

      // Only remove files that contain our module-specific marker,
      // indicating they were scaffolded by this plugin and not customized.
      $contents = file_get_contents($destPath);
      if ($contents !== FALSE && str_contains($contents, '#ai-claude-agent-sdk-generated')) {
        unlink($destPath);
        $io->write('  <info>Removed scaffolded file: ' . $dest . '</info>');
        $removed = TRUE;

        // Remove parent directory if empty (e.g., .ddev/commands/web/).
        $parentDir = dirname($destPath);
        if (is_dir($parentDir) && count(scandir($parentDir)) === 2) {
          rmdir($parentDir);
        }
      }
      else {
        $io->write('  <comment>Skipped ' . $dest . ' (customized, remove manually)</comment>');
      }
    }

    if ($removed) {
      $io->write('  <info>Claude sidecar DDEV config removed. Run <comment>ddev restart</comment> to apply.</info>');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ScriptEvents::POST_INSTALL_CMD => 'scaffold',
      ScriptEvents::POST_UPDATE_CMD => 'scaffold',
    ];
  }

  /**
   * Scaffold DDEV files from the sidecar into .ddev/.
   */
  public function scaffold(Event $event): void {
    $io = $event->getIO();
    $projectRoot = getcwd();

    // Only scaffold for DDEV projects.
    if (!is_dir($projectRoot . '/.ddev')) {
      return;
    }

    $sidecarDir = $this->findSidecarDir($event->getComposer(), $projectRoot);
    if ($sidecarDir === NULL) {
      return;
    }

    $changed = FALSE;
    foreach (self::FILE_MAP as $dest => $source) {
      $sourcePath = $sidecarDir . '/' . $source;
      $destPath = $projectRoot . '/' . $dest;

      if (!file_exists($sourcePath)) {
        continue;
      }

      // Ensure destination directory exists.
      $destDir = dirname($destPath);
      if (!is_dir($destDir)) {
        mkdir($destDir, 0755, TRUE);
      }

      // Skip if file unchanged.
      if (file_exists($destPath) && md5_file($sourcePath) === md5_file($destPath)) {
        continue;
      }

      copy($sourcePath, $destPath);
      // Ensure commands are executable.
      if (str_contains($dest, '/commands/')) {
        chmod($destPath, 0755);
      }
      $changed = TRUE;
    }

    if ($changed) {
      $io->write('  <info>Scaffolded Claude sidecar DDEV config. Run <comment>ddev restart</comment> to activate.</info>');
    }
  }

  /**
   * Find the sidecar directory from installed packages or known paths.
   */
  private function findSidecarDir(Composer $composer, string $projectRoot): ?string {
    // First: check installed Composer packages.
    $repo = $composer->getRepositoryManager()->getLocalRepository();
    foreach ($repo->getPackages() as $package) {
      if ($package->getName() === 'drupal/ai_claude_agent_sdk') {
        $installPath = $composer->getInstallationManager()->getInstallPath($package);
        $candidate = $installPath . '/sidecar';
        if (is_dir($candidate) && file_exists($candidate . '/ddev/config.claude-sidecar.yaml')) {
          return $candidate;
        }
      }
    }

    // Fallback: check common module paths (for local/development installs).
    $candidates = [
      $projectRoot . '/web/modules/contrib/ai_claude_agent_sdk/sidecar',
      $projectRoot . '/web/modules/custom/ai_claude_agent_sdk/sidecar',
      $projectRoot . '/web/modules/ai_claude_agent_sdk/sidecar',
    ];

    foreach ($candidates as $candidate) {
      if (is_dir($candidate) && file_exists($candidate . '/ddev/config.claude-sidecar.yaml')) {
        return $candidate;
      }
    }

    return NULL;
  }

}
