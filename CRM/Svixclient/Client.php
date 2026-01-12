<?php

declare(strict_types=1);

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
   */
  private string $baseUrl = 'https://api.svix.com';

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
    $this->apiKey = $this->loadApiKey();
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
   * @param string $sourceId
   *   The Svix source ID (e.g., src_stripe_xxx).
   * @param string $url
   *   The webhook URL to receive events.
   * @param string $description
   *   A description for this destination.
   * @param string $filterScript
   *   JavaScript filter/transformation script.
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
    string $filterScript,
  ): array {
    try {
      $response = $this->request('POST', "/ingest/api/v1/source/{$sourceId}/endpoint", [
        'url' => $url,
        'description' => $description,
        'transformation' => $filterScript,
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
   */
  public function getDestination(string $sourceId, string $destinationId): ?array {
    try {
      return $this->request('GET', "/ingest/api/v1/source/{$sourceId}/endpoint/{$destinationId}");
    }
    catch (\Exception $e) {
      \Civi::log()->warning('Failed to get Svix destination', [
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
        'error' => $e->getMessage(),
      ]);
      return NULL;
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
        if (isset($decoded['message'])) {
          $errorMessage .= ": {$decoded['message']}";
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
