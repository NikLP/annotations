<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP (Model Context Protocol) endpoint for annotation context resources.
 *
 * Implements the MCP Streamable HTTP transport (2025-03-26 spec) over a single
 * POST endpoint at /api/annotations/mcp.
 *
 * Each annotation_target is exposed as an MCP resource addressed by:
 *   annotation://target/{target_id}
 *
 * Optional URI query parameters (same semantics as the REST endpoint):
 *   ?ref_depth=0|1|2        — entity reference traversal depth
 *   ?include_field_meta=1   — include field type/cardinality/description
 *
 * Supported JSON-RPC methods:
 *   initialize           — capability handshake; negotiates protocol version
 *   notifications/*      — notifications acknowledged, no response body
 *   resources/list       — enumerate all annotation targets as MCP resources
 *   resources/read       — assembled context for one target, rendered as markdown
 *   ping                 — keep-alive; returns empty result object
 */
class ContextMcpController extends ControllerBase {

  private const PROTOCOL_VERSION = '2025-03-26';

  private const SUPPORTED_VERSIONS = ['2025-03-26', '2024-11-05'];

  private const URI_PATTERN = '#^annotation://target/([a-z0-9_]+)(\?.*)?$#';

  public function __construct(
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_context.assembler'),
      $container->get('annotations_context.renderer'),
    );
  }

  /**
   * Handles a POST request containing a JSON-RPC 2.0 message.
   */
  public function handle(Request $request): Response {
    $content = $request->getContent();
    $body = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return $this->errorResponse(NULL, -32700, 'Parse error', [], Response::HTTP_BAD_REQUEST);
    }

    if (!is_array($body) || ($body['jsonrpc'] ?? '') !== '2.0' || !array_key_exists('method', $body)) {
      return $this->errorResponse(NULL, -32600, 'Invalid request');
    }

    $method = $body['method'];
    $id     = $body['id'] ?? NULL;
    $params = $body['params'] ?? [];

    // Notifications carry no id and expect no response body.
    if (!array_key_exists('id', $body) && str_starts_with($method, 'notifications/')) {
      return new Response('', Response::HTTP_ACCEPTED);
    }

    return match ($method) {
      'initialize'     => $this->handleInitialize($id, $params),
      'resources/list' => $this->handleResourcesList($id),
      'resources/read' => $this->handleResourcesRead($id, $params, $request),
      'ping'           => $this->successResponse($id, new \stdClass()),
      default          => $this->errorResponse($id, -32601, 'Method not found'),
    };
  }

  private function handleInitialize(mixed $id, array $params): JsonResponse {
    $clientVersion = $params['protocolVersion'] ?? '';
    $negotiated    = in_array($clientVersion, self::SUPPORTED_VERSIONS, TRUE)
      ? $clientVersion
      : self::PROTOCOL_VERSION;

    return $this->successResponse($id, [
      'protocolVersion' => $negotiated,
      'capabilities'    => [
        'resources' => [
          'subscribe'   => FALSE,
          'listChanged' => FALSE,
        ],
      ],
      'serverInfo' => [
        'name'    => 'Annotations MCP Server',
        'version' => '1.0.0',
      ],
    ]);
  }

  private function handleResourcesList(mixed $id): JsonResponse {
    $targets   = $this->entityTypeManager()->getStorage('annotation_target')->loadMultiple();
    $resources = [];

    foreach ($targets as $target) {
      $resources[] = [
        'uri'         => 'annotation://target/' . $target->id(),
        'name'        => $target->label(),
        'description' => 'Annotation context for ' . $target->label(),
        'mimeType'    => 'text/plain',
      ];
    }

    return $this->successResponse($id, ['resources' => $resources]);
  }

  private function handleResourcesRead(mixed $id, array $params, Request $request): JsonResponse {
    $uri = $params['uri'] ?? '';

    if (!preg_match(self::URI_PATTERN, $uri, $matches)) {
      return $this->errorResponse($id, -32602, 'Invalid params: unsupported URI scheme');
    }

    $target_id = $matches[1];
    $target    = $this->entityTypeManager()->getStorage('annotation_target')->load($target_id);

    if (!$target) {
      return $this->errorResponse($id, -32002, 'Resource not found', ['uri' => $uri]);
    }

    // Filter to types explicitly enabled for AI/MCP consumption.
    // Default FALSE — types must be opted in via the annotation type edit form.
    $all_types = $this->entityTypeManager()->getStorage('annotation_type')->loadMultiple();
    $ai_types  = array_keys(array_filter(
      $all_types,
      fn($t) => $t->getThirdPartySetting('annotations_context', 'in_ai_context', FALSE),
    ));

    // Bearer token auth grants full access; omit account so the assembler
    // returns all types rather than filtering by the anonymous session.
    $options = ['target_id' => $target_id, 'types' => $ai_types];
    if (!$request->attributes->get('_mcp_bearer_auth')) {
      $options['account'] = $this->currentUser();
    }

    // Parse optional query parameters embedded in the URI.
    if (!empty($matches[2])) {
      parse_str(ltrim($matches[2], '?'), $query);
      if (isset($query['ref_depth'])) {
        $options['ref_depth'] = max(0, (int) $query['ref_depth']);
      }
      if (($query['include_field_meta'] ?? '') === '1') {
        $options['include_field_meta'] = TRUE;
      }
    }

    $payload  = $this->assembler->assemble($options);
    $markdown = $this->renderer->render($payload);

    return $this->successResponse($id, [
      'contents' => [
        [
          'uri'      => $uri,
          'mimeType' => 'text/plain',
          'text'     => $markdown,
        ],
      ],
    ]);
  }

  private function successResponse(mixed $id, array|object $result): JsonResponse {
    return new JsonResponse([
      'jsonrpc' => '2.0',
      'id'      => $id,
      'result'  => $result,
    ]);
  }

  private function errorResponse(mixed $id, int $code, string $message, array $data = [], int $httpStatus = Response::HTTP_OK): JsonResponse {
    $error = ['code' => $code, 'message' => $message];
    if ($data) {
      $error['data'] = $data;
    }
    return new JsonResponse([
      'jsonrpc' => '2.0',
      'id'      => $id,
      'error'   => $error,
    ], $httpStatus);
  }

}
