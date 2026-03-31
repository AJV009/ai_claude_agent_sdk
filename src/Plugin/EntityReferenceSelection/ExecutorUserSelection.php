<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Plugin\EntityReferenceSelection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\user\Plugin\EntityReferenceSelection\UserSelection;

/**
 * Selection plugin for executor user accounts.
 *
 * Lightweight passthrough extending UserSelection. Eligibility labeling is
 * handled by ExecutorAutocompleteController. Permission enforcement is
 * handled by AgentProfileForm::validateForm().
 */
#[EntityReferenceSelection(
  id: "executor_user_selection",
  label: new TranslatableMarkup("Executor user selection"),
  entity_types: ["user"],
  group: "executor_user_selection",
  weight: 1,
)]
class ExecutorUserSelection extends UserSelection {

}
