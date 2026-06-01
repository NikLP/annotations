<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\annotations_audit\ScanService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows out-of-scope fields with per-item dismiss controls.
 */
class DismissDriftForm extends FormBase {

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
    return 'annotations_audit_dismiss_drift_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $drift
   *   Active (non-dismissed) drift items keyed by target_id.
   * @param int $dismissed_count
   *   Number of currently dismissed items.
   * @param array $target_labels
   *   Human-readable labels keyed by target_id.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $drift = [], int $dismissed_count = 0, array $target_labels = []): array {
    $form['block'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--status']],
    ];

    if (!empty($drift)) {
      $form['block']['intro'] = [
        '#markup' => '<p>' . $this->t('The following tracked targets have fields available in Drupal that are not yet in scope. <a href=":url">Configure targets</a> to include them, or dismiss to suppress the notice.', [
          ':url' => Url::fromRoute('entity.annotation_target.collection')->toString(),
        ]) . '</p>',
      ];

      $form['block']['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('Target'), $this->t('Field'), ''],
      ];

      foreach ($drift as $target_id => $fields) {
        $label = $target_labels[$target_id] ?? $target_id;
        foreach ($fields as $field_name) {
          $row_key = 'row_' . substr(md5("{$target_id}:{$field_name}"), 0, 8);
          $form['block']['table'][$row_key]['target'] = ['#plain_text' => $label];
          $form['block']['table'][$row_key]['field']  = ['#plain_text' => $field_name];
          $form['block']['table'][$row_key]['action'] = [
            '#type' => 'submit',
            '#value' => $this->t('Dismiss'),
            '#submit' => ['::dismissItem'],
            '#limit_validation_errors' => [],
            '#attributes' => ['class' => ['button--small']],
            '#target_id' => $target_id,
            '#field_name' => $field_name,
          ];
        }
      }
    }
    elseif ($dismissed_count > 0) {
      $form['block']['all_dismissed'] = [
        '#markup' => '<p>' . $this->t('All scope drift notices are currently dismissed.') . '</p>',
      ];
    }

    if ($dismissed_count > 0) {
      $form['block']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->formatPlural($dismissed_count, 'Reset 1 dismissed notice', 'Reset @count dismissed notices'),
        '#submit' => ['::resetDismissed'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['button--small']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Submit handler: dismisses a single field from scope drift notices.
   */
  public function dismissItem(array &$form, FormStateInterface $form_state): void {
    $element = $form_state->getTriggeringElement();
    $this->scanner->dismissDriftField($element['#target_id'], $element['#field_name']);
    $this->messenger()->addStatus($this->t('@field dismissed from scope drift notices.', [
      '@field' => $element['#field_name'],
    ]));
  }

  /**
   * Submit handler: clears all scope drift dismissals.
   */
  public function resetDismissed(array &$form, FormStateInterface $form_state): void {
    $this->scanner->clearDismissedDrift();
    $this->messenger()->addStatus($this->t('Scope drift notices reset.'));
  }

}
