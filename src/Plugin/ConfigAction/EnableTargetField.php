<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Appends field(s) to an annotation_target entity's fields list.
 *
 * The annotation_target config entity must already exist (export it from the
 * site and include it in the recipe's config/ directory before applying).
 *
 * Idempotent: fields already registered on the target are silently skipped.
 *
 * Usage in recipe.yml:
 * @code
 * config:
 *   actions:
 *     annotations.target.node__article:
 *       enableTargetField:
 *         - body
 *         - field_tags
 * @endcode
 */
#[ConfigAction(
  id: 'enableTargetField',
  admin_label: new TranslatableMarkup('Enable annotation target field(s)'),
  entity_types: ['annotation_target'],
)]
final class EnableTargetField implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
  ) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get(ConfigManagerInterface::class),
    );
  }

  public function apply(string $configName, mixed $value): void {
    $target = $this->configManager->loadConfigEntityByName($configName);
    if (!$target) {
      throw new ConfigActionException(sprintf('annotation_target config entity "%s" does not exist. Export and include it in the recipe before calling enableTargetField.', $configName));
    }

    $fields = is_array($value) ? $value : [$value];
    if (empty($fields)) {
      return;
    }

    $existing = $target->get('fields') ?? [];

    foreach ($fields as $field) {
      if (!is_string($field) || $field === '') {
        throw new ConfigActionException('Each value passed to enableTargetField must be a non-empty string.');
      }

      if (!in_array($field, $existing, TRUE)) {
        $existing[] = $field;
      }
    }

    $target->set('fields', $existing)->save();
  }

}
