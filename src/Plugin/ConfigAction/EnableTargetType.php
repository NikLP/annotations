<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Appends entity type(s) to the annotations enabled_target_types list.
 *
 * Idempotent: types already in the list are silently skipped.
 *
 * Usage in recipe.yml:
 * @code
 * config:
 *   actions:
 *     annotations.target_types:
 *       enableTargetType:
 *         - node
 *         - taxonomy_term
 * @endcode
 */
#[ConfigAction(
  id: 'enableTargetType',
  admin_label: new TranslatableMarkup('Enable annotation target type(s)'),
)]
final class EnableTargetType implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get(ConfigFactoryInterface::class));
  }

  public function apply(string $configName, mixed $value): void {
    if ($configName !== 'annotations.target_types') {
      throw new ConfigActionException(sprintf('The enableTargetType action only applies to annotations.target_types, got "%s".', $configName));
    }

    $types = is_array($value) ? $value : [$value];
    if (empty($types)) {
      return;
    }

    $config = $this->configFactory->getEditable($configName);
    $enabled = $config->get('enabled_target_types') ?? [];

    foreach ($types as $type) {
      if (!is_string($type) || $type === '') {
        throw new ConfigActionException('Each value passed to enableTargetType must be a non-empty string.');
      }
      if (!in_array($type, $enabled, TRUE)) {
        $enabled[] = $type;
      }
    }

    $config->set('enabled_target_types', $enabled)->save();
  }

}
