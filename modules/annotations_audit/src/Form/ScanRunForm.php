<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\annotations_audit\ScanService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One-button form to trigger a manual scan.
 *
 * Using a form rather than a direct GET link ensures Drupal's CSRF token
 * is in play for this state-changing action.
 */
class ScanRunForm extends FormBase {

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
    return 'annotations_audit_scan_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create waypoint'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->scanner->scan();
    $this->scanner->saveSnapshot($result);
    $this->scanner->clearAccumulatedChanges();

    $this->messenger()->addStatus(
      $this->t('Scan complete. @count target(s) discovered. Waypoint created.', ['@count' => count($result)])
    );
  }

}
