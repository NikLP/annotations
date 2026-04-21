<?php

declare(strict_types=1);

namespace Drupal\annotations_ai_context\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_chatbot\Plugin\Block\DeepChatFormBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Annotations Site Assistant chat block.
 *
 * Pre-configured to use the Annotations assistant. No assistant selection needed.
 */
#[Block(
  id: 'annotations_chat_block',
  admin_label: new TranslatableMarkup('Annotations Site Assistant'),
  category: new TranslatableMarkup('Annotations'),
)]
class AnnotationsChatBlock extends DeepChatFormBlock {

  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->routeMatch = $container->get('current_route_match');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $block = parent::build();
    if (empty($block)) {
      return $block;
    }

    $connect = json_decode($block['#deepchat_settings']['connect'], TRUE);
    $connect['additionalBodyProps']['contexts']['route_name'] = $this->routeMatch->getRouteName() ?? '';
    $connect['additionalBodyProps']['contexts']['route_params'] = $this->routeMatch->getRawParameters()->all();
    $block['#deepchat_settings']['connect'] = json_encode($connect);

    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'ai_assistant' => 'annotations',
      'bot_name' => 'Site Assistant',
      'first_message' => 'Hi! Ask me anything about how to use this site.',
      'verbose_mode' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    // The assistant is fixed to the Annotations assistant.
    unset($form['ai_assistant']);
    return $form;
  }

}
