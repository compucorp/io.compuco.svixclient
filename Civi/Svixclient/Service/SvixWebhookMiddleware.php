<?php

declare(strict_types=1);

namespace Civi\Svixclient\Service;

use Civi\Api4\SvixDestination;
use Civi\Svixclient\Enum\SvixIntegrationConfig;
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
   * Verify a Svix-forwarded webhook for a given destination type.
   *
   * @param string $payload
   *   The raw webhook payload (POST body).
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   * @param array|null $headers
   *   Optional Svix headers. If not provided, extracts from current request.
   *
   * @return array
   *   Result array with keys: valid, message, error.
   */
  public function verifyForType(string $payload, string $type, ?array $headers = NULL): array {
    $secret = $this->getSecretForType($type);

    if ($secret === NULL) {
      return [
        'valid' => FALSE,
        'message' => 'No Svix signing secret found for type',
        'error' => "No Svix destination configured for type: {$type}",
      ];
    }

    try {
      $isValid = \CRM_Svixclient_Client::verifyWebhook($payload, $headers ?? $this->getSvixHeaders(), $secret);

      return [
        'valid' => $isValid,
        'message' => 'Webhook signature verified successfully',
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      \Civi::log()->warning('Svix webhook verification failed', [
        'type' => $type,
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
   * Get the stored destination record for a destination type.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return array|null
   *   The destination record, or NULL if none exists.
   */
  public function getDestinationForType(string $type): ?array {
    try {
      $result = SvixDestination::get(FALSE)
        ->addWhere('type', '=', $type)
        ->addOrderBy('id', 'DESC')
        ->execute()
        ->first();

      return is_array($result) ? $result : NULL;
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to get Svix destination by type', [
        'type' => $type,
        'error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get the Svix signing secret for a destination type.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return string|null
   *   The signing secret, or NULL if not found.
   */
  public function getSecretForType(string $type): ?string {
    $destination = $this->getDestinationForType($type);

    if ($destination === NULL || empty($destination['signing_secret'])) {
      \Civi::log()->info('No Svix signing secret found', ['type' => $type]);
      return NULL;
    }

    return $destination['signing_secret'];
  }

  /**
   * Check whether a destination is registered for a destination type.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return bool
   *   TRUE if a Svix destination exists for this type.
   */
  public function isEnabledForType(string $type): bool {
    return $this->getDestinationForType($type) !== NULL;
  }

  /**
   * Get the configuration status for a non-payment-processor integration.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return array{enabled: bool, message: string}
   *   Status array with an enabled flag and a descriptive message.
   */
  public function getIntegrationConfigurationStatus(string $type): array {
    $config = SvixIntegrationConfig::fromType($type);
    if ($config === NULL) {
      return [
        'enabled' => FALSE,
        'message' => "Unsupported integration type for Svix: {$type}",
      ];
    }

    $svixStatus = $this->getConfigurationStatus();
    if (!$svixStatus['configured']) {
      return [
        'enabled' => FALSE,
        'message' => $svixStatus['message'],
      ];
    }

    if ($config->getSourceId() === NULL) {
      return [
        'enabled' => FALSE,
        'message' => "Cannot connect to Svix. Set the '{$config->getSourceIdSetting()}' setting.",
      ];
    }

    return [
      'enabled' => TRUE,
      'message' => 'Connected to Svix',
    ];
  }

  /**
   * Get the Svix ingest URL for an integration.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   *
   * @return string|null
   *   The ingest URL, or NULL if it cannot be determined.
   */
  public function getIngestUrlForType(string $type): ?string {
    $config = SvixIntegrationConfig::fromType($type);
    $sourceId = $config?->getSourceId();
    if ($sourceId === NULL) {
      return NULL;
    }

    try {
      return (new \CRM_Svixclient_Client())->getIngestUrl($sourceId);
    }
    catch (\Exception $e) {
      \Civi::log()->warning('Could not resolve Svix ingest URL', [
        'type' => $type,
        'error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Delete the Svix destination registered for a destination type.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   */
  public function deleteDestinationForType(string $type): void {
    $destination = $this->getDestinationForType($type);

    if ($destination === NULL) {
      \Civi::log()->debug('No Svix destination found for type', ['type' => $type]);
      return;
    }

    $this->removeDestination($destination);

    \Civi::log()->info('Svix destination deleted', [
      'svix_destination_id' => $destination['svix_destination_id'],
      'type' => $type,
    ]);
  }

  /**
   * Delete a destination record from Svix and from the database.
   *
   * @param array $destination
   *   The stored destination record.
   */
  private function removeDestination(array $destination): void {
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

    SvixDestination::delete(FALSE)
      ->addWhere('id', '=', $destination['id'])
      ->execute();
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
   * for the specified routing value to this CiviCRM site. Checks for
   * existing destinations and disables duplicates.
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

    // Get webhook URL.
    $webhookUrl = $this->getWebhookUrlForProcessor($processorType);

    // Build routing filter using processor-specific strategy.
    $filter = \CRM_Svixclient_Client::buildFilter($config->getFilterStrategy($routingValue));

    $description = str_replace('{value}', $routingValue, $config->getDescriptionTemplate());

    // Disable any existing destinations for this URL and routing value.
    $client = $this->createClient();
    $this->disableExistingDestinations($client, $sourceId, $webhookUrl, $description, $paymentProcessorId);

    // Create new destination.
    return $this->createNewDestination(
      $client,
      $sourceId,
      $webhookUrl,
      $filter,
      $description,
      $routingValue,
      $processorType,
      $paymentProcessorId,
      $contactId
    );
  }

  /**
   * Register a Svix destination for a non-payment-processor integration.
   *
   * @param string $type
   *   The integration name, e.g. 'Impact Stack'.
   * @param int|null $contactId
   *   Optional contact ID of who created this destination.
   *
   * @return string
   *   The Svix destination ID.
   *
   * @throws \CRM_Core_Exception
   *   If the integration is not supported or destination creation fails.
   */
  public function registerIntegrationDestination(string $type, ?int $contactId = NULL): string {
    $config = SvixIntegrationConfig::fromType($type);
    if ($config === NULL) {
      throw new \CRM_Core_Exception("Unsupported integration type for Svix: {$type}");
    }

    $sourceId = $config->getSourceId();
    if ($sourceId === NULL) {
      throw new \CRM_Core_Exception("Svix source ID not configured for {$type}. Set the '{$config->getSourceIdSetting()}' setting.");
    }

    $webhookUrl = $config->getWebhookUrl();
    $previous = $this->getDestinationForType($type);

    $description = str_replace('{value}', $webhookUrl, $config->getDescriptionTemplate());

    $client = $this->createClient();

    $destinationId = $this->createNewDestination(
      $client,
      $sourceId,
      $webhookUrl,
      NULL,
      $description,
      $webhookUrl,
      $type,
      NULL,
      $contactId
    );

    $this->disableExistingDestinations($client, $sourceId, $webhookUrl, $description, NULL, $destinationId);

    // The replacement is live, so the superseded record can now go.
    if ($previous !== NULL) {
      $this->removeDestination($previous);
    }

    return $destinationId;
  }

  /**
   * Create a Svix client instance.
   *
   * Overridable so tests can supply a double.
   *
   * @return \CRM_Svixclient_Client
   *   The Svix client.
   */
  protected function createClient(): \CRM_Svixclient_Client {
    return new \CRM_Svixclient_Client();
  }

  /**
   * Find and disable existing destinations with the same URL.
   *
   * Ensures only one destination per site URL and routing value. The
   * description embeds the routing value (e.g. the GoCardless organisation
   * ID), so destinations belonging to other routing values — such as a Live
   * account's destination while re-registering a Test account on the same
   * site — are left untouched.
   *
   * @param \CRM_Svixclient_Client $client
   *   The Svix client.
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $webhookUrl
   *   The webhook URL to match.
   * @param string $description
   *   The destination description to match (routing value included).
   * @param int|null $paymentProcessorId
   *   The payment processor being (re-)registered. Destinations recorded
   *   locally against a different processor are never disabled, even when
   *   URL and description match (e.g. the same sandbox organisation
   *   connected as both Live and Test in a dev environment).
   *   NULL for integrations that are not tied to a payment processor: those
   *   have a Svix source of their own, so every destination this lists
   *   already belongs to them and there is no sibling to protect.
   * @param string|null $keepDestinationId
   *   A Svix destination ID to leave enabled. Set when the replacement has
   *   already been created: it matches the same URL and description, so
   *   without this it would disable itself.
   */
  private function disableExistingDestinations(
    \CRM_Svixclient_Client $client,
    string $sourceId,
    string $webhookUrl,
    string $description,
    ?int $paymentProcessorId = NULL,
    ?string $keepDestinationId = NULL,
  ): void {
    $destinations = $client->listDestinations($sourceId);
    $otherProcessorDestinationIds = $paymentProcessorId === NULL
      ? []
      : $this->getOtherProcessorDestinationIds($paymentProcessorId);

    \Civi::log()->debug('Checking for existing destinations to disable', [
      'source_id' => $sourceId,
      'webhook_url' => $webhookUrl,
      'description' => $description,
      'total_destinations' => count($destinations),
    ]);

    foreach ($destinations as $dest) {
      if ($keepDestinationId !== NULL && ($dest['id'] ?? '') === $keepDestinationId) {
        continue;
      }

      // Check if URL matches (normalize by removing trailing ? or /).
      $destUrl = rtrim($dest['url'] ?? '', '?/');
      $compareUrl = rtrim($webhookUrl, '?/');

      if ($destUrl !== $compareUrl) {
        continue;
      }

      // Only disable destinations for the same routing value. The site's
      // Live and Test accounts share one webhook URL, so URL alone would
      // disable the other account's destination.
      if (($dest['description'] ?? '') !== $description) {
        continue;
      }

      // Never disable a destination registered to another payment
      // processor — on dev environments the same sandbox organisation can
      // be connected as both Live and Test, so even the description
      // cannot tell the two apart.
      if (in_array($dest['id'] ?? '', $otherProcessorDestinationIds, TRUE)) {
        continue;
      }

      // Skip already disabled destinations.
      if (!empty($dest['disabled'])) {
        continue;
      }

      \Civi::log()->info('Disabling existing Svix destination for URL', [
        'source_id' => $sourceId,
        'destination_id' => $dest['id'],
        'url' => $destUrl,
        'description' => $description,
      ]);

      $client->disableDestination($sourceId, $dest['id']);
    }
  }

  /**
   * Get Svix destination IDs registered to other payment processors.
   *
   * @param int $paymentProcessorId
   *   The payment processor being (re-)registered.
   *
   * @return string[]
   *   Svix destination IDs belonging to other payment processors.
   */
  private function getOtherProcessorDestinationIds(int $paymentProcessorId): array {
    return SvixDestination::get(FALSE)
      ->addSelect('svix_destination_id')
      ->addWhere('payment_processor_id', '!=', $paymentProcessorId)
      ->execute()
      ->column('svix_destination_id');
  }

  /**
   * Create a new Svix destination.
   *
   * @param \CRM_Svixclient_Client $client
   *   The Svix client.
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $webhookUrl
   *   The webhook URL.
   * @param string $filter
   *   The routing filter code.
   * @param string $description
   *   The destination description (routing value included).
   * @param string $routingValue
   *   The routing value, used to build the destination description.
   * @param string $type
   *   The destination type: a payment processor type name, or an integration
   *   name such as 'Impact Stack'.
   * @param int|null $paymentProcessorId
   *   The CiviCRM payment processor ID, or NULL for integrations that are not
   *   tied to a payment processor.
   * @param int|null $contactId
   *   Optional contact ID.
   *
   * @return string
   *   The new destination ID.
   */
  private function createNewDestination(
    \CRM_Svixclient_Client $client,
    string $sourceId,
    string $webhookUrl,
    ?string $filter,
    string $description,
    string $routingValue,
    string $type,
    ?int $paymentProcessorId,
    ?int $contactId,
  ): string {
    // Create destination via Svix client.
    $destination = $client->createDestination($sourceId, $webhookUrl, $description);

    // Set the transformation (filter). Integrations with a dedicated source
    // receive every event from that source, so they have no filter.
    if ($filter !== NULL) {
      $client->setTransformation($sourceId, $destination['id'], $filter);
    }

    // Get the signing secret.
    $signingSecret = $client->getDestinationSecret($sourceId, $destination['id']);

    // Store in database.
    $createAction = SvixDestination::create(FALSE)
      ->addValue('source_id', $sourceId)
      ->addValue('svix_destination_id', $destination['id'])
      ->addValue('signing_secret', $signingSecret)
      ->addValue('type', $type);

    if ($paymentProcessorId !== NULL) {
      $createAction->addValue('payment_processor_id', $paymentProcessorId);
    }

    $createdBy = $contactId ?? \CRM_Core_Session::getLoggedInContactID();
    if ($createdBy !== NULL) {
      $createAction->addValue('created_by', $createdBy);
    }

    $createAction->execute();

    \Civi::log()->info('Svix destination registered', [
      'routing_value' => $routingValue,
      'type' => $type,
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

    $this->removeDestination($destination);

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
