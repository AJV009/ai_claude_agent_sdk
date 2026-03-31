<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Event\PolicyDecisionEvent;
use Drupal\ai_claude_agent_sdk\Service\PolicyEvaluatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * HTTP policy endpoint for Claude Code PreToolUse/PostToolUse hooks.
 *
 * Claude Code sends tool evaluation requests here. The controller validates
 * the HMAC token, loads the agent profile, evaluates the tool call against
 * the profile's security tier policy, logs the decision, and returns
 * allow/deny/ask.
 */
class ClaudePolicyController extends ControllerBase {

  public function __construct(
    private readonly PolicyEvaluatorInterface $policyEvaluator,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly KeyValueStoreExpirableInterface $nonceStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $nonceStore = $container->get('keyvalue.expirable')
      ->get('ai_claude_sdk_policy_nonces');
    return new static(
      $container->get('ai_claude_agent_sdk.policy_evaluator'),
      $container->get('event_dispatcher'),
      $nonceStore,
    );
  }

  /**
   * Evaluates a tool call against the profile's security tier policy.
   *
   * Expected headers:
   * - X-Policy-Token: HMAC authentication token
   * - X-Policy-Profile: Agent profile machine name
   *
   * Expected JSON body:
   * - tool_name: string
   * - tool_input: object
   * - query_id: string
   * - session_id: string
   * - hook_type: string (PreToolUse or PostToolUse)
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with 'decision' (allow/deny/ask) and 'reason'.
   */
  public function evaluate(Request $request): JsonResponse {
    // 1. Validate HMAC authentication token.
    $token = $request->headers->get('X-Policy-Token', '');
    $profileId = $request->headers->get('X-Policy-Profile', '');

    if ($profileId === '' || !$this->validatePolicyToken($token, $profileId)) {
      return new JsonResponse(['error' => 'unauthorized'], 403);
    }

    // 2. Parse the request payload.
    $content = $request->getContent();
    $payload = json_decode($content, TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'invalid payload'], 400);
    }

    $toolName = $payload['tool_name'] ?? '';
    $toolInput = $payload['tool_input'] ?? [];
    $queryId = $payload['query_id'] ?? '';
    $hookType = $payload['hook_type'] ?? 'PreToolUse';

    if ($toolName === '') {
      return new JsonResponse(['error' => 'missing tool_name'], 400);
    }

    // 3. Load the agent profile.
    $profile = $this->loadProfile($profileId);
    if ($profile === NULL) {
      return new JsonResponse(['error' => 'profile not found'], 404);
    }

    // 4. Evaluate the tool call against the tier policy.
    $decision = $this->policyEvaluator->evaluate($toolName, $toolInput, $hookType, $profile);

    // 5. Dispatch event for sub-modules (audit log, etc.).
    $this->eventDispatcher->dispatch(new PolicyDecisionEvent(
      profileId: $profileId,
      queryId: $queryId,
      runId: $request->headers->get('X-Policy-Run-Id', ''),
      executorUid: (int) $request->headers->get('X-Policy-Executor-Uid', '0'),
      toolName: $toolName,
      toolInput: $toolInput,
      decision: $decision->decision,
      reason: $decision->reason,
      tier: $profile->getSecurityTier(),
    ));

    // 6. Always log to dblog for standard Drupal log viewers.
    $this->getLogger('ai_claude_agent_sdk')->info(
      'Policy @decision for @tool (profile: @profile, tier: @tier): @reason',
      [
        '@decision' => $decision->decision,
        '@tool' => $toolName,
        '@profile' => $profileId,
        '@tier' => $profile->getSecurityTier(),
        '@reason' => $decision->reason,
      ]
    );

    // 7. Return the decision.
    $response = [
      'decision' => $decision->decision,
      'reason' => $decision->reason,
    ];
    if ($decision->wasAsk) {
      $response['was_ask'] = TRUE;
    }
    return new JsonResponse($response);
  }

  /**
   * Validates a nonce-based HMAC policy token.
   *
   * Token format: "nonce:hmac_sha256(profile_id:nonce, site_hash_salt)"
   * The nonce must exist in the expirable key-value store and must be
   * associated with the same profile ID.
   *
   * @param string $token
   *   The token from X-Policy-Token header. Format: "nonce:hmac".
   * @param string $profileId
   *   The profile ID from X-Policy-Profile header.
   *
   * @return bool
   *   TRUE if the token is valid.
   */
  private function validatePolicyToken(string $token, string $profileId): bool {
    if ($token === '' || $profileId === '') {
      return FALSE;
    }

    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) {
      return FALSE;
    }

    [$nonce, $hmac] = $parts;

    // Nonce must exist in the store.
    $storedProfile = $this->nonceStore->get($nonce);
    if ($storedProfile === NULL) {
      return FALSE;
    }

    // Stored profile must match request profile.
    if ($storedProfile !== $profileId) {
      return FALSE;
    }

    // Recompute HMAC and compare (constant-time).
    $salt = Settings::getHashSalt();
    $expected = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);

    return hash_equals($expected, $hmac);
  }

  /**
   * Generates a nonce-based HMAC policy token for a profile.
   *
   * This is a static helper so ClaudeBridgeService can generate tokens
   * when building hook config for the sidecar.
   *
   * @param string $profileId
   *   The profile machine name.
   * @param string $nonce
   *   A random hex nonce unique to this session.
   *
   * @return string
   *   Token in "nonce:hmac" format.
   */
  public static function generatePolicyToken(string $profileId, string $nonce): string {
    $salt = Settings::getHashSalt();
    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    return $nonce . ':' . $hmac;
  }

  /**
   * Loads an agent profile by machine name.
   *
   * @param string $profileId
   *   The profile machine name.
   *
   * @return \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface|null
   *   The profile entity or NULL if not found.
   */
  private function loadProfile(string $profileId): ?AgentProfileInterface {
    $profile = $this->entityTypeManager()->getStorage('agent_profile')->load($profileId);
    return $profile instanceof AgentProfileInterface ? $profile : NULL;
  }

}
