<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding/editing Scheduled Task config entities.
 */
class ScheduledTaskForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface $task */
    $task = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Task label'),
      '#maxlength' => 255,
      '#default_value' => $task->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $task->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
      ],
      '#disabled' => !$task->isNew(),
    ];

    // Source section.
    $form['source'] = [
      '#type' => 'container',
    ];

    $form['source']['source_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Source'),
      '#options' => [
        'skill' => $this->t('Run a skill'),
        'prompt' => $this->t('Custom prompt'),
      ],
      '#default_value' => $task->getSourceType(),
      '#required' => TRUE,
    ];

    // Skill dropdown.
    $skills = $this->entityTypeManager->getStorage('agent_skill')->loadMultiple();
    $skillOptions = [];
    foreach ($skills as $skill) {
      $skillOptions[$skill->id()] = $skill->label();
    }

    $form['source']['skill_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Skill'),
      '#options' => $skillOptions,
      '#default_value' => $task->getSkillId(),
      '#empty_option' => $this->t('- Select a skill -'),
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'skill'],
        ],
        'required' => [
          ':input[name="source_type"]' => ['value' => 'skill'],
        ],
      ],
    ];

    $form['source']['arguments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arguments'),
      '#description' => $this->t('Replaces $ARGUMENTS in the skill body.'),
      '#maxlength' => 1024,
      '#default_value' => $task->getArguments(),
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'skill'],
        ],
      ],
    ];

    $form['source']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt'),
      '#rows' => 10,
      '#default_value' => $task->getPrompt(),
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'prompt'],
        ],
        'required' => [
          ':input[name="source_type"]' => ['value' => 'prompt'],
        ],
      ],
    ];

    // Profile selector.
    $profiles = $this->entityTypeManager->getStorage('agent_profile')->loadMultiple();
    $profileOptions = [];
    foreach ($profiles as $profile) {
      $profileOptions[$profile->id()] = $profile->label();
    }

    $form['profile_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Agent Profile'),
      '#options' => $profileOptions,
      '#default_value' => $task->getProfileId() ?: (isset($profileOptions['default']) ? 'default' : key($profileOptions)),
      '#required' => TRUE,
    ];

    // Schedule section.
    $form['schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule'),
      '#open' => TRUE,
    ];

    // Parse existing date or default to next hour.
    $scheduleDate = $task->getScheduleDate();
    $defaultDate = NULL;
    if ($scheduleDate) {
      try {
        $defaultDate = DrupalDateTime::createFromFormat('Y-m-d\TH:i:s', $scheduleDate);
      }
      catch (\Exception) {
        // Fall through to default.
      }
    }
    if (!$defaultDate || $defaultDate->hasErrors()) {
      $defaultDate = new DrupalDateTime('now');
    }

    $form['schedule']['schedule_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start date/time'),
      '#default_value' => $defaultDate,
      '#required' => TRUE,
    ];

    $scheduleTypeOptions = $this->buildScheduleTypeOptions($defaultDate);

    $form['schedule']['schedule_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Repeat'),
      '#options' => $scheduleTypeOptions,
      '#default_value' => $task->getScheduleType(),
      '#prefix' => '<div id="schedule-type-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['schedule']['schedule_custom_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Custom interval'),
      '#options' => [
        300 => $this->t('5 minutes'),
        900 => $this->t('15 minutes'),
        1800 => $this->t('30 minutes'),
        3600 => $this->t('1 hour'),
        21600 => $this->t('6 hours'),
        43200 => $this->t('12 hours'),
      ],
      '#default_value' => $task->getScheduleCustomInterval(),
      '#states' => [
        'visible' => [
          ':input[name="schedule_type"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['#attached']['library'][] = 'ai_claude_agent_sdk/scheduled-task';

    return $form;
  }

  /**
   * Build schedule type radio options with dynamic labels from a date.
   */
  private function buildScheduleTypeOptions(DrupalDateTime $date): array {
    $dayName = $date->format('l');
    $dayOfMonth = (int) $date->format('j');
    $monthDay = $date->format('M j');

    $suffixes = ['th', 'st', 'nd', 'rd'];
    $mod100 = $dayOfMonth % 100;
    $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th' : ($suffixes[$dayOfMonth % 10] ?? 'th');
    $ordinal = $dayOfMonth . $suffix;

    return [
      'once' => $this->t('None (run once)'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly (@day)', ['@day' => $dayName]),
      'monthly' => $this->t('Monthly (@day)', ['@day' => $ordinal]),
      'yearly' => $this->t('Yearly (@date)', ['@date' => $monthDay]),
      'weekday' => $this->t('Every Weekday (Mon–Fri)'),
      'custom' => $this->t('Custom interval'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    // Extract the ISO string from the datetime widget value BEFORE parent
    // processes it, but do NOT modify form_state (which would break the
    // Datetime element's #element_validate that runs after buildEntity).
    $dateValue = $form_state->getValue('schedule_date');
    $isoDate = NULL;
    if ($dateValue instanceof DrupalDateTime) {
      $isoDate = $dateValue->format('Y-m-d\TH:i:s');
    }
    elseif (is_array($dateValue) && !empty($dateValue['object']) && $dateValue['object'] instanceof DrupalDateTime) {
      $isoDate = $dateValue['object']->format('Y-m-d\TH:i:s');
    }
    elseif (is_array($dateValue)) {
      $date = $dateValue['date'] ?? '';
      $time = $dateValue['time'] ?? '00:00:00';
      if ($date) {
        $isoDate = $date . 'T' . $time;
      }
    }
    elseif (is_string($dateValue) && !empty($dateValue)) {
      $isoDate = $dateValue;
    }

    // Temporarily set the ISO string so parent can copy it to the entity.
    if ($isoDate !== NULL) {
      $originalValue = $dateValue;
      $form_state->setValue('schedule_date', $isoDate);
      parent::copyFormValuesToEntity($entity, $form, $form_state);
      // Restore the original value so Datetime element validation still works.
      $form_state->setValue('schedule_date', $originalValue);
    }
    else {
      parent::copyFormValuesToEntity($entity, $form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $sourceType = $form_state->getValue('source_type');

    if ($sourceType === 'skill') {
      $skillId = $form_state->getValue('skill_id');
      if (empty($skillId)) {
        $form_state->setErrorByName('skill_id', $this->t('Please select a skill.'));
      }
      else {
        $skill = $this->entityTypeManager->getStorage('agent_skill')->load($skillId);
        if (!$skill) {
          $form_state->setErrorByName('skill_id', $this->t('The selected skill does not exist.'));
        }
      }
    }

    if ($sourceType === 'prompt') {
      $prompt = $form_state->getValue('prompt');
      if (empty(trim((string) $prompt))) {
        $form_state->setErrorByName('prompt', $this->t('Please enter a prompt.'));
      }
    }

    $profileId = $form_state->getValue('profile_id');
    if ($profileId) {
      $profile = $this->entityTypeManager->getStorage('agent_profile')->load($profileId);
      if (!$profile) {
        $form_state->setErrorByName('profile_id', $this->t('The selected profile does not exist.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface $task */
    $task = $this->entity;

    // Recalculate next run via scheduler service.
    \Drupal::service('ai_claude_agent_sdk.scheduler')->recalculateAndStore($task);

    $messageArgs = ['%label' => $task->label()];
    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Scheduled task %label created.', $messageArgs));
    }
    else {
      $this->messenger()->addStatus($this->t('Scheduled task %label updated.', $messageArgs));
    }

    $form_state->setRedirectUrl($task->toUrl('collection'));

    return $result;
  }

  /**
   * Check whether a scheduled_task machine name exists.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('scheduled_task')->load($id);
  }

}
