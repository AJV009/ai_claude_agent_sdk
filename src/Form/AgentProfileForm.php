<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\ai_claude_agent_sdk\Execution\ValidationResult;
use Drupal\ai_claude_agent_sdk\Service\ExecutionPrincipalResolver;
use Drupal\ai_claude_agent_sdk\Service\SecurityTierManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding/editing Agent Profile config entities.
 */
class AgentProfileForm extends EntityForm {

  /**
   * The security tier manager.
   */
  protected SecurityTierManager $tierManager;

  /**
   * The execution principal resolver.
   */
  protected ExecutionPrincipalResolver $principalResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->tierManager = $container->get('ai_claude_agent_sdk.security_tier_manager');
    $instance->principalResolver = $container->get('ai_claude_agent_sdk.execution_principal_resolver');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile */
    $profile = $this->entity;

    // Basic settings.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $profile->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $profile->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_claude_agent_sdk\Entity\AgentProfile::load',
      ],
      '#disabled' => !$profile->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $profile->getDescription(),
    ];

    // Agent behavior.
    $form['behavior'] = [
      '#type' => 'details',
      '#title' => $this->t('Agent behavior'),
      '#open' => TRUE,
    ];

    $form['behavior']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $profile->getSystemPrompt(),
      '#rows' => 6,
    ];

    $form['behavior']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'opus' => $this->t('Opus'),
        'sonnet' => $this->t('Sonnet'),
        'haiku' => $this->t('Haiku'),
      ],
      '#default_value' => $profile->getModel(),
      '#required' => TRUE,
    ];

    $form['behavior']['max_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Max turns'),
      '#default_value' => $profile->getMaxTurns(),
      '#min' => 1,
      '#step' => 1,
    ];

    // Security tier selection.
    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security tier'),
      '#open' => TRUE,
    ];

    $form['security']['security_tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Security tier'),
      '#description' => $this->t('Controls permission mode, sandbox, HTTP policy hooks, and tool access. Select "Custom" for full manual control.'),
      '#options' => [
        'strict' => $this->t('Strict (production, scheduled tasks, untrusted)'),
        'standard' => $this->t('Standard (chatbot, supervised agents)'),
        'permissive' => $this->t('Permissive (development, trusted users)'),
        'custom' => $this->t('Custom (manual configuration)'),
      ],
      '#default_value' => $profile->getSecurityTier(),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::tierPreviewAjaxCallback',
        'wrapper' => 'tier-preview-wrapper',
        'event' => 'change',
      ],
    ];

    // Generated settings preview (read-only, shown for non-custom tiers).
    // Use the form_state value if available (AJAX rebuild), else saved value.
    $currentTier = $form_state->getValue('security_tier') ?: $profile->getSecurityTier();

    $form['security']['tier_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'tier-preview-wrapper',
        'class' => ['tier-settings-preview'],
      ],
    ];

    if ($currentTier !== 'custom') {
      $tierDescription = $this->tierManager->describeTier($currentTier);
      $previewRows = [];
      foreach ($tierDescription as $label => $value) {
        $previewRows[] = $this->t('<strong>@label:</strong> @value', [
          '@label' => $label,
          '@value' => $value,
        ]);
      }

      $form['security']['tier_preview']['label'] = [
        '#markup' => '<h4>' . $this->t('Generated Settings (managed by tier)') . '</h4>',
      ];

      $form['security']['tier_preview']['settings'] = [
        '#theme' => 'item_list',
        '#items' => $previewRows,
      ];

      $form['security']['tier_preview']['notice'] = [
        '#markup' => '<em>' . $this->t('These settings are managed by the security tier. Select "Custom" for full manual control.') . '</em>',
      ];
    }

    // Manual permission_mode: only visible for Custom tier.
    $form['security']['permission_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission mode'),
      '#options' => [
        'default' => $this->t('Default'),
        'plan' => $this->t('Plan'),
        'acceptEdits' => $this->t('Accept edits'),
        'delegate' => $this->t('Delegate'),
        'bypassPermissions' => $this->t('Bypass permissions'),
      ],
      '#default_value' => $profile->getPermissionMode(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['sandbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filesystem sandbox'),
      '#description' => $this->t('Isolate filesystem access.'),
      '#default_value' => $profile->getSandbox(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['sandbox_network'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Network isolation'),
      '#description' => $this->t('Isolate network access inside the sandbox.'),
      '#default_value' => $profile->getSandboxNetwork(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['hook_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('HTTP policy hook'),
      '#description' => $this->t('Controls whether tool calls are evaluated by the Drupal policy endpoint. "Enforced" blocks disallowed calls. "Logging" records but does not block.'),
      '#options' => [
        '' => $this->t('Disabled'),
        'logging' => $this->t('Logging only'),
        'enforced' => $this->t('Enforced'),
      ],
      '#default_value' => $profile->getHookMode(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['disable_bypass_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable bypass permissions mode'),
      '#description' => $this->t('Prevent Claude from using bypassPermissions mode.'),
      '#default_value' => $profile->getDisableBypassMode(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['managed_rules_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Managed rules only'),
      '#description' => $this->t('Only allow permission rules managed by the profile. Ignores local .claude/settings.json rules.'),
      '#default_value' => $profile->getManagedRulesOnly(),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['bash_allow_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Bash allow patterns'),
      '#description' => $this->t('One pattern per line. Example: <code>Bash(ls:*)</code>, <code>Bash(cat:*)</code>'),
      '#default_value' => implode("\n", $profile->getBashAllowPatterns()),
      '#rows' => 4,
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['security']['bash_deny_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Bash deny patterns'),
      '#description' => $this->t('One pattern per line. Example: <code>Bash(rm -rf *)</code>, <code>Bash(git push --force*)</code>'),
      '#default_value' => implode("\n", $profile->getBashDenyPatterns()),
      '#rows' => 4,
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    // Tools.
    $form['tools'] = [
      '#type' => 'details',
      '#title' => $this->t('Tools'),
      '#states' => [
        'visible' => [
          ':input[name="security_tier"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['tools']['allowed_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed tools'),
      '#description' => $this->t('One tool name per line. Leave empty to allow all.'),
      '#default_value' => implode("\n", $profile->getAllowedTools()),
      '#rows' => 4,
    ];

    $form['tools']['denied_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Denied tools'),
      '#description' => $this->t('One tool name per line.'),
      '#default_value' => implode("\n", $profile->getDeniedTools()),
      '#rows' => 4,
    ];

    // MCP Servers.
    $form['mcp'] = [
      '#type' => 'details',
      '#title' => $this->t('MCP Servers'),
    ];

    $mcp_lines = [];
    foreach ($profile->getMcpServers() as $server) {
      $mcp_lines[] = $server['name'] . '|' . $server['transport'] . '|' . $server['url'];
    }

    $form['mcp']['mcp_servers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP server connections'),
      '#description' => $this->t('One server per line in format: <code>name|transport|url</code> (e.g. <code>drupal|http|/_mcp</code>).'),
      '#default_value' => implode("\n", $mcp_lines),
      '#rows' => 4,
    ];

    // Environment.
    $form['environment'] = [
      '#type' => 'details',
      '#title' => $this->t('Environment'),
    ];

    $form['environment']['working_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Working directory'),
      '#default_value' => $profile->getWorkingDirectory(),
    ];

    $form['environment']['allowed_directories'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed directories'),
      '#description' => $this->t('One directory path per line.'),
      '#default_value' => implode("\n", $profile->getAllowedDirectories()),
      '#rows' => 3,
    ];

    // Execution identity.
    $form['execution'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution identity'),
      '#open' => TRUE,
    ];

    $form['execution']['interactive_notice'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="execution_modality"]' => ['value' => 'interactive'],
        ],
      ],
      'message' => [
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('In interactive mode, the logged-in user\'s identity is used for execution. The executor account setting only applies to background and outside-in modalities.')
          . '</div>',
      ],
    ];

    // Wrap executor field + preview in a container with #states so both
    // hide when modality is 'interactive'. #states on the composite
    // user_reference_autocomplete element itself doesn't work because
    // Drupal's states JS doesn't process #tree composite wrappers.
    $form['execution']['executor_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'invisible' => [
          ':input[name="execution_modality"]' => ['value' => 'interactive'],
        ],
      ],
    ];

    $form['execution']['executor_wrapper']['executor_uid'] = [
      '#type' => 'user_reference_autocomplete',
      '#title' => $this->t('Executor account'),
      '#description' => $this->t(
        "The Drupal user account for background and scheduled executions. "
        . "This user's roles determine what tools can do when calling back into Drupal. "
        . "Interactive sessions use the logged-in user instead."
      ),
      '#default_value' => $profile->getExecutorUid(),
      '#selection_handler' => 'executor_user_selection',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#autocomplete_route_name' => 'ai_claude_agent_sdk.executor_autocomplete',
      '#ajax' => [
        'callback' => '::executorPreviewAjaxCallback',
        'wrapper' => 'executor-preview-wrapper',
        'event' => 'change',
      ],
    ];

    // Executor preview: always render the container so the AJAX wrapper
    // exists in the DOM even when no executor is selected.
    $form['execution']['executor_wrapper']['executor_preview'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'executor-preview-wrapper'],
    ];

    // Load executor for preview. During AJAX rebuild, read the raw user
    // input because #element_validate hasn't collapsed the composite value
    // yet when form() runs.
    $userInput = $form_state->getUserInput();
    if (!empty($userInput['executor_uid']['value'])) {
      $executorUid = (int) $userInput['executor_uid']['value'];
    }
    else {
      $executorUid = (int) ($profile->getExecutorUid() ?? 0);
    }
    $executorUser = NULL;
    if ($executorUid > 0) {
      $executorUser = $this->entityTypeManager->getStorage('user')->load($executorUid);
    }

    if ($executorUid > 0 && !$executorUser) {
      // UID was set but entity load returned NULL — user was deleted.
      $form['execution']['executor_wrapper']['executor_preview']['error'] = [
        '#markup' => '<p style="color: red; font-weight: bold;">'
          . $this->t('Selected user (uid: @uid) does not exist.', ['@uid' => $executorUid])
          . '</p>',
      ];
    }
    elseif ($executorUser) {
      // Build ValidationResult directly from the loaded user — cannot use
      // principalResolver->validate($profile) because during AJAX rebuild
      // the profile entity still has the old saved executor UID.
      if ($executorUser->isBlocked()) {
        $validation = ValidationResult::invalid(
          (string) $this->t('Executor user @name is blocked.', [
            '@name' => $executorUser->getAccountName(),
          ])
        );
      }
      else {
        $validation = ValidationResult::valid($executorUser);
      }

      $form['execution']['executor_wrapper']['executor_preview']['details'] = [
        '#type' => 'details',
        '#title' => $this->t('Account Details'),
        '#open' => TRUE,
      ];
      $form['execution']['executor_wrapper']['executor_preview']['details']['info'] = [
        '#markup' => $this->buildExecutorPreview($executorUser, $validation),
      ];

      // Show eligibility status when restriction is enabled.
      $restrictByPermission = $this->config('ai_claude_agent_sdk.settings')
        ->get('restrict_executor_by_permission');

      if ($restrictByPermission) {
        if ($executorUser->hasPermission('act as ai executor')) {
          $form['execution']['executor_wrapper']['executor_preview']['eligibility'] = [
            '#markup' => '<div class="messages messages--status">'
              . $this->t('Eligible as executor.')
              . '</div>',
          ];
        }
        else {
          $form['execution']['executor_wrapper']['executor_preview']['eligibility'] = [
            '#markup' => '<div class="messages messages--error">'
              . $this->t("Ineligible — missing 'Act as AI executor' permission. Assign it via People > Permissions or add a role that includes it.")
              . '</div>',
          ];
        }
      }
    }
    else {
      // No executor selected (uid=0 or empty).
      $form['execution']['executor_wrapper']['executor_preview']['empty'] = [
        '#markup' => '<p><em>' . $this->t('No executor account selected.') . '</em></p>',
      ];
    }

    $form['execution']['execution_modality'] = [
      '#type' => 'select',
      '#title' => $this->t('Execution modality'),
      '#options' => [
        'interactive' => $this->t('Interactive'),
        'background' => $this->t('Background'),
        'outside_in' => $this->t('Outside-in'),
      ],
      '#default_value' => $profile->getExecutionModality(),
      '#weight' => -10,
    ];

    // Advanced.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];

    $form['advanced']['extra_args'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra CLI arguments'),
      '#description' => $this->t('One argument per line.'),
      '#default_value' => implode("\n", $profile->getExtraArgs()),
      '#rows' => 3,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate executor UID if set.
    // Value may be composite array (from the element) or int (after normalize).
    $executorUid = $form_state->getValue('executor_uid');
    if (is_array($executorUid)) {
      $executorUid = (int) ($executorUid['value'] ?? 0);
    }
    $executorUid = (int) $executorUid;
    if (!empty($executorUid)) {
      $uid = $executorUid;
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user) {
        $form_state->setErrorByName('executor_uid', $this->t('The selected executor user does not exist.'));
        return;
      }
      // Only block on blocked users during final save, not AJAX preview
      // rebuilds. During AJAX, the blocked state is shown in the preview
      // container instead (via ValidationResult::invalid).
      $triggeringElement = $form_state->getTriggeringElement();
      $isAjaxPreview = $triggeringElement && ($triggeringElement['#name'] ?? '') === 'executor_uid';
      if ($user->isBlocked() && !$isAjaxPreview) {
        $form_state->setErrorByName('executor_uid', $this->t(
          'The selected executor user %name is blocked. Blocked users cannot be used for execution.',
          ['%name' => $user->getAccountName()]
        ));
      }

      // Check executor eligibility when restriction is enabled.
      $restrictByPermission = $this->config('ai_claude_agent_sdk.settings')
        ->get('restrict_executor_by_permission');

      if ($restrictByPermission && !$user->isBlocked() && !$user->hasPermission('act as ai executor')) {
        $form_state->setErrorByName('executor_uid', $this->t(
          "The selected user @username does not have the 'Act as AI executor' permission. Grant it at /admin/people/permissions or assign a role that includes it.",
          ['@username' => $user->getDisplayName()]
        ));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // The composite element's #element_validate collapses to int, but during
    // AJAX rebuilds copyFormValuesToEntity may run before validation completes.
    // Ensure executor_uid is always int before the entity receives it.
    // The composite element keeps its value as an array throughout form
    // processing. Normalize to int here before the entity receives it.
    // The composite element value is an array with #tree. Extract the int
    // from raw user input (which is immutable across calls) and set it on the
    // entity directly — skip parent for this field to avoid TypeError on the
    // entity's typed int property.
    $userInput = $form_state->getUserInput();
    $normalizedUid = (int) ($userInput['executor_uid']['value'] ?? 0);
    // Remove from form_state so parent::copyFormValuesToEntity() skips it.
    $form_state->unsetValue('executor_uid');

    // Parse textarea values into arrays before they are copied to the entity,
    // since the entity properties are typed as arrays.
    $textarea_fields = [
      'allowed_tools',
      'denied_tools',
      'allowed_directories',
      'extra_args',
      'bash_allow_patterns',
      'bash_deny_patterns',
    ];
    foreach ($textarea_fields as $field) {
      $form_state->setValue($field, $this->textareaToArray($form_state->getValue($field)));
    }

    // Parse MCP servers (skip if already parsed to array of mappings).
    $mcp_raw = $form_state->getValue('mcp_servers');
    if (is_string($mcp_raw)) {
      $mcp_servers = [];
      $lines = $this->textareaToArray($mcp_raw);
      foreach ($lines as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) === 3) {
          $mcp_servers[] = [
            'name' => trim($parts[0]),
            'transport' => trim($parts[1]),
            'url' => trim($parts[2]),
          ];
        }
      }
      $form_state->setValue('mcp_servers', $mcp_servers);
    }

    parent::copyFormValuesToEntity($entity, $form, $form_state);

    // Set executor_uid directly on the entity (was excluded from parent above).
    $entity->set('executor_uid', $normalizedUid);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile */
    $profile = $this->entity;

    $status = $profile->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Agent profile %label created.', [
        '%label' => $profile->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Agent profile %label updated.', [
        '%label' => $profile->label(),
      ]));
    }

    $form_state->setRedirectUrl($profile->toUrl('collection'));
    return $status;
  }

  /**
   * AJAX callback: rebuilds the tier preview when the tier dropdown changes.
   */
  public function tierPreviewAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['security']['tier_preview'];
  }

  /**
   * AJAX callback: rebuilds the executor preview when the autocomplete changes.
   */
  public function executorPreviewAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['execution']['executor_wrapper']['executor_preview'];
  }

  /**
   * Builds an HTML preview of the executor account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The executor user account.
   * @param \Drupal\ai_claude_agent_sdk\Execution\ValidationResult $validation
   *   The validation result.
   *
   * @return string
   *   Rendered HTML markup for the preview.
   */
  protected function buildExecutorPreview(AccountInterface $account, ValidationResult $validation): string {
    $uid = $account->id();
    $name = $account->getAccountName();
    $status = $account->isBlocked() ? $this->t('Blocked') : $this->t('Active');
    $roles = implode(', ', $account->getRoles());

    $statusClass = $validation->isValid() ? 'color: green' : 'color: red';

    $html = '<div class="executor-preview">';
    $html .= '<p><strong>' . $this->t('User:') . '</strong> ' . htmlspecialchars($name) . ' (uid: ' . (int) $uid . ')</p>';
    $html .= '<p><strong>' . $this->t('Status:') . '</strong> <span style="' . $statusClass . '">' . $status . '</span></p>';
    $html .= '<p><strong>' . $this->t('Roles:') . '</strong> ' . htmlspecialchars($roles) . '</p>';

    if (!$validation->isValid() && $validation->message) {
      $html .= '<p style="color: red; font-weight: bold;">' . htmlspecialchars($validation->message) . '</p>';
    }

    $html .= '<p><em>' . $this->t(
      'Background and scheduled executions run as this user. Interactive sessions use the logged-in user.'
    ) . '</em></p>';

    $html .= '<p><strong>' . $this->t("This user's Drupal roles determine what tools can do when calling back into Drupal.") . '</strong></p>';

    $html .= '</div>';

    return $html;
  }

  /**
   * Converts a textarea value to an array of non-empty trimmed lines.
   *
   * @param string|null $value
   *   The textarea value.
   *
   * @return string[]
   *   Array of non-empty lines.
   */
  protected function textareaToArray(string|array|null $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    if (is_array($value)) {
      return $value;
    }
    return array_values(array_filter(
      array_map('trim', explode("\n", $value)),
      fn(string $line): bool => $line !== '',
    ));
  }

}
