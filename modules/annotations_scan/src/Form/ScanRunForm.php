<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\annotations_scan\ScanService;
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
    return new static($container->get('annotations_scan.scanner'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_scan_run_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run scan now'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->scanner->scan();

    $this->messenger()->addStatus(
      $this->t('Scan complete. @count targets discovered.', ['@count' => count($result)])
    );
  }

}
