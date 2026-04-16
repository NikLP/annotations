<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\argument_validator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsArgumentValidator;
use Drupal\views\Plugin\views\argument_validator\ArgumentValidatorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates an annotation_target machine name and resolves it to a title.
 *
 * Attach this validator to a contextual filter on an annotation view.
 * The validated title is used by Views title tokens ({{ arguments.arg_0 }}).
 */
#[ViewsArgumentValidator(
  id: 'annotation_target',
  title: new TranslatableMarkup('Annotation Target ID'),
)]
class AnnotationTargetArgumentValidator extends ArgumentValidatorPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  public function validateArgument($arg): bool {
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($arg);
    if ($target === NULL) {
      return FALSE;
    }
    $this->argument->validated_title = $target->label();
    return TRUE;
  }

}
