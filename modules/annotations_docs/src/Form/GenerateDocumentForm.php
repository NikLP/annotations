<?php

declare(strict_types=1);

namespace Drupal\annotations_docs\Form;

use Drupal\annotations\Entity\AnnotationTargetInterface;
use Drupal\annotations_docs\DocumentGeneratorService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for generating or regenerating an annotation document.
 */
class GenerateDocumentForm extends FormBase {

  public function __construct(
    protected DocumentGeneratorService $generator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('annotations_docs.generator'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_docs_generate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AnnotationTargetInterface $annotation_target = NULL): array {
    if ($annotation_target === NULL) {
      $form['error'] = ['#markup' => $this->t('Target not found.')];
      return $form;
    }

    $form_state->set('target_id', $annotation_target->id());
    $existing = $this->generator->loadDocumentNode($annotation_target->id());

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $existing
        ? $this->t('Regenerate documentation for <strong>@label</strong>? The existing document will be updated and a new revision saved.', ['@label' => $annotation_target->label()])
        : $this->t('Generate documentation for <strong>@label</strong>? This will call the configured AI provider.', ['@label' => $annotation_target->label()]),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $existing ? $this->t('Regenerate') : $this->t('Generate'),
      '#button_type' => 'primary',
    ];
    
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('annotations_docs.page', [], ['query' => ['target' => $annotation_target->id()]]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $target_id = (string) $form_state->get('target_id');

    try {
      $this->generator->generate($target_id);
      $this->messenger()->addStatus($this->t('Documentation generated successfully.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Generation failed: @message', ['@message' => $e->getMessage()]));
      $this->getLogger('annotations_docs')->error('Document generation failed for @target: @message', [
        '@target' => $target_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('annotations_docs.page', [], ['query' => ['target' => $target_id]]);
  }

}
