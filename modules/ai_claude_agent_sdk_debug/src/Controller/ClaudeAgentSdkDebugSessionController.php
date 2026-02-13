<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Drupal\ai_claude_agent_sdk_debug\Session\SessionFileStore;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkDebugSessionController extends ControllerBase {

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly SessionFileStore $sessionFileStore,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk_debug.session_file_store'),
    );
  }

  public function page(string $session_id): array {
    $metadata = $this->sessionTracker->get($session_id) ?? [];
    $files = $this->sessionFileStore->listSessionFiles($session_id);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['claude-agent-sdk-session-page']],
      '#attached' => [
        'library' => [
          'ai_claude_agent_sdk_debug/session',
        ],
      ],
      'summary' => [
        '#type' => 'details',
        '#title' => $this->t('Session summary'),
        '#open' => TRUE,
      ],
    ];

    $summaryItems = [
      $this->t('Session ID: <code>@id</code>', ['@id' => $session_id]),
      $this->t('Mode: @mode', ['@mode' => (string) ($metadata['mode'] ?? 'n/a')]),
      $this->t('Source: @source', ['@source' => (string) ($metadata['source'] ?? 'n/a')]),
      $this->t('Resume: @resume', ['@resume' => (string) ($metadata['resume'] ?? 'n/a')]),
      $this->t('UID: @uid', ['@uid' => isset($metadata['uid']) ? (string) $metadata['uid'] : 'n/a']),
      $this->t('First seen: @value', ['@value' => isset($metadata['first_seen']) ? date('c', (int) $metadata['first_seen']) : 'n/a']),
      $this->t('Last seen: @value', ['@value' => isset($metadata['last_seen']) ? date('c', (int) $metadata['last_seen']) : 'n/a']),
    ];
    $build['summary']['list'] = [
      '#theme' => 'item_list',
      '#items' => $summaryItems,
    ];

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin: 12px 0;'],
      'delete' => [
        '#type' => 'link',
        '#title' => $this->t('Delete this session'),
        '#url' => Url::fromRoute('ai_claude_agent_sdk_debug.session_delete_confirm', ['session_id' => $session_id]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to processes'),
        '#url' => Url::fromRoute('ai_claude_agent_sdk_debug.processes'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    if (empty($files)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => $this->t('<p>No session JSONL files were found for this session ID.</p>'),
      ];
      return $build;
    }

    foreach ($files as $index => $file) {
      $suffix = $index + 1;
      $detailsKey = 'file_' . $suffix;
      $events = $this->parseJsonl((string) $file['content']);

      $build[$detailsKey] = [
        '#type' => 'details',
        '#title' => $this->t('Session file @num', ['@num' => (string) $suffix]),
        '#open' => $index === 0,
      ];
      $build[$detailsKey]['path'] = [
        '#type' => 'item',
        '#title' => $this->t('Path'),
        '#markup' => '<code>' . Html::escape((string) $file['path']) . '</code>',
      ];
      $build[$detailsKey]['meta'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Size: @size bytes', ['@size' => (string) $file['size']]),
          $this->t('Modified: @time', ['@time' => date('c', (int) $file['modified'])]),
          $this->t('Entries: @count', ['@count' => (string) count($events)]),
        ],
      ];

      $rows = [];
      foreach ($events as $event) {
        $rowClasses = [$event['is_primary'] ? 'claude-session-row--primary' : 'claude-session-row--secondary'];
        if ($event['is_duplicate']) {
          $rowClasses[] = 'claude-session-row--duplicate';
        }

        $timeShort = (string) ($event['timestamp_short'] ?? '');
        $timeFull = (string) ($event['timestamp'] ?? '');
        $timeMarkup = '<span title="' . Html::escape($timeFull) . '">' . Html::escape($timeShort) . '</span>';

        $rows[] = [
          'class' => $rowClasses,
          'data' => [
            (string) $event['line'],
            [
              'data' => [
                '#markup' => $timeMarkup,
              ],
            ],
            (string) $event['type'],
            [
              'data' => [
                '#markup' => $this->buildSummaryCellMarkup($event),
              ],
            ],
          ],
        ];
      }

      $build[$detailsKey]['readable'] = [
        '#type' => 'details',
        '#title' => $this->t('Readable view'),
        '#open' => TRUE,
      ];

      if (!empty($rows)) {
        $build[$detailsKey]['readable']['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('#'),
            $this->t('Time'),
            $this->t('Type'),
            $this->t('Summary'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No entries found.'),
        ];
      }
      else {
        $build[$detailsKey]['readable']['empty'] = [
          '#type' => 'item',
          '#title' => $this->t('Readable view'),
          '#markup' => $this->t('No parseable JSONL entries found.'),
        ];
      }

      $build[$detailsKey]['raw'] = [
        '#type' => 'details',
        '#title' => $this->t('Raw JSONL'),
        '#open' => FALSE,
      ];
      $build[$detailsKey]['raw']['content'] = [
        '#type' => 'textarea',
        '#value' => (string) $file['content'],
        '#rows' => 24,
        '#attributes' => [
          'readonly' => 'readonly',
          'style' => 'font-family: monospace;',
        ],
      ];
    }

    return $build;
  }

  private function parseJsonl(string $content): array {
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $events = [];

    foreach ($lines as $index => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      $decoded = json_decode($line, TRUE);
      if (!is_array($decoded)) {
        $events[] = [
          'line' => $index + 1,
          'timestamp' => '',
          'type' => 'invalid',
          'role' => '',
          'summary' => mb_substr($line, 0, 200),
          'full' => $line,
          'is_primary' => FALSE,
          'is_duplicate' => FALSE,
        ];
        continue;
      }

      $summaryData = $this->summarizeEvent($decoded);
      $events[] = [
        'line' => $index + 1,
        'timestamp' => $this->formatTimestamp($decoded['timestamp'] ?? null, FALSE),
        'timestamp_short' => $this->formatTimestamp($decoded['timestamp'] ?? null, TRUE),
        'type' => (string) ($decoded['type'] ?? ''),
        'role' => (string) (($decoded['message']['role'] ?? $decoded['userType'] ?? '') ?: ''),
        'summary' => $summaryData['summary'],
        'full' => $summaryData['full'],
        'is_primary' => $summaryData['is_primary'],
        'is_duplicate' => $summaryData['is_duplicate'],
      ];
    }

    return $events;
  }

  private function formatTimestamp(mixed $value, bool $short = FALSE): string {
    if (!is_string($value) || $value === '') {
      return '';
    }
    $time = strtotime($value);
    if ($time === FALSE) {
      return $value;
    }
    return $short ? date('H:i:s', $time) : date('Y-m-d H:i:s', $time);
  }

  private function summarizeEvent(array $event): array {
    $type = (string) ($event['type'] ?? '');

    if ($type === 'queue-operation') {
      $op = (string) ($event['operation'] ?? '');
      $summary = $op !== '' ? 'queue operation: ' . $op : 'queue operation';
      return [
        'summary' => $summary,
        'full' => $summary,
        'is_primary' => FALSE,
        'is_duplicate' => FALSE,
      ];
    }

    if ($type === 'user') {
      $message = $event['message']['content'] ?? null;
      return $this->summarizeMessageContent($message);
    }

    if ($type === 'assistant') {
      $message = $event['message']['content'] ?? null;
      return $this->summarizeMessageContent($message);
    }

    if (!empty($event['toolUseResult'])) {
      $full = (string) $event['toolUseResult'];
      return [
        'summary' => mb_substr($full, 0, 200),
        'full' => $full,
        'is_primary' => FALSE,
        'is_duplicate' => FALSE,
      ];
    }

    if (!empty($event['requestId'])) {
      $summary = 'request: ' . (string) $event['requestId'];
      return [
        'summary' => $summary,
        'full' => $summary,
        'is_primary' => FALSE,
        'is_duplicate' => FALSE,
      ];
    }

    $full = json_encode($event) ?: '';
    return [
      'summary' => mb_substr($full, 0, 200),
      'full' => $full,
      'is_primary' => FALSE,
      'is_duplicate' => FALSE,
    ];
  }

  private function summarizeMessageContent(mixed $content): array {
    if (is_string($content)) {
      $isDuplicate = $this->isRepeatedPrompt($content);
      $display = $isDuplicate ? $this->firstRepeatedPromptLine($content) . ' [x2]' : $content;
      return [
        'summary' => mb_substr($display, 0, 200),
        'full' => $content,
        'is_primary' => TRUE,
        'is_duplicate' => $isDuplicate,
      ];
    }

    if (!is_array($content)) {
      return [
        'summary' => '',
        'full' => '',
        'is_primary' => FALSE,
        'is_duplicate' => FALSE,
      ];
    }

    $parts = [];
    $fullParts = [];
    $hasToolEntries = FALSE;
    $textOnly = TRUE;
    foreach ($content as $item) {
      if (!is_array($item)) {
        continue;
      }
      $itemType = (string) ($item['type'] ?? '');
      if ($itemType === 'text') {
        $text = (string) ($item['text'] ?? '');
        $parts[] = mb_substr($text, 0, 160);
        $fullParts[] = $text;
      }
      elseif ($itemType === 'tool_use') {
        $hasToolEntries = TRUE;
        $textOnly = FALSE;
        $toolName = (string) ($item['name'] ?? 'tool');
        $toolInput = '';
        if (is_array($item['input'] ?? null)) {
          $toolInput = (string) (($item['input']['command'] ?? $item['input']['description'] ?? ''));
        }
        $toolText = 'tool_use ' . $toolName . ($toolInput !== '' ? ': ' . mb_substr($toolInput, 0, 120) : '');
        $parts[] = $toolText;
        $fullParts[] = $toolText;
      }
      elseif ($itemType === 'tool_result') {
        $hasToolEntries = TRUE;
        $textOnly = FALSE;
        $toolResult = 'tool_result: ' . mb_substr((string) ($item['content'] ?? ''), 0, 120);
        $parts[] = $toolResult;
        $fullParts[] = $toolResult;
      }
      else {
        $textOnly = FALSE;
      }
    }

    $summary = mb_substr(implode(' | ', $parts), 0, 260);
    $full = implode(' | ', $fullParts);
    $isPrimary = !$hasToolEntries && $textOnly && $full !== '';
    $isDuplicate = $isPrimary ? $this->isRepeatedPrompt($full) : FALSE;

    return [
      'summary' => $summary,
      'full' => $full,
      'is_primary' => $isPrimary,
      'is_duplicate' => $isDuplicate,
    ];
  }

  private function isRepeatedPrompt(string $content): bool {
    $trimmed = trim($content);
    if ($trimmed === '') {
      return FALSE;
    }

    $parts = preg_split('/\r\n|\r|\n/', $trimmed);
    if (!is_array($parts) || count($parts) !== 2) {
      return FALSE;
    }

    return trim($parts[0]) !== '' && trim($parts[0]) === trim($parts[1]);
  }

  private function firstRepeatedPromptLine(string $content): string {
    $parts = preg_split('/\r\n|\r|\n/', trim($content));
    if (!is_array($parts) || empty($parts)) {
      return trim($content);
    }
    return trim((string) $parts[0]);
  }

  private function buildSummaryCellMarkup(array $event): string {
    $summary = (string) ($event['summary'] ?? '');
    $full = (string) ($event['full'] ?? '');
    $isDuplicate = (bool) ($event['is_duplicate'] ?? FALSE);

    $markup = '<div class="claude-session-summary-cell">';
    $markup .= '<div class="claude-session-summary-preview">' . Html::escape($summary) . '</div>';

    if ($full !== '' && $full !== $summary) {
      $markup .= '<details class="claude-session-show-full"><summary>more</summary><pre class="claude-session-summary-full">' . Html::escape($full) . '</pre></details>';
    }

    $markup .= '</div>';
    return $markup;
  }

}
