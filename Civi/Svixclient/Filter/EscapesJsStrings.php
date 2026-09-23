<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

/**
 * Shared JavaScript string escaping for filter strategies.
 *
 * Uses json_encode() for base escaping, then escapes single quotes
 * since the generated JS uses single-quoted strings.
 *
 * @package Civi\Svixclient\Filter
 */
trait EscapesJsStrings {

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
