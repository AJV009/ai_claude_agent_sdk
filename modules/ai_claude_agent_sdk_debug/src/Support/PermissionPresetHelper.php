<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Support;

final class PermissionPresetHelper {

  public static function normalizePermissionMode(mixed $mode): ?string {
    $value = trim((string) $mode);
    if ($value === '') {
      return null;
    }

    // Legacy alias mapping kept here for future reference, but intentionally
    // disabled for now to avoid carrying compatibility behavior.
    // $legacyAliases = [
    //   'auto' => 'default',
    //   'ask' => 'default',
    //   'deny' => 'plan',
    // ];
    // if (isset($legacyAliases[$value])) {
    //   return $legacyAliases[$value];
    // }

    $allowed = ['default', 'delegate', 'plan', 'dontAsk', 'acceptEdits', 'bypassPermissions'];
    return in_array($value, $allowed, TRUE) ? $value : null;
  }

  public static function apply(array $optionsData): array {
    $warnings = [];
    $preset = self::normalizePreset($optionsData['permissionPreset'] ?? null);
    $options = $optionsData;
    $options['permissionPreset'] = $preset;
    $normalizedMode = self::normalizePermissionMode($options['permissionMode'] ?? null);
    if ($normalizedMode === null) {
      unset($options['permissionMode']);
    }
    else {
      $options['permissionMode'] = $normalizedMode;
    }

    if ($preset === 'strict_deny') {
      $options['permissionMode'] = 'plan';
      return [
        'options' => $options,
        'warnings' => $warnings,
        'preset' => $preset,
      ];
    }

    if ($preset === 'ask') {
      $options['permissionMode'] = 'default';
      return [
        'options' => $options,
        'warnings' => $warnings,
        'preset' => $preset,
      ];
    }

    if ($preset === 'bridge_only' || $preset === 'bridge_plus_read') {
      $options['allowedTools'] = self::ensureList($options['allowedTools'] ?? null);
      if (!in_array('Bash', $options['allowedTools'], TRUE)) {
        $options['allowedTools'][] = 'Bash';
      }

      if (isset($options['disallowedTools']) && is_array($options['disallowedTools'])) {
        $options['disallowedTools'] = array_values(array_filter($options['disallowedTools'], static fn ($tool): bool => $tool !== 'Bash'));
      }

      if (!isset($options['permissionMode']) || trim((string) $options['permissionMode']) === '') {
        $options['permissionMode'] = 'default';
      }

      $allowPatterns = self::bridgeAllowPatterns($preset === 'bridge_plus_read');
      $options['settings'] = self::mergeAllowPatternsIntoSettings($options['settings'] ?? null, $allowPatterns, $warnings);
    }

    return [
      'options' => $options,
      'warnings' => $warnings,
      'preset' => $preset,
    ];
  }

  public static function formatPreview(array $optionsData, array $warnings = []): string {
    $preset = self::normalizePreset($optionsData['permissionPreset'] ?? null);
    $lines = [];
    $lines[] = 'Preset: ' . self::presetLabel($preset);
    $mode = trim((string) ($optionsData['permissionMode'] ?? ''));
    $lines[] = 'Permission mode: ' . ($mode !== '' ? $mode : 'CLI default');

    $allowedTools = self::ensureList($optionsData['allowedTools'] ?? null);
    $lines[] = 'Allowed tools: ' . (!empty($allowedTools) ? implode(', ', $allowedTools) : '(default)');

    $settingsRaw = trim((string) ($optionsData['settings'] ?? ''));
    if ($settingsRaw !== '' && $settingsRaw[0] === '{') {
      $decoded = json_decode($settingsRaw, TRUE);
      if (is_array($decoded) && isset($decoded['permissions']['allow']) && is_array($decoded['permissions']['allow'])) {
        $lines[] = 'Permission allow patterns:';
        foreach ($decoded['permissions']['allow'] as $pattern) {
          $lines[] = '  - ' . (string) $pattern;
        }
      }
    }
    elseif ($settingsRaw !== '') {
      $lines[] = 'Settings source: ' . $settingsRaw;
    }
    else {
      $lines[] = 'Permission allow patterns: (none)';
    }

    if (!empty($warnings)) {
      $lines[] = 'Warnings:';
      foreach ($warnings as $warning) {
        $lines[] = '  - ' . $warning;
      }
    }

    return implode("\n", $lines);
  }

  private static function mergeAllowPatternsIntoSettings(mixed $settingsRaw, array $allowPatterns, array &$warnings): string {
    $settingsValue = trim((string) $settingsRaw);
    $settingsObj = [];

    if ($settingsValue !== '') {
      if ($settingsValue[0] === '{') {
        $decoded = json_decode($settingsValue, TRUE);
        if (is_array($decoded)) {
          $settingsObj = $decoded;
        }
        else {
          $warnings[] = 'Permission preset could not parse existing settings JSON; starting from a new permissions object.';
        }
      }
      else {
        $warnings[] = 'Permission preset cannot merge into settings file path. Use inline JSON settings to inspect/edit the allowlist.';
        return $settingsValue;
      }
    }

    if (!isset($settingsObj['permissions']) || !is_array($settingsObj['permissions'])) {
      $settingsObj['permissions'] = [];
    }
    $existingAllow = $settingsObj['permissions']['allow'] ?? [];
    if (!is_array($existingAllow)) {
      $existingAllow = [];
    }

    foreach ($allowPatterns as $pattern) {
      if (!in_array($pattern, $existingAllow, TRUE)) {
        $existingAllow[] = $pattern;
      }
    }

    $settingsObj['permissions']['allow'] = array_values($existingAllow);
    return (string) json_encode($settingsObj, JSON_UNESCAPED_SLASHES);
  }

  private static function bridgeAllowPatterns(bool $includeReadHelpers): array {
    $patterns = [
      'Bash(drush:ai-claude-agent-sdk:tool-run*)',
      'Bash(drush ai-claude-agent-sdk:tool-run *)',
      'Bash(ddev:drush ai-claude-agent-sdk:tool-run*)',
      'Bash(ddev drush ai-claude-agent-sdk:tool-run *)',
      'Bash(ddev:exec drush ai-claude-agent-sdk:tool-run*)',
      'Bash(ddev exec drush ai-claude-agent-sdk:tool-run *)',
    ];

    if ($includeReadHelpers) {
      $patterns[] = 'Bash(ls:*)';
      $patterns[] = 'Bash(pwd)';
    }

    return $patterns;
  }

  private static function ensureList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $item) {
      if (!is_string($item)) {
        continue;
      }
      $item = trim($item);
      if ($item === '') {
        continue;
      }
      $out[$item] = $item;
    }
    return array_values($out);
  }

  private static function normalizePreset(mixed $value): string {
    $preset = trim((string) $value);
    if ($preset === '') {
      return 'none';
    }
    $allowed = [
      'none',
      'strict_deny',
      'ask',
      'bridge_only',
      'bridge_plus_read',
    ];
    return in_array($preset, $allowed, TRUE) ? $preset : 'none';
  }

  private static function presetLabel(string $preset): string {
    return match ($preset) {
      'strict_deny' => 'Strict deny',
      'ask' => 'Ask',
      'bridge_only' => 'Allow bridge command only',
      'bridge_plus_read' => 'Allow bridge command + read helpers',
      default => 'Manual / unchanged',
    };
  }

}
