<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_audit\Controller;

use Drupal\ai_claude_agent_sdk_audit\Form\AuditLogFilterForm;
use Drupal\ai_claude_agent_sdk_audit\Service\AuditLogger;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin pages for the audit log.
 */
class AuditLogController extends ControllerBase {

  /**
   * Rows per page.
   */
  private const PAGE_LIMIT = 50;

  public function __construct(
    private readonly AuditLogger $auditLogger,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk_audit.audit_logger'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the audit log overview page.
   */
  public function overview(Request $request): array {
    $build = [];

    // Clear log link.
    $build['clear'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear log messages'),
      '#url' => Url::fromRoute('ai_claude_agent_sdk_audit.clear'),
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];

    // Filter form.
    $build['filter_form'] = $this->formBuilder()->getForm(AuditLogFilterForm::class);

    // Get filters from session.
    $filters = AuditLogFilterForm::getSessionFilters($request);

    // Count total for pager.
    $total = $this->auditLogger->count($filters);

    // Initialize pager.
    $page = max(0, (int) $request->query->get('page', 0));
    $offset = $page * self::PAGE_LIMIT;

    // Query rows.
    $rows = $this->auditLogger->query($filters, self::PAGE_LIMIT, $offset);

    // Build table header.
    $header = [
      $this->t('Timestamp'),
      $this->t('Profile'),
      $this->t('Tool'),
      $this->t('Decision'),
      $this->t('Tier'),
      $this->t('Executor'),
      $this->t('Reason'),
    ];

    // Build table rows.
    $tableRows = [];
    foreach ($rows as $row) {
      $decisionClass = 'audit-decision--' . ($row['decision'] ?? 'deny');

      $executorDisplay = '';
      $uid = (int) ($row['executor_uid'] ?? 0);
      if ($uid > 0) {
        $user = $this->entityTypeManager()->getStorage('user')->load($uid);
        if ($user) {
          $executorDisplay = Link::fromTextAndUrl(
            $user->getDisplayName(),
            $user->toUrl(),
          )->toString();
        }
        else {
          $executorDisplay = (string) $uid;
        }
      }

      $reason = $row['reason'] ?? '';
      if (mb_strlen($reason) > 80) {
        $reason = mb_substr($reason, 0, 80) . '...';
      }

      $detailUrl = Url::fromRoute('ai_claude_agent_sdk_audit.detail', [
        'audit_entry_id' => $row['id'],
      ]);

      $tableRows[] = [
        ['data' => Link::fromTextAndUrl(
          $this->dateFormatter->format((int) $row['timestamp'], 'short'),
          $detailUrl,
        )->toRenderable()],
        $row['profile_id'] ?? '',
        $row['tool_name'] ?? '',
        [
          'data' => $row['decision'] ?? '',
          'class' => [$decisionClass],
        ],
        $row['tier'] ?? '',
        ['data' => ['#markup' => $executorDisplay]],
        $reason,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $tableRows,
      '#empty' => $this->t('No audit log entries found.'),
      '#attributes' => ['class' => ['audit-log-table']],
    ];

    // Pager.
    \Drupal::service('pager.manager')->createPager($total, self::PAGE_LIMIT);
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Renders a single audit log entry detail page.
   */
  public function detail(int $audit_entry_id): array {
    $entry = $this->auditLogger->load($audit_entry_id);
    if ($entry === NULL) {
      throw new NotFoundHttpException();
    }

    $rows = [];

    $rows[] = [$this->t('ID'), $entry['id']];
    $rows[] = [
      $this->t('Timestamp'),
      $this->dateFormatter->format((int) $entry['timestamp'], 'long'),
    ];

    // Profile — link to edit form if entity exists.
    $profileDisplay = $entry['profile_id'];
    $profile = $this->entityTypeManager()->getStorage('agent_profile')->load($entry['profile_id']);
    if ($profile) {
      $profileDisplay = Link::fromTextAndUrl(
        $profile->label(),
        $profile->toUrl('edit-form'),
      )->toString();
    }
    $rows[] = [$this->t('Profile'), ['data' => ['#markup' => $profileDisplay]]];

    $rows[] = [$this->t('Query ID'), $entry['query_id']];
    $rows[] = [$this->t('Run ID'), $entry['run_id'] ?: $this->t('N/A')];

    // Executor — link to user profile.
    $uid = (int) $entry['executor_uid'];
    $executorDisplay = (string) $uid;
    if ($uid > 0) {
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);
      if ($user) {
        $executorDisplay = Link::fromTextAndUrl(
          $user->getDisplayName() . ' (uid: ' . $uid . ')',
          $user->toUrl(),
        )->toString();
      }
    }
    $rows[] = [$this->t('Executor'), ['data' => ['#markup' => $executorDisplay]]];

    $rows[] = [$this->t('Tool'), $entry['tool_name']];

    // Decision with inline color.
    $decisionClass = 'audit-decision--' . $entry['decision'];
    $rows[] = [
      $this->t('Decision'),
      [
        'data' => $entry['decision'],
        'class' => [$decisionClass],
      ],
    ];

    $rows[] = [$this->t('Reason'), $entry['reason'] ?: $this->t('N/A')];
    $rows[] = [$this->t('Tier'), $entry['tier']];

    // Tool input — pretty-printed JSON.
    $toolInput = $entry['tool_input'] ?? '';
    $decoded = json_decode($toolInput, TRUE);
    $formatted = $decoded !== NULL
      ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      : $toolInput;
    $rows[] = [
      $this->t('Tool Input'),
      ['data' => ['#markup' => '<pre><code>' . htmlspecialchars($formatted) . '</code></pre>']],
    ];

    $build['detail'] = [
      '#type' => 'table',
      '#rows' => $rows,
      '#attributes' => ['class' => ['audit-log-detail']],
    ];

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to audit log'),
      '#url' => Url::fromRoute('ai_claude_agent_sdk_audit.overview'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

}
