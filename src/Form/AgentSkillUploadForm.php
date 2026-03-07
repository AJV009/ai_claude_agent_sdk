<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillFileSync;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for uploading Agent Skills from .zip or .md files.
 */
class AgentSkillUploadForm extends FormBase {

  public function __construct(
    protected readonly AgentSkillFileSync $skillFileSync,
    protected readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.skill_file_sync'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agent_skill_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['upload_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Skill file'),
      '#description' => $this->t('Upload a .zip file (Agent Skills spec directory) or a single .md file.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload and import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $all_files = $this->getRequest()->files->get('files', []);
    $file = $all_files['upload_file'] ?? NULL;

    if (!$file || !$file->isValid()) {
      $form_state->setErrorByName('upload_file', $this->t('Please upload a valid .zip or .md file.'));
      return;
    }

    $extension = strtolower($file->getClientOriginalExtension());
    if (!in_array($extension, ['zip', 'md'])) {
      $form_state->setErrorByName('upload_file', $this->t('Only .zip and .md files are accepted.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $all_files = $this->getRequest()->files->get('files', []);
    $file = $all_files['upload_file'];
    $extension = strtolower($file->getClientOriginalExtension());

    if ($extension === 'zip') {
      $this->importFromZip($file->getRealPath(), $form_state);
    }
    else {
      $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
      $this->importFromMd($file->getRealPath(), $filename, $form_state);
    }
  }

  /**
   * Import a skill from a zip archive.
   */
  protected function importFromZip(string $zipPath, FormStateInterface $form_state): void {
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== TRUE) {
      $this->messenger()->addError($this->t('Failed to open zip file.'));
      return;
    }

    $tempDir = $this->fileSystem->getTempDirectory() . '/skill_import_' . uniqid();
    mkdir($tempDir, 0755, TRUE);
    $zip->extractTo($tempDir);
    $zip->close();

    // Find SKILL.md — check root first, then first subdirectory.
    $skillMdPath = NULL;
    if (file_exists($tempDir . '/SKILL.md')) {
      $skillMdPath = $tempDir . '/SKILL.md';
    }
    else {
      $dirs = glob($tempDir . '/*', GLOB_ONLYDIR);
      foreach ($dirs as $dir) {
        if (file_exists($dir . '/SKILL.md')) {
          $skillMdPath = $dir . '/SKILL.md';
          break;
        }
      }
    }

    if (!$skillMdPath) {
      $this->messenger()->addError($this->t('No SKILL.md found in the zip archive.'));
      $this->fileSystem->deleteRecursive($tempDir);
      return;
    }

    $content = file_get_contents($skillMdPath);
    $entity = $this->skillFileSync->createEntityFromContent($content);

    $this->fileSystem->deleteRecursive($tempDir);

    if ($entity) {
      $this->messenger()->addStatus($this->t('Skill %label imported successfully.', [
        '%label' => $entity->label(),
      ]));
      $form_state->setRedirectUrl($entity->toUrl('edit-form'));
    }
    else {
      $this->messenger()->addError($this->t('A skill with that machine name already exists.'));
    }
  }

  /**
   * Import a skill from a single .md file.
   */
  protected function importFromMd(string $mdPath, string $filename, FormStateInterface $form_state): void {
    $content = file_get_contents($mdPath);
    $entity = $this->skillFileSync->createEntityFromContent($content, $filename);

    if ($entity) {
      $this->messenger()->addStatus($this->t('Skill %label imported successfully.', [
        '%label' => $entity->label(),
      ]));
      $form_state->setRedirectUrl($entity->toUrl('edit-form'));
    }
    else {
      $this->messenger()->addError($this->t('A skill with that machine name already exists.'));
    }
  }

}
