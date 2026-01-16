<?php

declare(strict_types=1);

use Civi\Svixclient\Filter\FilterStrategyInterface;
use Civi\Svixclient\Filter\SimpleFieldFilter;
use Svix\Webhook;

/**
 * Svix API Client for CiviCRM.
 *
 * Provides methods to create and delete webhook destinations in Svix Ingest,
 * as well as verify incoming webhooks.
 *
 * Note: The Svix PHP SDK is used for webhook verification only.
 * The Ingest API (for destination management) is accessed via REST.
 */
class CRM_Svixclient_Client {

  /**
   * Base URL for Svix Ingest API.
   *
   * Protected to allow test classes to override for mock servers.
   * Loaded from settings or defaults to US server.*/
  protected string $baseUrl;

  /**
   * Svix API key.
   */
  private string $apiKey;

  /**
   * Constructor.
   *
   * @throws CRM_Core_Exception
   *   If Svix API key is not configured.
   */
  public function __construct() {
    $this->baseUrl = $this->loadServerUrl();
    $this->apiKey = $this->loadApiKey();
  }

  /**
   * Load the Svix server URL from settings.
   *
   * @return string
   *   The Svix server URL.
   */
  private function loadServerUrl(): string {
    $url = \Civi::settings()->get('svix_server_url');

    if (empty($url)) {
      // Default to US server if not configured.
      $url = 'https://api.svix.com';
    }

    return rtrim($url, '/');
  }

  /**
   * Load the Svix API key from settings or environment variable.
   *
   * @return string
   *   The Svix API key.
   *
   * @throws CRM_Core_Exception
   *   If no API key is configured.
   */
  private function loadApiKey(): string {
    $key = \Civi::settings()->get('svix_api_key');

    if (empty($key)) {
      $key = getenv('SVIX_API_KEY');
    }

    if (empty($key)) {
      throw new CRM_Core_Exception('Svix API key not configured. Set it in CiviCRM settings or via SVIX_API_KEY environment variable.');
    }

    return $key;
  }

  /**
   * Create a destination (endpoint) in Svix for receiving webhooks.
   *
   * Note: Transformations must be set separately via setTransformation().
   * The Svix Ingest API does not support setting transformation when creating.
   *
   * @param string $sourceId
   *   The Svix source ID (e.g., src_stripe_xxx).
   * @param string $url
   *   The webhook URL to receive events.
   * @param string $description
   *   A description for this destination.
   *
   * @return array
   *   The created destination data from Svix API.
   *
   * @throws CRM_Core_Exception
   *   If the API call fails.
   */
  public function createDestination(
    string $sourceId,
    string $url,
    string $description,
  ): array {
    try {
      \Civi::log()->info('Creating Svix destination', [
        'source_id' => $sourceId,
        'url' => $url,
        'description' => $description,
      ]);

      $response = $this->request('POST', "/ingest/api/v1/source/{$sourceId}/endpoint", [
        'url' => $url,
        'description' => $description,
      ]);

      \Civi::log()->info('Svix destination created', [
        'source_id' => $sourceId,
        'destination_id' => $response['id'] ?? 'unknown',
        'url' => $url,
      ]);

      return $response;
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to create Svix destination', [
        'source_id' => $sourceId,
        'url' => $url,
        'error' => $e->getMessage(),
      ]);
      throw new CRM_Core_Exception('Failed to create Svix destination: ' . $e->getMessage());
    }
  }

  /**
   * Set the transformation (JavaScript filter) for a destination.
   *
   * Must be called after createDestination() as the Svix Ingest API
   * requires transformations to be set via a separate PATCH call.
   *
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID.
   * @param string $code
   *   The JavaScript transformation code.
   *
   * @throws CRM_Core_Exception
   *   If the API call fails.
   */
  public function setTransformation(string $sourceId, string $destinationId, string $code): void {
    try {
      \Civi::log()->info('Setting Svix transformation', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'code_length' => strlen($code),
      ]);

      $this->request('PATCH', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}/transformation", [
        'code' => $code,
        'enabled' => TRUE,
      ]);

      \Civi::log()->info('Svix transformation set successfully', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
      ]);
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to set Svix transformation', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      throw new CRM_Core_Exception('Failed to set Svix transformation: ' . $e->getMessage());
    }
  }

  /**
   * Get the signing secret for a destination.
   *
   * The signing secret is used to verify incoming webhooks.
   *
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID.
   *
   * @return string
   *   The webhook signing secret.
   *
   * @throws CRM_Core_Exception
   *   If the API call fails.
   */
  public function getDestinationSecret(string $sourceId, string $destinationId): string {
    try {
      $response = $this->request('GET', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}/secret");

      \Civi::log()->info('Retrieved Svix destination secret', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
      ]);

      return $response['key'];
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to get Svix destination secret', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      throw new CRM_Core_Exception('Failed to get Svix destination secret: ' . $e->getMessage());
    }
  }

  /**
   * List all destinations for a source.
   *
   * @param string $sourceId
   *   The Svix source ID.
   *
   * @return array
   *   Array of destination objects.
   *
   * @throws CRM_Core_Exception
   *   If the API call fails.
   */
  public function listDestinations(string $sourceId): array {
    try {
      $response = $this->request('GET', "/ingest/api/v1/source/{$sourceId}/endpoint");

      \Civi::log()->info('Listed Svix destinations', [
        'source_id' => $sourceId,
        'count' => count($response['data'] ?? []),
      ]);

      return $response['data'] ?? [];
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to list Svix destinations', [
        'source_id' => $sourceId,
        'error' => $e->getMessage(),
      ]);
      throw new CRM_Core_Exception('Failed to list Svix destinations: ' . $e->getMessage());
    }
  }

  /**
   * Disable a destination in Svix.
   *
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID to disable.
   *
   * @return bool
   *   TRUE if successfully disabled, FALSE otherwise.
   */
  public function disableDestination(string $sourceId, string $destinationId): bool {
    try {
      // First get the current destination to preserve its URL.
      $destination = $this->getDestination($sourceId, $destinationId);
      if ($destination === NULL) {
        \Civi::log()->warning('Cannot disable destination - not found', [
          'source_id' => $sourceId,
          'destination_id' => $destinationId,
        ]);
        return FALSE;
      }

      // PUT requires the full object with url.
      $this->request('PUT', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}", [
        'url' => $destination['url'],
        'disabled' => TRUE,
      ]);

      \Civi::log()->info('Svix destination disabled', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to disable Svix destination', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Delete a destination from Svix.
   *
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID to delete.
   *
   * @return bool
   *   TRUE if successfully deleted, FALSE otherwise.
   */
  public function deleteDestination(string $sourceId, string $destinationId): bool {
    try {
      $this->request('DELETE', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}");

      \Civi::log()->info('Svix destination deleted', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      \Civi::log()->error('Failed to delete Svix destination', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get a destination from Svix.
   *
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID.
   *
   * @return array|null
   *   The destination data, or NULL if not found.
   *
   * @throws CRM_Core_Exception
   *   If the API call fails with a non-404 error.
   */
  public function getDestination(string $sourceId, string $destinationId): ?array {
    try {
      return $this->request('GET', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}");
    }
    catch (\Exception $e) {
      // Only return NULL for 404 (not found) errors.
      // Re-throw all other errors (network issues, 500s, etc.).
      if (strpos($e->getMessage(), '(404)') !== FALSE) {
        \Civi::log()->info('Svix destination not found', [
          'source_id' => $sourceId,
          'destination_id' => $destinationId,
        ]);
        return NULL;
      }

      \Civi::log()->error('Failed to get Svix destination', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Verify an incoming webhook from Svix.
   *
   * @param string $payload
   *   The raw webhook payload.
   * @param array $headers
   *   The webhook headers (svix-id, svix-timestamp, svix-signature).
   * @param string $secret
   *   The webhook signing secret.
   *
   * @return bool
   *   TRUE if the webhook is valid.
   *
   * @throws \Exception
   *   If verification fails.
   */
  public static function verifyWebhook(string $payload, array $headers, string $secret): bool {
    $webhook = new Webhook($secret);
    $webhook->verify($payload, $headers);
    return TRUE;
  }

  /**
   * Build a JavaScript filter function for routing webhooks.
   *
   * Convenience method that creates a SimpleFieldFilter and builds it.
   * For more complex filters, use the FilterStrategyInterface directly.
   *
   * @param string $field
   *   The field path in the webhook payload to match against. Can be a
   *   simple field (e.g., 'account') or nested path ('links.organisation').
   * @param string $value
   *   The value to match (e.g., 'acct_xxx' for Stripe, 'OR000123' for
   *   GoCardless).
   *
   * @return string
   *   The JavaScript filter function.
   *
   * @see \Civi\Svixclient\Filter\SimpleFieldFilter
   * @see \Civi\Svixclient\Filter\FilterStrategyInterface
   */
  public static function buildRoutingFilter(string $field, string $value): string {
    $filter = new SimpleFieldFilter($field, $value);
    return $filter->build();
  }

  /**
   * Build a JavaScript filter from a FilterStrategyInterface implementation.
   *
   * Use this method when you need custom filter logic beyond simple field
   * matching.
   *
   * @param \Civi\Svixclient\Filter\FilterStrategyInterface $filter
   *   The filter strategy to build.
   *
   * @return string
   *   The JavaScript filter function.
   */
  public static function buildFilter(FilterStrategyInterface $filter): string {
    return $filter->build();
  }

  /**
   * Make an HTTP request to the Svix API.
   *
   * @param string $method
   *   HTTP method (GET, POST, DELETE, etc.).
   * @param string $path
   *   API path (e.g., /ingest/api/v1/source/{sourceId}/endpoint).
   * @param array|null $data
   *   Request data for POST/PUT requests.
   *
   * @return array
   *   Decoded JSON response.
   *
   * @throws CRM_Core_Exception
   *   If the request fails.
   */
  private function request(string $method, string $path, ?array $data = NULL): array {
    $ch = curl_init();
    if ($ch === FALSE) {
      throw new CRM_Core_Exception('Failed to initialize cURL session for Svix API request.');
    }

    $options = [
      CURLOPT_URL => $this->baseUrl . $path,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $this->apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
      ],
    ];

    switch ($method) {
      case 'POST':
        $options[CURLOPT_POST] = TRUE;
        if ($data !== NULL) {
          $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        break;

      case 'DELETE':
        $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        break;

      case 'PUT':
        $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if ($data !== NULL) {
          $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        break;

      case 'PATCH':
        $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        if ($data !== NULL) {
          $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        break;

      case 'GET':
      default:
        // GET is the default.
        break;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    // Note: curl_close() is deprecated in PHP 8.0+ as handles are auto-closed.
    if ($error) {
      throw new CRM_Core_Exception("Svix API request failed: {$error}");
    }

    if ($httpCode >= 400) {
      $errorMessage = "Svix API error ({$httpCode})";
      if ($response) {
        $decoded = json_decode($response, TRUE);
        if (isset($decoded['detail'])) {
          // Handle both string and array error details.
          $detail = is_array($decoded['detail'])
            ? json_encode($decoded['detail'])
            : $decoded['detail'];
          $errorMessage .= ": {$detail}";
        }
        else {
          $errorMessage .= ": {$response}";
        }
      }
      throw new CRM_Core_Exception($errorMessage);
    }

    if (empty($response)) {
      return [];
    }

    $decoded = json_decode($response, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new CRM_Core_Exception('Failed to decode Svix API response: ' . json_last_error_msg());
    }

    return $decoded;
  }

}
