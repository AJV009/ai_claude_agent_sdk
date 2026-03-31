<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Autocomplete controller for executor user selection.
 *
 * Returns active users matching the query string. When the executor
 * restriction toggle is enabled, users lacking the 'act as ai executor'
 * permission get a decorated label in the dropdown. The submitted value
 * is always clean ("username (uid)") so entity_autocomplete can parse it.
 */
class ExecutorAutocompleteController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * Note: Cannot use constructor property promotion with `readonly` here
   * because ControllerBase already declares $entityTypeManager and
   * $configFactory as non-readonly properties.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Handles the autocomplete request.
   */
  public function handleAutocomplete(Request $request): JsonResponse {
    $matches = [];
    $input = $request->query->get('q', '');

    if (strlen($input) < 1) {
      return new JsonResponse($matches);
    }

    $restrictByPermission = $this->configFactory
      ->get('ai_claude_agent_sdk.settings')
      ->get('restrict_executor_by_permission');

    $userStorage = $this->entityTypeManager->getStorage('user');
    $query = $userStorage->getQuery()
      ->condition('status', 1)
      ->condition('name', $input, 'CONTAINS')
      ->accessCheck(TRUE)
      ->range(0, 25);
    $uids = $query->execute();

    if (empty($uids)) {
      return new JsonResponse($matches);
    }

    $users = $userStorage->loadMultiple($uids);

    foreach ($users as $user) {
      $name = $user->getDisplayName();
      $value = $name . ' (' . $user->id() . ')';
      $label = $name;

      if ($restrictByPermission && !$user->hasPermission('act as ai executor')) {
        $label = $name . " (ineligible — missing 'Act as AI executor' permission)";
      }

      $matches[] = [
        'value' => $value,
        'label' => $label,
      ];
    }

    return new JsonResponse($matches);
  }

}
