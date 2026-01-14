<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

/**
 * Simple field matching filter strategy.
 *
 * Creates a JavaScript filter that matches a single field value in the
 * webhook payload. Supports both simple fields and nested paths.
 *
 * ## Examples
 *
 * ```php
 * // Stripe: match by 'account' field
 * $filter = new SimpleFieldFilter('account', 'acct_xxx');
 *
 * // GoCardless: match by nested 'links.organisation' field
 * $filter = new SimpleFieldFilter('links.organisation', 'OR000123');
 * ```
 *
 * ## Generated JavaScript
 *
 * ```javascript
 * function handler(input) {
 *     if (input.account !== 'acct_xxx') return null;
 *     return { payload: input };
 * }
 * ```
 *
 * @package Civi\Svixclient\Filter
 */
class SimpleFieldFilter implements FilterStrategyInterface {

  /**
   * The field path to match.
   *
   * @var string
   */
  private string $field;

  /**
   * The value to match against.
   *
   * @var string
   */
  private string $value;

  /**
   * SimpleFieldFilter constructor.
   *
   * @param string $field
   *   The field path in the webhook payload to match against. Can be a
   *   simple field (e.g., 'account') or nested path ('links.organisation').
   * @param string $value
   *   The value to match (e.g., 'acct_xxx' for Stripe, 'OR000123' for
   *   GoCardless).
   */
  public function __construct(string $field, string $value) {
    $this->field = $field;
    $this->value = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): string {
    $escapedField = $this->escapeJsString($this->field);
    $escapedValue = $this->escapeJsString($this->value);

    return <<<JS
function handler(input) {
    if (input.{$escapedField} !== '{$escapedValue}') return null;
    return { payload: input };
}
JS;
  }

  /**
   * Escape a string for safe use in JavaScript single-quoted strings.
   *
   * Uses json_encode() for base escaping, then escapes single quotes
   * since the generated JS uses single-quoted strings.
   *
   * @param string $value
   *   The string to escape.
   *
   * @return string
   *   The escaped string (without surrounding quotes).
   *
   * @throws \InvalidArgumentException
   *   If the value cannot be encoded.
   */
  private function escapeJsString(string $value): string {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === FALSE) {
      throw new \InvalidArgumentException('Failed to encode value for JavaScript escaping');
    }
    // Remove the surrounding quotes added by json_encode.
    $result = substr($encoded, 1, -1);
    // Escape single quotes (json_encode only escapes double quotes).
    return str_replace("'", "\\'", $result);
  }

}
