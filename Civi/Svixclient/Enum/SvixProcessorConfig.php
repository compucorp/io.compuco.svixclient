<?php

declare(strict_types=1);

namespace Civi\Svixclient\Enum;

use Civi\Svixclient\Filter\AnyFieldFilter;
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

  /**
   * Get the CiviCRM setting name for the platform source ID.
   *
   * Some processors also send events from the platform account itself.
   * Stripe Express payments are destination charges on the platform, so
   * their events come from a separate Stripe destination ("Events from:
   * Your account") into a separate Svix source.
   *
   * @return string|null
   *   The setting name, or NULL if the processor has no platform source.
   */
  public function getPlatformSourceIdSetting(): ?string {
    return match ($this) {
      self::StripeConnect => 'svix_source_stripe_platform',
      self::Gocardless => NULL,
    };
  }

  /**
   * Get the platform source ID for this processor from settings.
   *
   * @return string|null
   *   The platform source ID, or NULL if not supported or not configured.
   */
  public function getPlatformSourceId(): ?string {
    $setting = $this->getPlatformSourceIdSetting();
    if ($setting === NULL) {
      return NULL;
    }

    $value = \Civi::settings()->get($setting);
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Get the filter strategy for the platform source.
   *
   * Stripe platform events have no `account` field. Destination-charge
   * PaymentIntents and charges carry the connected account in
   * transfer_data.destination and on_behalf_of; Checkout Sessions carry it
   * in metadata.connected_account_id (set by the Stripe extension, see
   * CRM_Stripe_Helper_Constants::CONNECTED_ACCOUNT_METADATA_KEY).
   *
   * That covers the events the platform event destination sends:
   * payment_intent.succeeded / payment_failed / canceled (PaymentIntent),
   * charge.refunded (Charge) and checkout.session.expired (Checkout
   * Session). Refund (refund.*, charge.refund.updated), Dispute
   * (charge.dispute.*) and SetupIntent objects carry none of these fields,
   * so if those events are ever sent, every destination cancels them: they
   * are never delivered, and never delivered to the wrong site.
   *
   * @param string $routingValue
   *   The routing value to match (e.g. 'acct_xxx').
   *
   * @return \Civi\Svixclient\Filter\FilterStrategyInterface|null
   *   The filter strategy, or NULL if the processor has no platform source.
   */
  public function getPlatformFilterStrategy(string $routingValue): ?FilterStrategyInterface {
    return match ($this) {
      self::StripeConnect => new AnyFieldFilter([
        'account',
        'data.object.transfer_data.destination',
        'data.object.on_behalf_of',
        'data.object.metadata.connected_account_id',
      ], $routingValue),
      self::Gocardless => NULL,
    };
  }

  /**
   * Get the description template for platform destinations.
   *
   * Different from getDescriptionTemplate() so a platform destination is
   * never mistaken for the processor's main destination when existing
   * destinations are disabled on re-registration.
   *
   * @return string|null
   *   The description template, or NULL if the processor has no platform
   *   source.
   */
  public function getPlatformDescriptionTemplate(): ?string {
    return match ($this) {
      self::StripeConnect => 'CiviCRM Stripe platform - {value}',
      self::Gocardless => NULL,
    };
  }

}
