<?php

declare(strict_types=1);

namespace Civi\Svixclient\Enum;

use Civi\Svixclient\Filter\AnyFieldFilter;

/**
 * Tests for the platform source support in SvixProcessorConfig.
 *
 * @group headless
 */
class SvixProcessorConfigTest extends \BaseHeadlessTest {

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
   * Test Stripe Connect has a platform source setting; GoCardless has none.
   */
  public function testPlatformSourceSetting(): void {
    $this->assertSame('svix_source_stripe_platform', SvixProcessorConfig::StripeConnect->getPlatformSourceIdSetting());
    $this->assertNull(SvixProcessorConfig::Gocardless->getPlatformSourceIdSetting());
  }

  /**
   * Test the platform source ID is read from settings.
   */
  public function testPlatformSourceIdComesFromSettings(): void {
    $settings = $this->originalMandatorySettings ?? [];
    $settings['Svix']['svix_source_stripe_platform'] = 'src_platform';
    $this->setMandatorySettings($settings);

    $this->assertSame('src_platform', SvixProcessorConfig::StripeConnect->getPlatformSourceId());
    $this->assertNull(SvixProcessorConfig::Gocardless->getPlatformSourceId());
  }

  /**
   * Test an empty platform source setting counts as not configured.
   */
  public function testEmptyPlatformSourceIdIsNotConfigured(): void {
    $settings = $this->originalMandatorySettings ?? [];
    $settings['Svix']['svix_source_stripe_platform'] = '';
    $this->setMandatorySettings($settings);

    $this->assertNull(SvixProcessorConfig::StripeConnect->getPlatformSourceId());
  }

  /**
   * Test the Stripe platform filter checks every routing field.
   */
  public function testStripePlatformFilterChecksEveryRoutingField(): void {
    $filter = SvixProcessorConfig::StripeConnect->getPlatformFilterStrategy('acct_123');
    $this->assertInstanceOf(AnyFieldFilter::class, $filter);

    $js = $filter->build();
    $this->assertStringContainsString("webhook.payload.account === 'acct_123'", $js);
    $this->assertStringContainsString("webhook.payload.data.object.transfer_data.destination === 'acct_123'", $js);
    $this->assertStringContainsString("webhook.payload.data.object.on_behalf_of === 'acct_123'", $js);
    $this->assertStringContainsString("webhook.payload.data.object.metadata.connected_account_id === 'acct_123'", $js);

    $this->assertNull(SvixProcessorConfig::Gocardless->getPlatformFilterStrategy('OR000123'));
  }

  /**
   * Test the platform description differs from the main one.
   *
   * Re-registering disables destinations by URL and description, so the two
   * must differ for the main destination to be left alone.
   */
  public function testPlatformDescriptionDiffersFromMainDescription(): void {
    $platform = SvixProcessorConfig::StripeConnect->getPlatformDescriptionTemplate();
    $this->assertSame('CiviCRM Stripe platform - {value}', $platform);
    $this->assertNotSame(SvixProcessorConfig::StripeConnect->getDescriptionTemplate(), $platform);
    $this->assertNull(SvixProcessorConfig::Gocardless->getPlatformDescriptionTemplate());
  }

}
