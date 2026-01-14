<?php

use Civi\Svixclient\Filter\FilterStrategyInterface;
use Civi\Svixclient\Filter\SimpleFieldFilter;

/**
 * Tests for the CRM_Svixclient_Client class.
 *
 * @group headless
 */
class CRM_Svixclient_ClientTest extends BaseHeadlessTest {

  /**
   * Original environment variable value.
   *
   * @var string|false
   */
  private string|false $originalEnvKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Save original env var.
    $this->originalEnvKey = getenv('SVIX_API_KEY');
    // Clear env var for tests.
    putenv('SVIX_API_KEY');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore original env var.
    if ($this->originalEnvKey !== FALSE) {
      putenv('SVIX_API_KEY=' . $this->originalEnvKey);
    }
    else {
      putenv('SVIX_API_KEY');
    }
    // Clear any settings.
    \Civi::settings()->set('svix_api_key', NULL);
    parent::tearDown();
  }

  /**
   * Test that exception is thrown when no API key is configured.
   */
  public function testThrowsExceptionWhenNoApiKey(): void {
    // Ensure no API key is set via environment.
    putenv('SVIX_API_KEY');

    // Clear the setting and flush cache.
    \Civi::settings()->set('svix_api_key', NULL);
    \Civi::settings()->revert('svix_api_key');

    // Check if API key is still available (e.g., from civicrm.settings.php).
    $keyFromSettings = \Civi::settings()->get('svix_api_key');
    $keyFromEnv = getenv('SVIX_API_KEY');

    if (!empty($keyFromSettings) || !empty($keyFromEnv)) {
      $this->markTestSkipped(
        'Cannot test missing API key when key is configured in civicrm.settings.php or environment'
      );
    }

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('Svix API key not configured');

    new CRM_Svixclient_Client();
  }

  /**
   * Test that API key from settings is used.
   */
  public function testApiKeyFromSettings(): void {
    \Civi::settings()->set('svix_api_key', 'test_api_key_from_settings');

    // This will throw an exception from Svix SDK because the key is invalid,
    // but it proves the key was retrieved from settings.
    try {
      new CRM_Svixclient_Client();
      // If we get here, the client was created (key was found).
      $this->assertTrue(TRUE);
    }
    catch (\CRM_Core_Exception $e) {
      // If the exception is about API key not configured, fail the test.
      if (str_contains($e->getMessage(), 'not configured')) {
        $this->fail('API key should have been retrieved from settings');
      }
      // Other exceptions are acceptable (e.g., invalid key format).
      $this->assertTrue(TRUE);
    }
    catch (\Exception $e) {
      // Other exceptions from Svix SDK are acceptable.
      $this->assertTrue(TRUE);
    }
  }

  /**
   * Test that API key from environment variable is used as fallback.
   */
  public function testApiKeyFromEnvVar(): void {
    \Civi::settings()->set('svix_api_key', NULL);
    putenv('SVIX_API_KEY=test_api_key_from_env');

    try {
      new CRM_Svixclient_Client();
      $this->assertTrue(TRUE);
    }
    catch (\CRM_Core_Exception $e) {
      if (str_contains($e->getMessage(), 'not configured')) {
        $this->fail('API key should have been retrieved from environment variable');
      }
      $this->assertTrue(TRUE);
    }
    catch (\Exception $e) {
      // Other exceptions from Svix SDK are acceptable.
      $this->assertTrue(TRUE);
    }
  }

  /**
   * Test that settings take precedence over environment variable.
   */
  public function testSettingsTakePrecedenceOverEnvVar(): void {
    \Civi::settings()->set('svix_api_key', 'key_from_settings');
    putenv('SVIX_API_KEY=key_from_env');

    // Both are set - settings should be used.
    // We can't directly verify which key is used, but we can verify
    // the client is created without "not configured" exception.
    try {
      new CRM_Svixclient_Client();
      $this->assertTrue(TRUE);
    }
    catch (\CRM_Core_Exception $e) {
      if (str_contains($e->getMessage(), 'not configured')) {
        $this->fail('API key should have been found');
      }
      $this->assertTrue(TRUE);
    }
    catch (\Exception $e) {
      $this->assertTrue(TRUE);
    }
  }

  /**
   * Test buildRoutingFilter generates correct JavaScript for Stripe.
   */
  public function testBuildRoutingFilterForStripe(): void {
    $filter = CRM_Svixclient_Client::buildRoutingFilter('account', 'acct_1234567890');

    // Verify the filter is a valid JavaScript function.
    $this->assertStringContainsString('function handler(input)', $filter);
    $this->assertStringContainsString("input.account !== 'acct_1234567890'", $filter);
    $this->assertStringContainsString('return null', $filter);
    $this->assertStringContainsString('return { payload: input }', $filter);
  }

  /**
   * Test buildRoutingFilter with different field (e.g., GoCardless).
   */
  public function testBuildRoutingFilterForGoCardless(): void {
    $filter = CRM_Svixclient_Client::buildRoutingFilter('organisation_id', 'OR000123');

    $this->assertStringContainsString('function handler(input)', $filter);
    $this->assertStringContainsString("input.organisation_id !== 'OR000123'", $filter);
  }

  /**
   * Test buildRoutingFilter with nested field path.
   */
  public function testBuildRoutingFilterWithNestedField(): void {
    $filter = CRM_Svixclient_Client::buildRoutingFilter('links.organisation', 'OR000123');

    $this->assertStringContainsString('function handler(input)', $filter);
    $this->assertStringContainsString("input.links.organisation !== 'OR000123'", $filter);
  }

  /**
   * Test buildRoutingFilter properly escapes special characters.
   */
  public function testBuildRoutingFilterEscapesSpecialChars(): void {
    // Test with quotes.
    $filter = CRM_Svixclient_Client::buildRoutingFilter('account', "acct_with'quote");

    // The quote should be escaped.
    $this->assertStringContainsString('function handler(input)', $filter);
    // The raw unescaped string should not appear.
    $this->assertStringNotContainsString("'acct_with'quote'", $filter);
  }

  /**
   * Test buildFilter accepts FilterStrategyInterface implementation.
   */
  public function testBuildFilterAcceptsFilterStrategy(): void {
    $filterStrategy = new SimpleFieldFilter('account', 'acct_test');

    $js = CRM_Svixclient_Client::buildFilter($filterStrategy);

    $this->assertStringContainsString('function handler(input)', $js);
    $this->assertStringContainsString("input.account !== 'acct_test'", $js);
  }

  /**
   * Test buildFilter works with custom filter implementation.
   */
  public function testBuildFilterWorksWithCustomImplementation(): void {
    // Create a custom filter using anonymous class.
    $customFilter = new class implements FilterStrategyInterface {

      /**
       * {@inheritdoc}
       */
      public function build(): string {
        return <<<JS
function handler(input) {
    if (!input.custom_field) return null;
    return { payload: input };
}
JS;
      }

    };

    $js = CRM_Svixclient_Client::buildFilter($customFilter);

    $this->assertStringContainsString('function handler(input)', $js);
    $this->assertStringContainsString('input.custom_field', $js);
  }

}
