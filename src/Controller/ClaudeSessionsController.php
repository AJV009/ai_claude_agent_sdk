<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Claude Sessions page.
 */
class ClaudeSessionsController extends ControllerBase {

  public function __construct(
    protected readonly ClaudeBridgeService $bridge,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.bridge'),
    );
  }

  /**
   * Renders the Claude Sessions page as a standard Drupal admin table.
   */
  public function page(): array {
    $build = [];
    $build['#attached']['library'][] = 'ai_claude_agent_sdk/sessions';

    try {
      $sessionsData = $this->bridge->fetchSessions(100);
      $activeData = $this->bridge->fetchActiveSessions();
    }
    catch (\Exception $e) {
      $build['error'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#markup' => $this->t('Unable to connect to sidecar. Check that it is running.'),
        ],
      ];
      return $build;
    }

    $sessions = $sessionsData['sessions'] ?? [];
    $activeList = $activeData['active'] ?? [];

    // Index active sessions by sessionId for quick lookup.
    $activeMap = [];
    foreach ($activeList as $active) {
      $id = $active['sessionId'] ?? $active['queryId'] ?? '';
      if ($id) {
        $activeMap[$id] = $active;
      }
    }

    // Build the table header (keyed to match row keys).
    $header = [
      'status' => $this->t('Status'),
      'session' => $this->t('Session'),
      'type' => $this->t('Type'),
      'activity' => $this->t('Last Activity'),
      'messages' => $this->t('Messages'),
      'operations' => $this->t('Operations'),
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => $this->t('No sessions yet. <a href=":url">Open the terminal</a> to start one.', [
        ':url' => Url::fromRoute('ai_claude_agent_sdk.terminal')->toString(),
      ]),
    ];

    $rowIndex = 0;

    // Render background queries first.
    $bgQueries = array_filter($activeList, fn($a) => ($a['type'] ?? '') === 'background_query');
    foreach ($bgQueries as $query) {
      $queryId = $query['queryId'] ?? '';
      $status = $query['status'] ?? 'running';

      $operations = [];
      if ($status === 'running' && $queryId) {
        $operations['interrupt'] = [
          'title' => $this->t('Interrupt'),
          'url' => Url::fromRoute('ai_claude_agent_sdk.query_abort', ['query_id' => $queryId]),
        ];
      }
      if (!empty($query['sessionId'])) {
        $operations['view'] = [
          'title' => $this->t('View Messages'),
          'url' => Url::fromRoute('ai_claude_agent_sdk.session_messages', ['session_id' => $query['sessionId']]),
        ];
      }

      $label = $query['skillId'] ? 'Skill: ' . $query['skillId'] : 'Background query';

      $build['table'][$rowIndex]['status'] = ['#markup' => '<span class="session-status session-status--' . $status . '">' . ucfirst($status) . '</span>'];
      $build['table'][$rowIndex]['session'] = ['#markup' => htmlspecialchars($label)];
      $build['table'][$rowIndex]['type'] = ['#markup' => $this->t('Background')];
      $build['table'][$rowIndex]['activity'] = ['#markup' => $this->formatTimeAgo($query['startedAt'] ?? '')];
      $build['table'][$rowIndex]['messages'] = ['#markup' => '-'];
      $build['table'][$rowIndex]['operations'] = [
        'data' => ['#type' => 'operations', '#links' => $operations],
      ];
      $rowIndex++;
    }

    // Render SDK sessions.
    foreach ($sessions as $session) {
      $sessionId = $session['sessionId'] ?? '';
      $isActive = isset($activeMap[$sessionId]);
      $status = $isActive ? 'running' : 'inactive';
      $statusLabel = $isActive ? $this->t('Active') : $this->t('Inactive');

      $label = $session['summary'] ?? $session['name'] ?? $sessionId;
      if (mb_strlen($label) > 60) {
        $label = mb_substr($label, 0, 60) . '...';
      }

      $messageCount = $session['messageCount'] ?? '-';
      $lastModified = $session['lastModified'] ?? $session['createdAt'] ?? $session['updatedAt'] ?? '';

      $operations = [];
      if ($sessionId) {
        $operations['view'] = [
          'title' => $this->t('View Messages'),
          'url' => Url::fromRoute('ai_claude_agent_sdk.session_messages', ['session_id' => $sessionId]),
        ];
        $operations['terminal'] = [
          'title' => $this->t('Open Terminal'),
          'url' => Url::fromRoute('ai_claude_agent_sdk.terminal', [], ['fragment' => 'session=' . $sessionId]),
        ];
      }

      $build['table'][$rowIndex]['status'] = ['#markup' => '<span class="session-status session-status--' . $status . '">' . $statusLabel . '</span>'];
      $build['table'][$rowIndex]['session'] = ['#markup' => htmlspecialchars($label)];
      $build['table'][$rowIndex]['type'] = ['#markup' => $this->t('SDK Session')];
      $build['table'][$rowIndex]['activity'] = ['#markup' => $this->formatTimeAgo($lastModified)];
      $build['table'][$rowIndex]['messages'] = ['#markup' => (string) $messageCount];
      $build['table'][$rowIndex]['operations'] = [
        'data' => ['#type' => 'operations', '#links' => $operations],
      ];
      $rowIndex++;
    }

    return $build;
  }

  /**
   * Renders session message detail page.
   */
  public function messages(string $session_id): array {
    $build = [];

    try {
      $data = $this->bridge->fetchSessionMessages($session_id);
    }
    catch (\Exception $e) {
      $build['error'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#markup' => $this->t('Unable to load session messages.'),
        ],
      ];
      return $build;
    }

    if (empty($data) || !empty($data['error'])) {
      $build['empty'] = [
        '#markup' => $this->t('Session not found.'),
      ];
      return $build;
    }

    // Session metadata.
    $meta = [];
    if (!empty($data['model'])) {
      $meta[] = $this->t('Model: @model', ['@model' => $data['model']]);
    }
    if (isset($data['costUsd'])) {
      $meta[] = $this->t('Cost: $@cost', ['@cost' => number_format((float) $data['costUsd'], 4)]);
    }
    if (!empty($data['messageCount'])) {
      $meta[] = $this->t('Messages: @count', ['@count' => $data['messageCount']]);
    }

    if ($meta) {
      $build['meta'] = [
        '#markup' => '<p>' . implode(' &middot; ', $meta) . '</p>',
      ];
    }

    // Messages table.
    $messages = $data['messages'] ?? $data['session']['messages'] ?? [];
    $header = [$this->t('Role'), $this->t('Content')];
    $rows = [];

    foreach ($messages as $msg) {
      $inner = $msg['message'] ?? $msg;
      $role = $inner['role'] ?? $msg['type'] ?? 'unknown';

      $rawContent = $inner['content'] ?? '';
      if (is_array($rawContent)) {
        $textParts = array_filter($rawContent, fn($b) => ($b['type'] ?? '') === 'text');
        $content = implode("\n", array_map(fn($b) => $b['text'] ?? '', $textParts));
      }
      else {
        $content = (string) $rawContent;
      }

      if (mb_strlen($content) > 2000) {
        $content = mb_substr($content, 0, 2000) . '...';
      }

      $rows[] = [
        ucfirst($role),
        ['data' => ['#markup' => '<pre style="white-space: pre-wrap; margin: 0;">' . htmlspecialchars($content) . '</pre>']],
      ];
    }

    $build['messages_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No messages in this session.'),
    ];

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to sessions'),
      '#url' => Url::fromRoute('ai_claude_agent_sdk.sessions'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

  /**
   * Formats a timestamp as a human-readable relative time.
   */
  private function formatTimeAgo(string|int $timestamp): string|\Stringable {
    if (!$timestamp) {
      return '';
    }

    if (is_int($timestamp)) {
      $time = $timestamp;
    }
    else {
      $time = strtotime($timestamp);
      if ($time === FALSE) {
        return $timestamp;
      }
    }

    $diff = time() - $time;
    if ($diff < 60) {
      return $this->t('just now');
    }
    if ($diff < 3600) {
      $minutes = (int) floor($diff / 60);
      return $this->t('@count min ago', ['@count' => $minutes]);
    }
    if ($diff < 86400) {
      $hours = (int) floor($diff / 3600);
      return $this->t('@count hr ago', ['@count' => $hours]);
    }
    $days = (int) floor($diff / 86400);
    return $this->t('@count day(s) ago', ['@count' => $days]);
  }

}
