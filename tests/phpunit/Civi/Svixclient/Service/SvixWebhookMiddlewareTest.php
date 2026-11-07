<?php

namespace Civi\Svixclient\Service;

use Civi\Api4\PaymentProcessor;
use Civi\Api4\SvixDestination;
use Civi\Svixclient\Enum\SvixIntegrationConfig;
use Svix\Webhook;

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
   * Test integrations are not throttled unless they ask to be.
   */
  public function testRegisterIntegrationDestinationIsUnthrottledByDefault(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->expects($this->once())
      ->method('createDestination')
      ->with('src_is', $this->anything(), $this->anything(), $this->isNull())
      ->willReturn(['id' => 'ep_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_new');
    $client->method('listDestinations')->willReturn([]);

    $this->middlewareWithClient($client)->registerIntegrationDestination('Impact Stack');
  }

  /**
   * Test a requested throttle rate is passed through to Svix.
   */
  public function testRegisterIntegrationDestinationPassesThrottleRate(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_impact_stack' => 'src_is',
    ]);

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->expects($this->once())
      ->method('createDestination')
      ->with('src_is', $this->anything(), $this->anything(), $this->identicalTo(500))
      ->willReturn(['id' => 'ep_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_new');
    $client->method('listDestinations')->willReturn([]);

    $this->assertSame(
      'ep_new',
      $this->middlewareWithClient($client)->registerIntegrationDestination('Impact Stack', NULL, 500)
    );
  }

  /**
   * Test an invalid throttle rate leaves the live destination alone.
   */
  public function testRegisterIntegrationDestinationRejectsInvalidThrottleRate(): void {
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

    $client = new class() extends \CRM_Svixclient_Client {

      /**
       * {@inheritdoc}
       */
      protected function request(string $method, string $path, ?array $data = NULL): array {
        throw new \LogicException("Svix must not be called: {$method} {$path}");
      }

    };

    $middleware = $this->middlewareWithClient($client);

    try {
      $middleware->registerIntegrationDestination('Impact Stack', NULL, 0);
      $this->fail('Expected an invalid throttle rate to be rejected.');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('throttle rate', $e->getMessage());
    }

    $stored = $middleware->getDestinationForType('Impact Stack');
    $this->assertNotNull($stored);
    $this->assertSame('ep_old', $stored['svix_destination_id']);
  }

  /**
   * Test a processor type without a platform source cannot register one.
   */
  public function testRegisterPlatformDestinationThrowsForProcessorWithoutPlatformSource(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('has no Svix platform source');

    $this->middleware->registerPlatformDestination('GoCardless', 1, 'OR000123');
  }

  /**
   * Test an empty routing value is rejected before anything is created.
   */
  public function testRegisterPlatformDestinationRejectsEmptyRoutingValue(): void {
    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->expects($this->never())->method('createDestination');

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('routing value is required');

    $this->middlewareWithClient($client)->registerPlatformDestination('Stripe Connect', 1, '');
  }

  /**
   * Test registering fails clearly when the platform source isn't set.
   */
  public function testRegisterPlatformDestinationThrowsWhenPlatformSourceNotConfigured(): void {
    $this->setSvixSettings(['svix_source_stripe_platform' => '']);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage("Set the 'svix_source_stripe_platform' setting");

    $this->middleware->registerPlatformDestination('Stripe Connect', 1, 'acct_123');
  }

  /**
   * Test the platform destination uses the platform source and filter.
   */
  public function testRegisterPlatformDestinationUsesPlatformSourceAndFilter(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_stripe_connect' => 'src_connect',
      'svix_source_stripe_platform' => 'src_platform',
    ]);
    $processorId = $this->createDummyProcessor('Stripe Express');

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([]);
    $client->expects($this->once())
      ->method('createDestination')
      ->with('src_platform', $this->stringContains('civicrm/stripe/webhook'), 'CiviCRM Stripe platform - acct_123')
      ->willReturn(['id' => 'ep_platform']);
    $client->expects($this->once())
      ->method('setTransformation')
      ->with('src_platform', 'ep_platform', $this->logicalAnd(
        $this->stringContains("webhook.payload.data.object.transfer_data.destination === 'acct_123'"),
        $this->stringContains("webhook.payload.data.object.metadata.connected_account_id === 'acct_123'")
      ));
    $client->method('getDestinationSecret')->willReturn('whsec_platform');

    $destinationId = $this->middlewareWithClient($client)
      ->registerPlatformDestination('Stripe Connect', $processorId, 'acct_123');

    $this->assertSame('ep_platform', $destinationId);
    $stored = SvixDestination::get(FALSE)
      ->addWhere('svix_destination_id', '=', 'ep_platform')
      ->execute()
      ->first();
    $this->assertIsArray($stored);
    $this->assertSame('src_platform', $stored['source_id']);
    $this->assertSame('Stripe Connect', $stored['type']);
    $this->assertSame($processorId, (int) $stored['payment_processor_id']);
    $this->assertSame('whsec_platform', $stored['signing_secret']);
  }

  /**
   * Test re-registering the platform destination leaves the main one alone.
   */
  public function testRegisterPlatformDestinationDoesNotDisableMainDestination(): void {
    $this->setSvixSettings([
      'svix_api_key' => 'sk_test_key',
      'svix_source_stripe_platform' => 'src_platform',
    ]);
    $processorId = $this->createDummyProcessor('Stripe Express');
    $url = \CRM_Utils_System::url('civicrm/stripe/webhook', '', TRUE);

    $client = $this->createMock(\CRM_Svixclient_Client::class);
    $client->method('listDestinations')->willReturn([
      [
        'id' => 'ep_main',
        'url' => $url,
        'description' => 'CiviCRM Stripe - acct_123',
        'disabled' => FALSE,
      ],
      [
        'id' => 'ep_platform_old',
        'url' => $url,
        'description' => 'CiviCRM Stripe platform - acct_123',
        'disabled' => FALSE,
      ],
    ]);
    $client->expects($this->once())
      ->method('disableDestination')
      ->with('src_platform', 'ep_platform_old');
    $client->method('createDestination')->willReturn(['id' => 'ep_platform_new']);
    $client->method('getDestinationSecret')->willReturn('whsec_platform_new');

    $this->middlewareWithClient($client)->registerPlatformDestination('Stripe Connect', $processorId, 'acct_123');
  }

  /**
   * Test a webhook signed by any of the processor's destinations verifies.
   *
   * A Stripe Express site has a main and a platform destination, each with
   * its own secret. Svix signs each delivery with the secret of the
   * destination that delivered it.
   */
  public function testVerifyAcceptsAnyOfTheProcessorsSecrets(): void {
    $processorId = $this->createDummyProcessor('Express site');
    $mainSecret = 'whsec_' . base64_encode(random_bytes(24));
    $platformSecret = 'whsec_' . base64_encode(random_bytes(24));
    $this->createDestinationRecord($processorId, 'src_connect', 'ep_main', $mainSecret);
    $this->createDestinationRecord($processorId, 'src_platform', 'ep_platform', $platformSecret);

    $payload = '{"type":"payment_intent.succeeded"}';
    foreach ([$mainSecret, $platformSecret] as $secret) {
      $result = $this->middleware->verify($payload, 'Dummy', $this->signedHeaders($payload, $secret));
      $this->assertTrue($result['valid'], 'Signed with one of the destination secrets');
      $this->assertNull($result['error']);
    }
  }

  /**
   * Test a webhook signed with an unknown secret is still rejected.
   */
  public function testVerifyRejectsSignatureFromUnknownSecret(): void {
    $processorId = $this->createDummyProcessor('Express site');
    $this->createDestinationRecord($processorId, 'src_connect', 'ep_main', 'whsec_' . base64_encode(random_bytes(24)));
    $this->createDestinationRecord($processorId, 'src_platform', 'ep_platform', 'whsec_' . base64_encode(random_bytes(24)));

    $payload = '{"type":"payment_intent.succeeded"}';
    $otherSecret = 'whsec_' . base64_encode(random_bytes(24));
    $result = $this->middleware->verify($payload, 'Dummy', $this->signedHeaders($payload, $otherSecret));

    $this->assertFalse($result['valid']);
    $this->assertNotEmpty($result['error']);
  }

  /**
   * Test deleting a processor's destinations removes all of them.
   */
  public function testDeleteDestinationRemovesEveryDestinationOfTheProcessor(): void {
    $processorId = $this->createDummyProcessor('Express site');
    $otherProcessorId = $this->createDummyProcessor('Other site');
    $this->createDestinationRecord($processorId, 'src_connect', 'ep_main', 'whsec_main');
    $this->createDestinationRecord($processorId, 'src_platform', 'ep_platform', 'whsec_platform');
    $this->createDestinationRecord($otherProcessorId, 'src_connect', 'ep_other', 'whsec_other');

    $this->middleware->deleteDestination($processorId);

    $remaining = SvixDestination::get(FALSE)
      ->addWhere('payment_processor_id', 'IN', [$processorId, $otherProcessorId])
      ->execute()
      ->column('svix_destination_id');
    $this->assertSame(['ep_other'], $remaining);
  }

  /**
   * Creates a Dummy payment processor.
   *
   * @param string $name
   *   The processor name.
   *
   * @return int
   *   The processor ID.
   */
  private function createDummyProcessor(string $name): int {
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', $name)
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();
    $this->assertIsArray($processor);

    return (int) $processor['id'];
  }

  /**
   * Stores a destination record for a payment processor.
   *
   * @param int $processorId
   *   The payment processor ID.
   * @param string $sourceId
   *   The Svix source ID.
   * @param string $destinationId
   *   The Svix destination ID.
   * @param string $secret
   *   The signing secret.
   */
  private function createDestinationRecord(int $processorId, string $sourceId, string $destinationId, string $secret): void {
    SvixDestination::create(FALSE)
      ->addValue('source_id', $sourceId)
      ->addValue('svix_destination_id', $destinationId)
      ->addValue('signing_secret', $secret)
      ->addValue('type', 'Dummy')
      ->addValue('payment_processor_id', $processorId)
      ->execute();
  }

  /**
   * Builds Svix headers for a payload signed with the given secret.
   *
   * @param string $payload
   *   The webhook payload.
   * @param string $secret
   *   The signing secret.
   *
   * @return array<string,string>
   *   The svix-id, svix-timestamp and svix-signature headers.
   */
  private function signedHeaders(string $payload, string $secret): array {
    $messageId = 'msg_' . bin2hex(random_bytes(8));
    $timestamp = time();

    return [
      'svix-id' => $messageId,
      'svix-timestamp' => (string) $timestamp,
      'svix-signature' => (new Webhook($secret))->sign($messageId, $timestamp, $payload),
    ];
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
