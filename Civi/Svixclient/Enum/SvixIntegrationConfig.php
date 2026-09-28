<?php

declare(strict_types=1);

namespace Civi\Svixclient\Enum;

/**
 * Configuration for non-payment-processor integrations that use Svix.
 */
enum SvixIntegrationConfig: string {

  case ImpactStack = 'Impact Stack';

  /**
   * Get the CiviCRM setting name holding this integration's Svix source ID.
   *
   * @return string
   *   The setting name for the source ID.
   */
  public function getSourceIdSetting(): string {
    return match ($this) {
      self::ImpactStack => 'svix_source_impact_stack',
    };
  }

  /**
   * Get the CiviCRM path that receives webhooks for this integration.
   *
   * @return string
   *   The internal CiviCRM path (without a leading slash).
   */
  public function getWebhookPath(): string {
    return match ($this) {
      self::ImpactStack => 'civicrm/impactstack/api/webhook',
    };
  }

  /**
   * Get the description used when creating the Svix destination.
   *
   * @return string
   *   The description template.
   */
  public function getDescriptionTemplate(): string {
    return match ($this) {
      self::ImpactStack => 'CiviCRM Impact Stack - {value}',
    };
  }

  /**
   * Create config from an integration name.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return self|null
   *   The config enum, or NULL if not found.
   */
  public static function fromType(string $type): ?self {
    return self::tryFrom($type);
  }

  /**
   * Get the Svix source ID for this integration from settings.
   *
   * @return string|null
   *   The source ID, or NULL if not configured.
   */
  public function getSourceId(): ?string {
    $value = \Civi::settings()->get($this->getSourceIdSetting());
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Get the absolute CiviCRM webhook URL for this integration.
   *
   * @return string
   *   The absolute URL Svix should deliver events to.
   */
  public function getWebhookUrl(): string {
    return \CRM_Utils_System::url($this->getWebhookPath(), '', TRUE);
  }

}
