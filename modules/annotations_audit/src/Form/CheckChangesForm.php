<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\annotations_audit\ScanService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a diff against the current waypoint without saving a new one.
 *
 * Equivalent to what hook_cron does: scan → diff → merge accumulated changes.
 * Does not update the waypoint or clear the accumulated changes list.
 */
class CheckChangesForm extends FormBase {

  public function __construct(
    protected ScanService $scanner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('annotations_audit.scan_service'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_audit_check_changes_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check for changes'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $snapshot = $this->scanner->loadSnapshot();

    if (empty($snapshot)) {
      $this->messenger()->addWarning($this->t('No waypoint created yet. Create a waypoint first.'));
      return;
    }

    $result      = $this->scanner->scan();
    $diff        = $this->scanner->computeDiff($result, $snapshot);
    $new_changes = $this->scanner->mergeNewChanges($diff);
    $all_changes = $this->scanner->getAccumulatedChanges();

    if (empty($all_changes)) {
      $this->messenger()->addStatus($this->t('No structural changes detected since the last waypoint.'));
    }
    elseif (!empty($new_changes)) {
      $this->messenger()->addWarning(
        $this->t('@count new change(s) detected since the last check.', ['@count' => count($new_changes)])
      );
    }
  }

}
