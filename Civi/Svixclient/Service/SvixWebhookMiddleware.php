<?php

declare(strict_types=1);

namespace Civi\Svixclient\Service;

use Civi\Api4\SvixDestination;
use Civi\Svixclient\Enum\SvixProcessorConfig;

/**
 * Middleware service for verifying Svix-forwarded webhooks.
 *
 * This service provides reusable webhook signature verification
 * for any payment processor extension that uses Svix for webhook routing.
 *
 * Usage:
 * @code
 * $middleware = \Civi::service('svix.webhook_middleware');
 * if ($middleware->isSvixRequest()) {
 *   $result = $middleware->verify($payload, 'Stripe Connect');
 *   if (!$result['valid']) {
 *     throw new \Exception('Signature verification failed');
 *   }
 * }
 * @endcode
 *
 * @package Civi\Svixclient\Service
 */
class SvixWebhookMiddleware {

  /**
   * Check if the current request has Svix headers.
   *
   * Looks for the svix-signature header which indicates the webhook
   * was forwarded through Svix.
   *
   * @return bool
   *   TRUE if Svix headers are present, FALSE otherwise.
   */
  public function isSvixRequest(): bool {
    return !empty($this->getSvixHeaders()['svix-signature']);
  }

  /**
   * Get Svix headers from the current request.
   *
   * Extracts the three required Svix headers from $_SERVER.
   *
   * @return array
   *   Array with keys: svix-id, svix-timestamp, svix-signature.
   */
  public function getSvixHeaders(): array {
    return [
      'svix-id' => $_SERVER['HTTP_SVIX_ID'] ?? '',
      'svix-timestamp' => $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? '',
      'svix-signature' => $_SERVER['HTTP_SVIX_SIGNATURE'] ?? '',
    ];
  }

  /**
   * Verify a Svix-forwarded webhook.
   *
   * Looks up the signing secret for the given payment processor type
   * and verifies the webhook signature.
   *
   * @param string $payload
   *   The raw webhook payload (POST body).
   * @param string $processorTypeName
   *   The payment processor type name (e.g., 'Stripe Connect', 'GoCardless').
   * @param array|null $headers
   *   Optional Svix headers. If not provided, extracts from current request.
   *
   * @return array
   *   Result array with keys:
   *   - valid: bool - Whether signature is valid
   *   - message: string - Description of result
   *   - error: string|null - Error message if validation failed
   */
  public function verify(string $payload, string $processorTypeName, ?array $headers = NULL): array {
    if ($headers === NULL) {
      $headers = $this->getSvixHeaders();
    }

    // Get the signing secret for this processor type.
    $secret = $this->getSecretForProcessorType($processorTypeName);

    if ($secret === NULL) {
      return [
        'valid' => FALSE,
        'message' => 'No Svix signing secret found for processor type',
        'error' => "No Svix destination configured for processor type: {$processorTypeName}",
      ];
    }

    try {
      $isValid = \CRM_Svixclient_Client::verifyWebhook($payload, $headers, $secret);

      return [
        'valid' => $isValid,
        'message' => 'Webhook signature verified successfully',
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      \Civi::log()->warning('Svix webhook verification failed', [
        'processor_type' => $processorTypeName,
        'error' => $e->getMessage(),
      ]);

      return [
        'valid' => FALSE,
        'message' => 'Webhook signature verification failed',
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Get the Svix signing secret for a payment processor type.
   *
   * Looks up the SvixDestination record associated with the given
   * payment processor type and returns its signing secret.
   *
   * @param string $processorTypeName
   *   The payment processor type name (e.g., 'Stripe Connect').
   *
   * @return string|null
   *   The signing secret, or NULL if not found.
   */
  public function getSecretForProcessorType(string $processorTypeName): ?string {
    try {
      // Query supports both test and live processors.
      $destination = SvixDestination::get(FALSE)
        ->addSelect('signing_secret')
        ->addJoin('PaymentProcessor AS pp', 'INNER', ['payment_processor_id', '=', 'pp.id'])
        ->addWhere('pp.payment_processor_type_id:name', '=', $processorTypeName)
        ->addWhere('pp.is_active', '=', TRUE)
        ->execute()
        ->first();

      if ($destination === NULL || empty($destination['signing_secret'])) {
        \Civi::log()->info('No Svix signing secret found', [
          'processor_type' => $processorTypeName,
        ]);
        return NULL;
      }

      return $destination['signing_secret'];
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to get Svix signing secret', [
        'processor_type' => $processorTypeName,
        'error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Check if Svix integration is enabled for a processor type.
   *
   * @param string $processorTypeName
   *   The payment processor type name.
   *
   * @return bool
   *   TRUE if a Svix destination exists for this processor type.
   */
  public function isEnabledForProcessorType(string $processorTypeName): bool {
    return $this->getSecretForProcessorType($processorTypeName) !== NULL;
  }

  /**
   * Check if Svix is configured with an API key.
   *
   * This checks if the basic Svix configuration (API key) is present,
   * regardless of any processor-specific settings.
   *
   * @return bool
   *   TRUE if Svix API key is configured.
   */
  public function isConfigured(): bool {
    return $this->getConfigurationStatus()['configured'];
  }

  /**
   * Get detailed Svix configuration status.
   *
   * Returns information about whether Svix is properly configured.
   * Processor extensions can use this to show appropriate messages
   * without needing to know about Svix internals.
   *
   * @return array{configured: bool, message: string}
   *   Status array with configured flag and descriptive message.
   */
  public function getConfigurationStatus(): array {
    // Check if API key is configured (setting or environment variable).
    $apiKey = \Civi::settings()->get('svix_api_key');
    if (empty($apiKey) && empty(getenv('SVIX_API_KEY'))) {
      return [
        'configured' => FALSE,
        'message' => 'Svix API key is not configured.',
      ];
    }

    return [
      'configured' => TRUE,
      'message' => 'Svix is configured.',
    ];
  }

  /**
   * Register a Svix destination for a payment processor.
   *
   * Creates a new webhook destination in Svix that routes webhooks
   * for the specified routing value to this CiviCRM site.
   *
   * @param string $processorType
   *   The payment processor type name (e.g., 'Stripe Connect').
   * @param int $paymentProcessorId
   *   The CiviCRM payment processor ID.
   * @param string $routingValue
   *   The value to match for routing (e.g., Stripe account ID 'acct_xxx').
   * @param int|null $contactId
   *   Optional contact ID of who created this destination.
   *
   * @return string
   *   The Svix destination ID.
   *
   * @throws \CRM_Core_Exception
   *   If processor type is not supported or destination creation fails.
   */
  public function registerDestination(
    string $processorType,
    int $paymentProcessorId,
    string $routingValue,
    ?int $contactId = NULL,
  ): string {
    $config = SvixProcessorConfig::fromProcessorType($processorType);
    if ($config === NULL) {
      throw new \CRM_Core_Exception("Unsupported processor type for Svix: {$processorType}");
    }

    $sourceId = $config->getSourceId();
    if ($sourceId === NULL) {
      throw new \CRM_Core_Exception("Svix source ID not configured for {$processorType}. Set the '{$config->getSourceIdSetting()}' setting.");
    }

    // Build routing filter.
    $filter = \CRM_Svixclient_Client::buildRoutingFilter($config->getRoutingField(), $routingValue);

    // Build description.
    $description = str_replace('{value}', $routingValue, $config->getDescriptionTemplate());

    // Get webhook URL.
    $webhookUrl = $this->getWebhookUrlForProcessor($processorType);

    // Create destination via Svix client.
    $client = new \CRM_Svixclient_Client();
    $destination = $client->createDestination($sourceId, $webhookUrl, $description);

    // Set the transformation (filter).
    $client->setTransformation($sourceId, $destination['id'], $filter);

    // Get the signing secret.
    $signingSecret = $client->getDestinationSecret($sourceId, $destination['id']);

    // Store in database.
    $createAction = SvixDestination::create(FALSE)
      ->addValue('source_id', $sourceId)
      ->addValue('svix_destination_id', $destination['id'])
      ->addValue('signing_secret', $signingSecret)
      ->addValue('payment_processor_id', $paymentProcessorId);

    // Use provided contact ID or fall back to logged-in contact.
    $createdBy = $contactId ?? \CRM_Core_Session::getLoggedInContactID();
    if ($createdBy !== NULL) {
      $createAction->addValue('created_by', $createdBy);
    }

    $createAction->execute();

    \Civi::log()->info('Svix destination registered', [
      'processor_type' => $processorType,
      'routing_value' => $routingValue,
      'svix_destination_id' => $destination['id'],
      'payment_processor_id' => $paymentProcessorId,
    ]);

    return $destination['id'];
  }

  /**
   * Delete Svix destination for a payment processor.
   *
   * Removes the Svix destination from both Svix and the local database.
   *
   * @param int $paymentProcessorId
   *   The CiviCRM payment processor ID.
   */
  public function deleteDestination(int $paymentProcessorId): void {
    // Find destination record.
    $destination = SvixDestination::get(FALSE)
      ->addWhere('payment_processor_id', '=', $paymentProcessorId)
      ->execute()
      ->first();

    if ($destination === NULL) {
      \Civi::log()->debug('No Svix destination found for payment processor', [
        'payment_processor_id' => $paymentProcessorId,
      ]);
      return;
    }

    // Delete from Svix (ignore errors - destination may already be deleted).
    try {
      $client = new \CRM_Svixclient_Client();
      $client->deleteDestination($destination['source_id'], $destination['svix_destination_id']);
    }
    catch (\Exception $e) {
      \Civi::log()->warning('Failed to delete Svix destination from Svix API', [
        'svix_destination_id' => $destination['svix_destination_id'],
        'error' => $e->getMessage(),
      ]);
    }

    // Delete from database.
    SvixDestination::delete(FALSE)
      ->addWhere('id', '=', $destination['id'])
      ->execute();

    \Civi::log()->info('Svix destination deleted', [
      'svix_destination_id' => $destination['svix_destination_id'],
      'payment_processor_id' => $paymentProcessorId,
    ]);
  }

  /**
   * Get the webhook URL for a processor type.
   *
   * @param string $processorType
   *   The payment processor type name.
   *
   * @return string
   *   The absolute URL for the webhook endpoint.
   */
  private function getWebhookUrlForProcessor(string $processorType): string {
    // Map processor types to their webhook paths.
    $paths = [
      'Stripe Connect' => 'civicrm/stripe/webhook',
      'GoCardless' => 'civicrm/gocardless/webhook',
    ];

    $path = $paths[$processorType] ?? 'civicrm/payment/webhook';
    return \CRM_Utils_System::url($path, '', TRUE);
  }

}
