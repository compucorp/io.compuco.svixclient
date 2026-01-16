<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

/**
 * Interface for Svix webhook routing filter strategies.
 *
 * This interface defines the contract for filter builders following
 * SOLID principles (Strategy Pattern, Open/Closed Principle).
 *
 * ## Design Pattern: Strategy
 *
 * Different payment processors may need different routing logic:
 * - Stripe: Simple field match on 'account'
 * - GoCardless: Field match on 'links.organisation'
 * - Custom: Complex array matching or multiple conditions
 *
 * ## Usage
 *
 * ```php
 * // Simple field filter (most common)
 * $filter = new SimpleFieldFilter('account', 'acct_xxx');
 * $jsCode = $filter->build();
 *
 * // Or use the convenience static method
 * $jsCode = \CRM_Svixclient_Client::buildRoutingFilter('account', 'acct_xxx');
 * ```
 *
 * ## Implementing Custom Filters
 *
 * ```php
 * class MyCustomFilter implements FilterStrategyInterface {
 *   public function build(): string {
 *     return <<<JS
 *     function handler(input) {
 *       // Custom logic here
 *       return { payload: input };
 *     }
 *     JS;
 *   }
 * }
 * ```
 *
 * @package Civi\Svixclient\Filter
 */
interface FilterStrategyInterface {

  /**
   * Build the JavaScript filter function.
   *
   * The returned JavaScript must be a function named `handler` that:
   * 1. Accepts an `input` parameter (the webhook payload)
   * 2. Returns `null` to reject the webhook (not for this destination)
   * 3. Returns `{ payload: input }` to accept and forward the webhook.
   *
   * @return string
   *   The JavaScript filter function code.
   */
  public function build(): string;

}
