<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Session;

final class SessionFileStore {

  private const SESSION_ID_PATTERN = '/^[A-Za-z0-9-]+$/';

  public function listSessionFiles(string $sessionId): array {
    if (!$this->isValidSessionId($sessionId)) {
      return [];
    }

    $root = $this->projectsRoot();
    if ($root === null || !is_dir($root)) {
      return [];
    }

    $glob = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $sessionId . '.jsonl';
    $paths = glob($glob) ?: [];
    sort($paths);

    $files = [];
    foreach ($paths as $path) {
      if (!is_string($path) || !is_file($path)) {
        continue;
      }
      $content = @file_get_contents($path);
      if (!is_string($content)) {
        continue;
      }

      $files[] = [
        'path' => $path,
        'size' => filesize($path) ?: 0,
        'modified' => filemtime($path) ?: 0,
        'content' => $content,
      ];
    }

    return $files;
  }

  public function deleteSessionFiles(string $sessionId): array {
    if (!$this->isValidSessionId($sessionId)) {
      return ['deleted' => 0, 'paths' => []];
    }

    $root = $this->projectsRoot();
    if ($root === null || !is_dir($root)) {
      return ['deleted' => 0, 'paths' => []];
    }

    $glob = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $sessionId . '.jsonl';
    $paths = glob($glob) ?: [];

    $deletedPaths = [];
    foreach ($paths as $path) {
      if (!is_string($path) || !is_file($path)) {
        continue;
      }
      if (@unlink($path)) {
        $deletedPaths[] = $path;
      }
    }

    return [
      'deleted' => count($deletedPaths),
      'paths' => $deletedPaths,
    ];
  }

  private function projectsRoot(): ?string {
    $home = getenv('HOME');
    if (!is_string($home) || $home === '') {
      return null;
    }
    return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'projects';
  }

  private function isValidSessionId(string $sessionId): bool {
    return preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1;
  }

}
