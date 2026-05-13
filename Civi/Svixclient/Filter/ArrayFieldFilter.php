<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

/**
 * Array field matching filter strategy.
 *
 * Creates a JavaScript filter that iterates an array in the webhook payload
 * and matches if ANY item contains a target value at a nested field path.
 *
 * Designed for GoCardless webhooks where a single payload contains an
 * `events` array with events for potentially multiple organisations.
 *
 * ## Example
 *
 * ```php
 * $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000123');
 * ```
 *
 * ## Generated JavaScript
 *
 * ```javascript
 * function handler(webhook) {
 *     var items = webhook.payload.events || [];
 *     for (var i = 0; i < items.length; i++) {
 *         if (items[i].links && items[i].links.organisation === 'OR000123') {
 *             return webhook;
 *         }
 *     }
 *     webhook.cancel = true;
 *     return webhook;
 * }
 * ```
 *
 * @package Civi\Svixclient\Filter
 */
class ArrayFieldFilter implements FilterStrategyInterface {

  use EscapesJsStrings;

  /**
   * The array field path in the webhook payload.
   *
   * @var string
   */
  private string $arrayPath;

  /**
   * The dot-separated field path within each array item.
   *
   * @var string
   */
  private string $nestedFieldPath;

  /**
   * The value to match against.
   *
   * @var string
   */
  private string $value;

  /**
   * ArrayFieldFilter constructor.
   *
   * @param string $arrayPath
   *   The array field path in the webhook payload (e.g., 'events').
   * @param string $nestedFieldPath
   *   The dot-separated field path within each array item
   *   (e.g., 'links.organisation').
   * @param string $value
   *   The value to match (e.g., 'OR000123').
   */
  public function __construct(string $arrayPath, string $nestedFieldPath, string $value) {
    $this->arrayPath = $arrayPath;
    $this->nestedFieldPath = $nestedFieldPath;
    $this->value = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): string {
    $escapedValue = $this->escapeJsString($this->value);
    $arrayAccessor = 'webhook.payload.' . $this->arrayPath;
    $condition = $this->buildNestedCondition('items[i]', $this->nestedFieldPath, $escapedValue);

    return <<<JS
function handler(webhook) {
    var items = {$arrayAccessor} || [];
    for (var i = 0; i < items.length; i++) {
        if ({$condition}) {
            return webhook;
        }
    }
    webhook.cancel = true;
    return webhook;
}
JS;
  }

  /**
   * Build a null-safe nested field condition for JavaScript.
   *
   * For a path like 'links.organisation', generates:
   *   items[i].links && items[i].links.organisation === 'value'
   *
   * @param string $prefix
   *   The variable prefix (e.g., 'items[i]').
   * @param string $fieldPath
   *   The dot-separated field path.
   * @param string $escapedValue
   *   The escaped value to compare against.
   *
   * @return string
   *   The JavaScript condition expression.
   */
  private function buildNestedCondition(string $prefix, string $fieldPath, string $escapedValue): string {
    $parts = explode('.', $fieldPath);
    $checks = [];
    $currentPath = $prefix;

    // Add null checks for intermediate segments.
    for ($j = 0; $j < count($parts) - 1; $j++) {
      $currentPath .= '.' . $parts[$j];
      $checks[] = $currentPath;
    }

    // Final segment is the equality check.
    $fullPath = $prefix . '.' . $fieldPath;
    $checks[] = "{$fullPath} === '{$escapedValue}'";

    return implode(' && ', $checks);
  }

}
