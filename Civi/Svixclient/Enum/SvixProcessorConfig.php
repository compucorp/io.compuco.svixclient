<?php

declare(strict_types=1);

namespace Civi\Svixclient\Enum;

use Civi\Svixclient\Filter\ArrayFieldFilter;
use Civi\Svixclient\Filter\FilterStrategyInterface;
use Civi\Svixclient\Filter\SimpleFieldFilter;

/**
 * Configuration for payment processors that use Svix webhook routing.
 *
 * This enum defines the mapping between payment processor types and their
 * Svix configuration (routing field and source ID setting name).
 *
 * To add a new processor:
 * 1. Add a new case with the processor type name as value
 * 2. Add the routing field in getRoutingField()
 * 3. Add the source ID setting name in getSourceIdSetting()
 * 4. Create the setting in Svix extension's settings
 */
enum SvixProcessorConfig: string {

  case StripeConnect = 'Stripe Connect';
  case Gocardless = 'GoCardless';

  /**
   * Get the routing field for this processor.
   *
   * The routing field is the JSON path in the webhook payload
   * that identifies which destination should receive the webhook.
   *
   * @return string
   *   The routing field path.
   */
  public function getRoutingField(): string {
    return match ($this) {
      self::StripeConnect => 'account',
      self::Gocardless => 'links.organisation',
    };
  }

  /**
   * Get the CiviCRM setting name for the source ID.
   *
   * Each processor has its own Svix source, configured as a setting.
   * The source ID is shared across all sites using the same Svix account.
   *
   * @return string
   *   The setting name for the source ID.
   */
  public function getSourceIdSetting(): string {
    return match ($this) {
      self::StripeConnect => 'svix_source_stripe_connect',
      self::Gocardless => 'svix_source_gocardless',
    };
  }

  /**
   * Get the description template for destinations.
   *
   * Used when creating Svix destinations to provide a human-readable
   * description. The {value} placeholder is replaced with the routing value.
   *
   * @return string
   *   The description template.
   */
  public function getDescriptionTemplate(): string {
    return match ($this) {
      self::StripeConnect => 'CiviCRM Stripe - {value}',
      self::Gocardless => 'CiviCRM GoCardless - {value}',
    };
  }

  /**
   * Create config from processor type name.
   *
   * @param string $processorType
   *   The payment processor type name.
   *
   * @return self|null
   *   The config enum, or NULL if not found.
   */
  public static function fromProcessorType(string $processorType): ?self {
    return self::tryFrom($processorType);
  }

  /**
   * Get the filter strategy for this processor.
   *
   * Returns the appropriate filter strategy based on the processor type.
   * Stripe uses a simple field filter; GoCardless uses an array field
   * filter to handle multi-organisation webhook payloads.
   *
   * @param string $routingValue
   *   The routing value to match (e.g., 'acct_xxx' or 'OR000123').
   *
   * @return \Civi\Svixclient\Filter\FilterStrategyInterface
   *   The filter strategy instance.
   */
  public function getFilterStrategy(string $routingValue): FilterStrategyInterface {
    return match ($this) {
      self::StripeConnect => new SimpleFieldFilter($this->getRoutingField(), $routingValue),
      self::Gocardless => new ArrayFieldFilter('events', 'links.organisation', $routingValue),
    };
  }

  /**
   * Get the source ID for this processor from settings.
   *
   * @return string|null
   *   The source ID, or NULL if not configured.
   */
  public function getSourceId(): ?string {
    $value = \Civi::settings()->get($this->getSourceIdSetting());
    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
