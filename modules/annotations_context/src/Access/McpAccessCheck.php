<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Access checker for the MCP endpoint.
 *
 * Grants access when either:
 *   - the current user has 'view annotations context' or
 *     'administer annotations' (standard Drupal session auth), or
 *   - the request carries a valid 'Authorization: Bearer <key>' header matching
 *     the key stored in annotations_context.settings.
 *
 * When Bearer auth is used, sets the '_mcp_bearer_auth' request attribute so
 * ContextMcpController can omit the account filter from the assembler (giving
 * the token holder access to all annotation types, equivalent to admin).
 */
class McpAccessCheck implements AccessInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Check validity.
   */
  public function applies(Route $route): bool {
    return $route->hasRequirement('_mcp_access');
  }

  /**
   * Access handler.
   */
  public function access(Route $route, Request $request, AccountInterface $account): AccessResultInterface {
    // Session-based: standard Drupal permission check.
    if ($account->hasPermission('view annotations context') || $account->hasPermission('administer annotations')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Bearer token: compare against stored key using timing-safe comparison.
    $auth_header = $request->headers->get('Authorization', '');
    if (str_starts_with($auth_header, 'Bearer ')) {
      $token      = substr($auth_header, 7);
      $stored_key = $this->configFactory->get('annotations_context.settings')->get('mcp_api_key');

      if ($stored_key !== '' && $stored_key !== NULL && hash_equals($stored_key, $token)) {
        // Signal controller to skip account-based type filtering.
        $request->attributes->set('_mcp_bearer_auth', TRUE);
        return AccessResult::allowed()->setCacheMaxAge(0);
      }
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

}
