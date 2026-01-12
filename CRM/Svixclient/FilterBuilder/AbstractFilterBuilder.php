<?php

declare(strict_types=1);

/**
 * Abstract base class for Svix filter builders.
 *
 * Payment extensions should extend this class to create their own
 * filter builders with provider-specific logic.
 *
 * Example implementation in a Stripe extension:
 * @code
 * class CRM_Stripe_Svix_FilterBuilder extends CRM_Svixclient_FilterBuilder_AbstractFilterBuilder {
 *   private string $accountId;
 *
 *   public function setAccountId(string $accountId): self {
 *     $this->accountId = $accountId;
 *     return $this;
 *   }
 *
 *   public function build(): string {
 *     $escaped = $this->escapeJsString($this->accountId);
 *     return "function handler(input) {
 *       if (input.account !== '{$escaped}') return null;
 *       return { payload: input };
 *     }";
 *   }
 * }
 * @endcode
 */
abstract class CRM_Svixclient_FilterBuilder_AbstractFilterBuilder implements CRM_Svixclient_FilterBuilder_FilterBuilderInterface {

  /**
   * Escape a string for safe use in JavaScript single-quoted strings.
   *
   * Uses json_encode for proper escaping of all special characters
   * including newlines, unicode, and control characters.
   *
   * @param string $value
   *   The value to escape.
   *
   * @return string
   *   The escaped value safe for JavaScript strings.
   */
  protected function escapeJsString(string $value): string {
    // json_encode properly escapes all special characters for JavaScript.
    // Preserve unicode and slashes as-is.
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === FALSE) {
      throw new \InvalidArgumentException('Failed to encode value for JavaScript');
    }
    // Remove surrounding double quotes and convert for single-quoted JS string.
    $inner = substr($encoded, 1, -1);
    // Convert escaped double quotes to regular, escape single quotes.
    return str_replace(['\\"', "'"], ['"', "\\'"], $inner);
  }

}
