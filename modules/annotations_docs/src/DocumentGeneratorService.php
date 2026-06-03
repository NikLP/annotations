<?php

declare(strict_types=1);

namespace Drupal\annotations_docs;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;

/**
 * Generates and stores AI-authored documentation for an annotation target.
 */
class DocumentGeneratorService {

  /**
   * KV collection name for generation timestamps.
   */
  const KV_COLLECTION = 'annotations_docs.generated';

  /**
   * The ai_prompt config entity ID shipped as the default system prompt.
   */
  const DEFAULT_PROMPT_ID = 'annotations_docs__generate__default';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
    private readonly AiProviderPluginManager $aiProvider,
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Generates (or regenerates) documentation for a target, saves as a node.
   *
   * @return int
   *   The saved node ID.
   *
   * @throws \RuntimeException
   *   When no AI chat provider is configured or the target does not exist.
   */
  public function generate(string $target_id): int {
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if ($target === NULL) {
      throw new \RuntimeException("Annotation target '{$target_id}' not found.");
    }

    $set = $this->aiProvider->getSetProvider('chat');
    if (empty($set['provider_id']) || empty($set['model_id'])) {
      throw new \RuntimeException('No default AI chat provider is configured.');
    }

    $payload = $this->assembler->assemble([
      'target_id' => $target_id,
      'inc_meta' => TRUE,
    ]);
    $context_markdown = $this->renderer->render($payload);

    $input = new ChatInput([
      new ChatMessage('system', $this->systemPrompt()),
      new ChatMessage('user', $this->userMessage((string) $target->label(), $context_markdown)),
    ]);

    $markdown = html_entity_decode(
      $set['provider_id']->chat($input, $set['model_id'])->getNormalized()->getText(),
      ENT_QUOTES | ENT_HTML5,
      'UTF-8',
    );
    $generated_text = (new \League\CommonMark\CommonMarkConverter())->convert($markdown)->getContent();

    $node = $this->loadDocumentNode($target_id);
    if ($node === NULL) {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'annotations_document',
        'title' => $target->label(),
        'status' => NodeInterface::NOT_PUBLISHED,
        'annotations_doc_target' => $target_id,
      ]);
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node->set('annotations_doc_body', ['value' => $generated_text, 'format' => 'full_html']);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Generated via Annotations Documents');
    $node->save();

    // Store the generation timestamp; expires after 2 years (informational only).
    $this->keyValueFactory->get(self::KV_COLLECTION)->setWithExpire(
      $target_id,
      $this->time->getRequestTime(),
      730 * 86400,
    );

    return (int) $node->id();
  }

  /**
   * Loads the annotations_document node for a target, or NULL if none exists.
   */
  public function loadDocumentNode(string $target_id): ?NodeInterface {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'annotations_document')
      ->condition('annotations_doc_target', $target_id)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      return NULL;
    }

    /** @var \Drupal\node\NodeInterface */
    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

  /**
   * Returns the last-generated timestamp for a target, or NULL if never run.
   */
  public function getLastGeneratedTimestamp(string $target_id): ?int {
    $value = $this->keyValueFactory->get(self::KV_COLLECTION)->get($target_id);
    return is_int($value) ? $value : NULL;
  }

  /**
   * Returns the system prompt text, loaded from the ai_prompt config entity.
   *
   * Falls back to the hardcoded default if the config entity is absent.
   */
  private function systemPrompt(): string {
    $prompt = $this->configFactory
      ->get('ai.ai_prompt.' . self::DEFAULT_PROMPT_ID)
      ->get('prompt');

    if (!empty($prompt)) {
      return $prompt;
    }

    return $this->defaultSystemPrompt();
  }

  /**
   * Builds the user-turn message from the target label and assembled context.
   */
  private function userMessage(string $target_label, string $context): string {
    return "Please write documentation for the following content type.\n\nTarget: {$target_label}\n\n## Context\n\n{$context}";
  }

  /**
   * Hardcoded fallback system prompt used when the config entity is missing.
   */
  private function defaultSystemPrompt(): string {
    return <<<'PROMPT'
You are a technical writer creating documentation for a Drupal site's content model.

Given structured context about a content type or entity bundle, write clear, useful documentation in plain English for site editors.

Your documentation should:
- Begin with a brief description of what this content type is for and when editors should use it
- Explain each field: its purpose, when to fill it in, and what makes a good entry
- Note any workflow states or publishing requirements if present
- Use plain language suitable for non-technical site editors
- Structure the output with markdown headings (##, ###), paragraphs, and unordered lists where helpful
- Be honest about gaps: if information is not documented, say so rather than guessing

Output only the documentation body as markdown. Do not open with a heading for the content type name — the page title already provides that. Do not include a wrapping code fence, preamble, meta-commentary, or sign-off.
PROMPT;
  }

}
