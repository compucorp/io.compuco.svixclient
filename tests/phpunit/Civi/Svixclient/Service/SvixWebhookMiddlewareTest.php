<?php

namespace Civi\Svixclient\Service;

use Civi\Api4\PaymentProcessor;
use Civi\Api4\SvixDestination;
use Civi\Svixclient\Enum\SvixIntegrationConfig;

/**
 * Tests for the SvixWebhookMiddleware class.
 *
 * @group headless
 */
class SvixWebhookMiddlewareTest extends \BaseHeadlessTest {

  /**
   * The middleware instance under test.
   *
   * @var \Civi\Svixclient\Service\SvixWebhookMiddleware
   */
  private SvixWebhookMiddleware $middleware;

  /**
   * The mandatory settings that were configured before this test ran.
   *
   * @var array|null
   */
  private ?array $originalMandatorySettings = NULL;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->middleware = new SvixWebhookMiddleware();
    $this->originalMandatorySettings = $GLOBALS['civicrm_setting'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->setMandatorySettings($this->originalMandatorySettings);
    parent::tearDown();
  }

  /**
   * Replaces the mandatory settings and reloads them into the settings bag.
   *
   * @param array|null $settings
   *   The full mandatory settings array, or NULL to unset it.
   */
  private function setMandatorySettings(?array $settings): void {
    if ($settings === NULL) {
      unset($GLOBALS['civicrm_setting']);
    }
    else {
      $GLOBALS['civicrm_setting'] = $settings;
    }

    \Civi::service('settings_manager')->useMandatory();
  }

  /**
   * Configures Svix settings as mandatory settings.
   *
   * @param array<string,string> $values
   *   The Svix setting values, keyed by setting name.
   */
  private function setSvixSettings(array $values): void {
    $settings = $this->originalMandatorySettings ?? [];
    foreach ($values as $name => $value) {
      $settings['Svix'][$name] = $value;
    }

    $this->setMandatorySettings($settings);
  }

  /**
   * Test that middleware can be retrieved from the container.
   */
  public function testServiceIsRegisteredInContainer(): void {
    $service = \Civi::service('svix.webhook_middleware');
    $this->assertInstanceOf(SvixWebhookMiddleware::class, $service);
  }

  /**
   * Test isSvixRequest returns false when no Svix headers are present.
   */
  public function testIsSvixRequestReturnsFalseWithoutHeaders(): void {
    // Clear any existing headers.
    unset($_SERVER['HTTP_SVIX_ID']);
    unset($_SERVER['HTTP_SVIX_TIMESTAMP']);
    unset($_SERVER['HTTP_SVIX_SIGNATURE']);

    $this->assertFalse($this->middleware->isSvixRequest());
  }

  /**
   * Test isSvixRequest returns true when Svix signature header is present.
   */
  public function testIsSvixRequestReturnsTrueWithHeaders(): void {
    $_SERVER['HTTP_SVIX_ID'] = 'msg_test123';
    $_SERVER['HTTP_SVIX_TIMESTAMP'] = '1234567890';
    $_SERVER['HTTP_SVIX_SIGNATURE'] = 'v1,test_signature';

    try {
      $this->assertTrue($this->middleware->isSvixRequest());
    }
    finally {
      // Clean up.
      unset($_SERVER['HTTP_SVIX_ID']);
      unset($_SERVER['HTTP_SVIX_TIMESTAMP']);
      unset($_SERVER['HTTP_SVIX_SIGNATURE']);
    }
  }

  /**
   * Test getSvixHeaders extracts headers from $_SERVER.
   */
  public function testGetSvixHeadersExtractsFromServer(): void {
    $_SERVER['HTTP_SVIX_ID'] = 'msg_abc123';
    $_SERVER['HTTP_SVIX_TIMESTAMP'] = '1609459200';
    $_SERVER['HTTP_SVIX_SIGNATURE'] = 'v1,signature_here';

    try {
      $headers = $this->middleware->getSvixHeaders();

      $this->assertArrayHasKey('svix-id', $headers);
      $this->assertArrayHasKey('svix-timestamp', $headers);
      $this->assertArrayHasKey('svix-signature', $headers);
      $this->assertEquals('msg_abc123', $headers['svix-id']);
      $this->assertEquals('1609459200', $headers['svix-timestamp']);
      $this->assertEquals('v1,signature_here', $headers['svix-signature']);
    }
    finally {
      // Clean up.
      unset($_SERVER['HTTP_SVIX_ID']);
      unset($_SERVER['HTTP_SVIX_TIMESTAMP']);
      unset($_SERVER['HTTP_SVIX_SIGNATURE']);
    }
  }

  /**
   * Test getSvixHeaders returns empty strings when headers are missing.
   */
  public function testGetSvixHeadersReturnsEmptyStringsWhenMissing(): void {
    // Clear any existing headers.
    unset($_SERVER['HTTP_SVIX_ID']);
    unset($_SERVER['HTTP_SVIX_TIMESTAMP']);
    unset($_SERVER['HTTP_SVIX_SIGNATURE']);

    $headers = $this->middleware->getSvixHeaders();

    $this->assertArrayHasKey('svix-id', $headers);
    $this->assertArrayHasKey('svix-timestamp', $headers);
    $this->assertArrayHasKey('svix-signature', $headers);
    $this->assertEquals('', $headers['svix-id']);
    $this->assertEquals('', $headers['svix-timestamp']);
    $this->assertEquals('', $headers['svix-signature']);
  }

  /**
   * Test verify returns error when no secret is configured.
   */
  public function testVerifyReturnsErrorWithNoSecret(): void {
    $payload = '{"test": "data"}';
    $headers = [
      'svix-id' => 'msg_test',
      'svix-timestamp' => '1234567890',
      'svix-signature' => 'v1,test_sig',
    ];

    // Use a non-existent processor type.
    $result = $this->middleware->verify($payload, 'NonExistentProcessor', $headers);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('No Svix destination configured', $result['error']);
  }

  /**
   * Test getSecretForProcessorType returns null for unknown processor.
   */
  public function testGetSecretForProcessorTypeReturnsNullForUnknown(): void {
    $secret = $this->middleware->getSecretForProcessorType('UnknownProcessor');
    $this->assertNull($secret);
  }

  /**
   * Test isEnabledForProcessorType returns false for unknown processor.
   */
  public function testIsEnabledForProcessorTypeReturnsFalseForUnknown(): void {
    $enabled = $this->middleware->isEnabledForProcessorType('UnknownProcessor');
    $this->assertFalse($enabled);
  }

  /**
   * Test isConfigured returns false when API key is not set.
   */
  public function testIsConfiguredReturnsFalseWithoutApiKey(): void {
    // Clear any existing API key setting.
    \Civi::settings()->revert('svix_api_key');

    // Also clear environment variable for this test.
    $originalEnv = getenv('SVIX_API_KEY');
    putenv('SVIX_API_KEY');

    try {
      // Check if API key is still available (e.g., from civicrm.settings.php).
      $keyFromSettings = \Civi::settings()->get('svix_api_key');
      if (!empty($keyFromSettings)) {
        $this->markTestSkipped(
          'Cannot test missing API key when configured in civicrm.settings.php'
        );
      }

      $this->assertFalse($this->middleware->isConfigured());
    }
    finally {
      // Restore environment variable if it was set.
      if ($originalEnv !== FALSE) {
        putenv("SVIX_API_KEY={$originalEnv}");
      }
    }
  }

  /**
   * Test getConfigurationStatus returns correct message when not configured.
   */
  public function testGetConfigurationStatusWhenNotConfigured(): void {
    // Clear any existing API key setting.
    \Civi::settings()->revert('svix_api_key');

    // Also clear environment variable for this test.
    $originalEnv = getenv('SVIX_API_KEY');
    putenv('SVIX_API_KEY');

    try {
      // Check if API key is still available (e.g., from civicrm.settings.php).
      $keyFromSettings = \Civi::settings()->get('svix_api_key');
      if (!empty($keyFromSettings)) {
        $this->markTestSkipped(
          'Cannot test missing API key when configured in civicrm.settings.php'
        );
      }

      $status = $this->middleware->getConfigurationStatus();

      $this->assertFalse($status['configured']);
      $this->assertStringContainsString('API key is not configured', $status['message']);
    }
    finally {
      // Restore environment variable if it was set.
      if ($originalEnv !== FALSE) {
        putenv("SVIX_API_KEY={$originalEnv}");
      }
    }
  }

  /**
   * Test registerDestination throws exception for unsupported processor type.
   */
  public function testRegisterDestinationThrowsForUnsupportedProcessor(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Unsupported processor type for Svix');

    $this->middleware->registerDestination('UnsupportedProcessor', 1, 'test_value');
  }

  /**
   * Test registerDestination throws exception when source ID not configured.
   */
  public function testRegisterDestinationThrowsWhenSourceIdNotConfigured(): void {
    // Clear the source ID setting.
    \Civi::settings()->revert('svix_source_stripe_connect');

    // Check if source ID is still available (e.g., from civicrm.settings.php).
    $sourceId = \Civi::settings()->get('svix_source_stripe_connect');
    if (!empty($sourceId)) {
      $this->markTestSkipped(
        'Cannot test missing source ID when configured in civicrm.settings.php'
      );
    }

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Svix source ID not configured');

    $this->middleware->registerDestination('Stripe Connect', 1, 'acct_test123');
  }

  /**
   * Test registerIntegrationDestination throws for an unknown integration.
   */
  public function testRegisterIntegrationDestinationThrowsForUnknownType(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Unsupported integration type for Svix');

    $this->middleware->registerIntegrationDestination('Not An Integration');
  }

  /**
   * Test registerIntegrationDestination throws when the source is unset.
   */
  public function testRegisterIntegrationDestinationThrowsWhenSourceIdNotConfigured(): void {
    $this->setSvixSettings(['svix_source_impact_stack' => '']);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Svix source ID not configured');

    $this->middleware->registerIntegrationDestination('Impact Stack');
  }

  /**
   * Test type look-ups return nothing when no destination is registered.
   */
  public function testTypeLookupsReturnNothingWhenNotRegistered(): void {
    $this->assertNull($this->middleware->getDestinationForType('Impact Stack'));
    $this->assertNull($this->middleware->getSecretForType('Impact Stack'));
    $this->assertFalse($this->middleware->isEnabledForType('Impact Stack'));
  }

  /**
   * Test type look-ups find a destination that has no payment processor.
   */
  public function testTypeLookupsFindDestinationWithoutPaymentProcessor(): void {
    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_impact_stack_lookup')
      ->addValue('svix_destination_id', 'dest_impact_stack_lookup')
      ->addValue('type', 'Impact Stack')
      ->addValue('signing_secret', 'whsec_impact_stack')
      ->execute();

    $destination = $this->middleware->getDestinationForType('Impact Stack');

    $this->assertNotNull($destination);
    $this->assertEquals('dest_impact_stack_lookup', $destination['svix_destination_id']);
    $this->assertEquals('whsec_impact_stack', $this->middleware->getSecretForType('Impact Stack'));
    $this->assertTrue($this->middleware->isEnabledForType('Impact Stack'));
  }

  /**
   * Test verifyForType reports a missing destination rather than throwing.
   */
  public function testVerifyForTypeReturnsErrorWithNoSecret(): void {
    $result = $this->middleware->verifyForType('{"test": "data"}', 'Impact Stack', [
      'svix-id' => 'msg_test',
      'svix-timestamp' => '1234567890',
      'svix-signature' => 'v1,test_sig',
    ]);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('No Svix destination configured for type', $result['error']);
  }

  /**
   * Test getIntegrationConfigurationStatus rejects an unknown integration.
   */
  public function testGetIntegrationConfigurationStatusForUnknownType(): void {
    $status = $this->middleware->getIntegrationConfigurationStatus('Not An Integration');

    $this->assertFalse($status['enabled']);
    $this->assertStringContainsString('Unsupported integration type', $status['message']);
  }

  /**
   * Test getIntegrationConfigurationStatus reports a missing source ID.
   */
  public function testGetIntegrationConfigurationStatusWithoutSourceId(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => '',
    ]);

    $status = $this->middleware->getIntegrationConfigurationStatus('Impact Stack');

    $this->assertFalse($status['enabled']);
    $this->assertStringContainsString('svix_source_impact_stack', $status['message']);
  }

  /**
   * Test getIntegrationConfigurationStatus passes when fully configured.
   */
  public function testGetIntegrationConfigurationStatusWhenConfigured(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_impact_stack_123',
    ]);

    $status = $this->middleware->getIntegrationConfigurationStatus('Impact Stack');

    $this->assertTrue($status['enabled']);
  }

  /**
   * Test getIngestUrlForType returns NULL when no source is configured.
   */
  public function testGetIngestUrlForTypeReturnsNullWithoutSourceId(): void {
    $this->setSvixSettings(['svix_source_impact_stack' => '']);

    $this->assertNull($this->middleware->getIngestUrlForType('Impact Stack'));
  }

  /**
   * Test a successful integration registration stores the destination.
   */
  public function testRegisterIntegrationDestinationStoresDestinationWithoutFilter(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('createDestination')->willReturn(['id' => 'ep_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_new');
    $client->method('listDestinations')->willReturn([]);
    $client->expects($this->never())->method('setTransformation');

    $middleware = $this->middlewareWithClient($client);

    $this->assertSame('ep_new', $middleware->registerIntegrationDestination('Impact Stack'));

    $stored = $middleware->getDestinationForType('Impact Stack');
    $this->assertNotNull($stored);
    $this->assertSame('ep_new', $stored['svix_destination_id']);
    $this->assertSame('whsec_new', $stored['signing_secret']);
    $this->assertNull($stored['payment_processor_id']);
  }

  /**
   * Test re-registering replaces the previous destination record.
   */
  public function testRegisterIntegrationDestinationReplacesPreviousRecord(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_is')
      ->addValue('svix_destination_id', 'ep_old')
      ->addValue('type', 'Impact Stack')
      ->addValue('signing_secret', 'whsec_old')
      ->execute();

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('createDestination')->willReturn(['id' => 'ep_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_new');
    $client->method('listDestinations')->willReturn([]);

    $middleware = $this->middlewareWithClient($client);
    $middleware->registerIntegrationDestination('Impact Stack');

    $remaining = SvixDestination::get(FALSE)
      ->addWhere('type', '=', 'Impact Stack')
      ->execute();

    $this->assertCount(1, $remaining);
    $this->assertSame('ep_new', $remaining->first()['svix_destination_id']);
  }

  /**
   * Test the replacement destination is never disabled by its own clean-up.
   */
  public function testRegisterIntegrationDestinationDoesNotDisableItsReplacement(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    $webhookUrl = SvixIntegrationConfig::ImpactStack->getWebhookUrl();
    $description = 'CiviCRM Impact Stack - ' . $webhookUrl;

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('createDestination')->willReturn(['id' => 'ep_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_new');
    $client->method('listDestinations')->willReturn([
      ['id' => 'ep_stale', 'url' => $webhookUrl, 'description' => $description, 'disabled' => FALSE],
      ['id' => 'ep_new', 'url' => $webhookUrl, 'description' => $description, 'disabled' => FALSE],
    ]);

    // Only the orphan is stood down; the replacement stays enabled.
    $client->expects($this->once())
      ->method('disableDestination')
      ->with('src_is', 'ep_stale');

    $this->middlewareWithClient($client)->registerIntegrationDestination('Impact Stack');
  }

  /**
   * Test a failed creation leaves the live destination alone.
   */
  public function testFailedIntegrationRegistrationLeavesPreviousDestinationIntact(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_is')
      ->addValue('svix_destination_id', 'ep_old')
      ->addValue('type', 'Impact Stack')
      ->addValue('signing_secret', 'whsec_old')
      ->execute();

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('createDestination')
      ->willThrowException(new \CRM_Core_Exception('Svix API error (500)'));
    $client->expects($this->never())->method('disableDestination');
    $client->expects($this->never())->method('deleteDestination');

    $middleware = $this->middlewareWithClient($client);

    try {
      $middleware->registerIntegrationDestination('Impact Stack');
      $this->fail('Expected the registration to propagate the client failure.');
    }
    catch (\CRM_Core_Exception $e) {
      // Expected.
    }

    $stored = $middleware->getDestinationForType('Impact Stack');
    $this->assertNotNull($stored, 'The previous destination record must survive.');
    $this->assertSame('ep_old', $stored['svix_destination_id']);
  }

  /**
   * Builds a middleware whose Svix client is the given double.
   *
   * @param \CRM_Svixclient_Client $client
   *   The client double to use.
   *
   * @return \Civi\Svixclient\Service\SvixWebhookMiddleware
   *   The middleware under test.
   */
  private function middlewareWithClient(\CRM_Svixclient_Client $client): SvixWebhookMiddleware {
    return new class($client) extends SvixWebhookMiddleware {

      /**
       * @var \CRM_Svixclient_Client
       */
      private \CRM_Svixclient_Client $testClient;

      public function __construct(\CRM_Svixclient_Client $client) {
        $this->testClient = $client;
      }

      /**
       * @return \CRM_Svixclient_Client
       *   The svix client.
       */
      protected function createClient(): \CRM_Svixclient_Client {
        return $this->testClient;
      }

    };
  }

  /**
   * Test deleteDestinationForType is a no-op when nothing is registered.
   */
  public function testDeleteDestinationForTypeHandlesMissingDestination(): void {
    $this->middleware->deleteDestinationForType('Impact Stack');

    $this->assertFalse($this->middleware->isEnabledForType('Impact Stack'));
  }

  /**
   * Test deleteDestination handles gracefully when no destination exists.
   */
  public function testDeleteDestinationHandlesNoDestinationGracefully(): void {
    // This should not throw an exception even if no destination exists.
    // Using a payment processor ID that definitely doesn't exist.
    $this->middleware->deleteDestination(999999);

    // If we got here without exception, the test passes.
    $this->assertTrue(TRUE);
  }

  /**
   * Test disabling only targets destinations with the same description.
   *
   * A site's Live and Test accounts share one webhook URL. Re-registering
   * one account must not disable the other account's destination.
   */
  public function testDisableExistingDestinationsLeavesOtherRoutingValuesUntouched(): void {
    $webhookUrl = 'https://example.org/civicrm/gocardless/webhook';
    $destinations = [
      [
        'id' => 'ep_live',
        'url' => $webhookUrl,
        'description' => 'CiviCRM GoCardless - OR_LIVE',
        'disabled' => FALSE,
      ],
      [
        'id' => 'ep_test',
        'url' => $webhookUrl,
        'description' => 'CiviCRM GoCardless - OR_TEST',
        'disabled' => FALSE,
      ],
    ];

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn($destinations);
    $client->expects($this->once())
      ->method('disableDestination')
      ->with('src_123', 'ep_test');

    $this->invokeDisableExistingDestinations(
      $client,
      'src_123',
      $webhookUrl,
      'CiviCRM GoCardless - OR_TEST'
    );
  }

  /**
   * Test disabling matches URLs with trailing slash or query separator.
   */
  public function testDisableExistingDestinationsNormalizesUrls(): void {
    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([
      [
        'id' => 'ep_old',
        'url' => 'https://example.org/civicrm/gocardless/webhook/?',
        'description' => 'CiviCRM GoCardless - OR_TEST',
        'disabled' => FALSE,
      ],
    ]);
    $client->expects($this->once())
      ->method('disableDestination')
      ->with('src_123', 'ep_old');

    $this->invokeDisableExistingDestinations(
      $client,
      'src_123',
      'https://example.org/civicrm/gocardless/webhook',
      'CiviCRM GoCardless - OR_TEST'
    );
  }

  /**
   * Test already-disabled destinations are not disabled again.
   */
  public function testDisableExistingDestinationsSkipsAlreadyDisabled(): void {
    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([
      [
        'id' => 'ep_disabled',
        'url' => 'https://example.org/civicrm/gocardless/webhook',
        'description' => 'CiviCRM GoCardless - OR_TEST',
        'disabled' => TRUE,
      ],
    ]);
    $client->expects($this->never())->method('disableDestination');

    $this->invokeDisableExistingDestinations(
      $client,
      'src_123',
      'https://example.org/civicrm/gocardless/webhook',
      'CiviCRM GoCardless - OR_TEST'
    );
  }

  /**
   * Test destinations for a different URL are not disabled.
   */
  public function testDisableExistingDestinationsSkipsOtherUrls(): void {
    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([
      [
        'id' => 'ep_other_site',
        'url' => 'https://other-site.org/civicrm/gocardless/webhook',
        'description' => 'CiviCRM GoCardless - OR_TEST',
        'disabled' => FALSE,
      ],
    ]);
    $client->expects($this->never())->method('disableDestination');

    $this->invokeDisableExistingDestinations(
      $client,
      'src_123',
      'https://example.org/civicrm/gocardless/webhook',
      'CiviCRM GoCardless - OR_TEST'
    );
  }

  /**
   * Test a sibling processor's destination with an identical description.
   *
   * On dev environments the same sandbox organisation can be connected as
   * both Live and Test, so URL and description are identical for both
   * destinations. The local SvixDestination record must break the tie.
   */
  public function testDisableExistingDestinationsSkipsSiblingProcessorWithSameDescription(): void {
    $webhookUrl = 'https://example.org/civicrm/gocardless/webhook';
    $description = 'CiviCRM GoCardless - OR_SHARED';

    $liveProcessor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'GoCardless Live')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();
    $this->assertIsArray($liveProcessor);

    $testProcessor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'GoCardless Test')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', TRUE)
      ->execute()
      ->first();
    $this->assertIsArray($testProcessor);

    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_123')
      ->addValue('svix_destination_id', 'ep_live')
      ->addValue('type', 'GoCardless')
      ->addValue('payment_processor_id', $liveProcessor['id'])
      ->execute();
    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_123')
      ->addValue('svix_destination_id', 'ep_test')
      ->addValue('type', 'GoCardless')
      ->addValue('payment_processor_id', $testProcessor['id'])
      ->execute();

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([
      [
        'id' => 'ep_live',
        'url' => $webhookUrl,
        'description' => $description,
        'disabled' => FALSE,
      ],
      [
        'id' => 'ep_test',
        'url' => $webhookUrl,
        'description' => $description,
        'disabled' => FALSE,
      ],
    ]);

    // Re-registering the Test processor: only its own stale destination
    // may be disabled — never the Live processor's.
    $client->expects($this->once())
      ->method('disableDestination')
      ->with('src_123', 'ep_test');

    $this->invokeDisableExistingDestinations(
      $client,
      'src_123',
      $webhookUrl,
      $description,
      (int) $testProcessor['id']
    );
  }

  /**
   * Invoke the private disableExistingDestinations method.
   *
   * @param \CRM_Svixclient_Client $client
   *   The (mocked) Svix client.
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $webhookUrl
   *   The webhook URL to match.
   * @param string $description
   *   The destination description to match.
   * @param int $paymentProcessorId
   *   The payment processor being (re-)registered.
   */
  private function invokeDisableExistingDestinations(
    \CRM_Svixclient_Client $client,
    string $sourceId,
    string $webhookUrl,
    string $description,
    int $paymentProcessorId = 99999,
  ): void {
    $method = new \ReflectionMethod($this->middleware, 'disableExistingDestinations');
    $method->setAccessible(TRUE);
    $method->invoke($this->middleware, $client, $sourceId, $webhookUrl, $description, $paymentProcessorId);
  }

}
