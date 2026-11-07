<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

/**
 * Filter strategy that matches when any of several fields holds the value.
 *
 * Used where the routing value can sit in different places depending on the
 * event. Stripe Express (destination charge) events on the platform account
 * have no `account` field: PaymentIntents and charges carry the connected
 * account in `data.object.transfer_data.destination` / `on_behalf_of`, and
 * Checkout Sessions in `data.object.metadata.connected_account_id`.
 *
 * Every path is checked null-safely, so a missing intermediate object never
 * matches. The routing value must not be empty: an empty value would match
 * every event that lacks the fields and leak other sites' events.
 *
 * ## Example
 *
 * ```php
 * $filter = new AnyFieldFilter(
 *   ['account', 'data.object.on_behalf_of'],
 *   'acct_xxx'
 * );
 * ```
 *
 * ## Generated JavaScript
 *
 * ```javascript
 * function handler(webhook) {
 *     var matches = (webhook.payload.account === 'acct_xxx')
 *         || (webhook.payload.data && webhook.payload.data.object
 *             && webhook.payload.data.object.on_behalf_of === 'acct_xxx');
 *     if (!matches) {
 *         webhook.cancel = true;
 *     }
 *     return webhook;
 * }
 * ```
 *
 * @package Civi\Svixclient\Filter
 */
class AnyFieldFilter implements FilterStrategyInterface {

  use EscapesJsStrings;

  /**
   * Dot-separated field paths in the webhook payload.
   *
   * @var string[]
   */
  private array $fields;

  /**
   * The value to match against.
   *
   * @var string
   */
  private string $value;

  /**
   * AnyFieldFilter constructor.
   *
   * @param string[] $fields
   *   Dot-separated field paths in the webhook payload, e.g. 'account' or
   *   'data.object.transfer_data.destination'.
   * @param string $value
   *   The value to match (e.g. 'acct_xxx').
   *
   * @throws \InvalidArgumentException
   *   If no fields are given, a field is not a plain property path, or the
   *   value is empty.
   */
  public function __construct(array $fields, string $value) {
    if ($fields === []) {
      throw new \InvalidArgumentException('AnyFieldFilter needs at least one field.');
    }
    foreach ($fields as $field) {
      // Fields are JavaScript property accessors, not strings, so they are
      // not escaped: only plain dotted identifiers are allowed.
      if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $field)) {
        throw new \InvalidArgumentException("Invalid field path for AnyFieldFilter: {$field}");
      }
    }
    if ($value === '') {
      throw new \InvalidArgumentException('AnyFieldFilter needs a non-empty routing value.');
    }

    $this->fields = array_values($fields);
    $this->value = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): string {
    $escapedValue = $this->escapeJsString($this->value);
    $conditions = array_map(
      fn (string $field): string => '(' . $this->buildCondition($field, $escapedValue) . ')',
      $this->fields
    );
    $matches = implode("\n        || ", $conditions);

    return <<<JS
function handler(webhook) {
    var matches = {$matches};
    if (!matches) {
        webhook.cancel = true;
    }
    return webhook;
}
JS;
  }

  /**
   * Builds a null-safe comparison for one field path.
   *
   * For 'data.object.on_behalf_of' this gives:
   *   webhook.payload.data && webhook.payload.data.object
   *   && webhook.payload.data.object.on_behalf_of === 'value'
   *
   * @param string $field
   *   The dot-separated field path.
   * @param string $escapedValue
   *   The escaped routing value.
   *
   * @return string
   *   The JavaScript condition.
   */
  private function buildCondition(string $field, string $escapedValue): string {
    $parts = explode('.', $field);
    $checks = [];
    $path = 'webhook.payload';
    foreach (array_slice($parts, 0, -1) as $part) {
      $path .= '.' . $part;
      $checks[] = $path;
    }
    $checks[] = "webhook.payload.{$field} === '{$escapedValue}'";

    return implode(' && ', $checks);
  }

}
