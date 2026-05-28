<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\annotations_audit\ScanService;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for the annotations_audit module.
 */
class AnnotationsAuditHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ScanService $scanner,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    return match ($route_name) {
      'help.page.annotations_audit' => '<p>' . $this->t(
        'The Annotations Audit module crawls your site structure and reports annotation coverage. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>.',
        [':url' => '/admin/config/annotations/targets']
      ) . '</p>',
      default => '',
    };
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_coverage_gap_section' => [
        'variables' => [
          'heading' => NULL,
          'visually_hidden_prefix' => NULL,
          'items' => [],
          'modifier' => '',
        ],
      ],
      'annotations_coverage_gap_details' => [
        'variables' => [
          'summary' => NULL,
          'content' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $stored = $this->scanner->loadSnapshot();
    $result = $this->scanner->scan();
    $diff = $this->scanner->computeDiff($result, $stored);
    if ($this->scanner->diffHasChanges($diff)) {
      $this->scanner->storePendingDiff($diff);
      $this->logger->warning('Annotations Audit: site structure has changed since the last accepted scan. Visit the <a href=":url">audit scan page</a> to review.', [
        ':url' => '/admin/config/annotations/audit/scan',
      ]);
    }
    $this->scanner->saveSnapshot($result);
  }

  /**
   * Injects the "Affects coverage status" checkbox into annotation type form.
   */
  #[Hook('form_annotation_type_form_alter')]
  public function formAnnotationTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $entity */
    $entity = $form_state->getFormObject()->getEntity();

    $form['behavior']['affects_coverage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Affects coverage status'),
      '#description' => $this->t('When enabled, targets missing an annotation of this type are flagged in coverage reports.'),
      '#default_value' => $entity->getThirdPartySetting('annotations_audit', 'affects_coverage', FALSE),
    ];

    $form['#entity_builders'][] = [$this, 'buildAnnotationTypeEntity'];
  }

  /**
   * Entity builder: saves the affects_coverage third-party setting.
   */
  public function buildAnnotationTypeEntity(string $_entity_type, mixed $entity, array &$_form, FormStateInterface $form_state): void {
    $entity->setThirdPartySetting('annotations_audit', 'affects_coverage', (bool) $form_state->getValue('affects_coverage'));
  }

}
