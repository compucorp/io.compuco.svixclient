<?php

declare(strict_types=1);

namespace Civi\Api4\Action\Svix;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Verify an incoming webhook from Svix.
 *
 * This action verifies the signature of an incoming webhook
 * to ensure it came from Svix and hasn't been tampered with.
 *
 * @method string getPayload()
 * @method $this setPayload(string $payload)
 * @method array getHeaders()
 * @method $this setHeaders(array $headers)
 * @method string getSecret()
 * @method $this setSecret(string $secret)
 */
class VerifyWebhook extends AbstractAction {

  /**
   * The raw webhook payload.
   *
   * @var string
   * @required
   */
  protected string $payload;

  /**
   * The webhook headers.
   *
   * Should include: svix-id, svix-timestamp, svix-signature.
   *
   * @var array
   * @required
   */
  protected array $headers;

  /**
   * The webhook signing secret.
   *
   * @var string
   * @required
   */
  protected string $secret;

  /**
   * {@inheritdoc}
   */
  public function _run(Result $result): void {
    try {
      $isValid = \CRM_Svixclient_Client::verifyWebhook(
        $this->payload,
        $this->headers,
        $this->secret
      );

      $result[] = [
        'valid' => $isValid,
        'message' => 'Webhook signature is valid',
      ];
    }
    catch (\Exception $e) {
      $result[] = [
        'valid' => FALSE,
        'message' => 'Webhook verification failed: ' . $e->getMessage(),
        'error' => $e->getMessage(),
      ];
    }
  }

}
