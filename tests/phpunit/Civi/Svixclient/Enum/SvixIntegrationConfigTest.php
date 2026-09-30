<?php

declare(strict_types=1);

namespace Civi\Svixclient\Enum;

/**
 * Tests for the SvixIntegrationConfig enum.
 *
 * @group headless
 */
class SvixIntegrationConfigTest extends \BaseHeadlessTest {

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
    $this->setMandatorySvixSettings($this->originalMandatorySettings);
    parent::tearDown();
  }

  /**
   * Replaces the mandatory settings and reloads them into the settings bag.
   *
   * @param array|null $settings
   *   The full mandatory settings array, or NULL to unset it.
   */
  private function setMandatorySvixSettings(?array $settings): void {
    if ($settings === NULL) {
      unset($GLOBALS['civicrm_setting']);
    }
    else {
      $GLOBALS['civicrm_setting'] = $settings;
    }

    \Civi::service('settings_manager')->useMandatory();
  }

  /**
   * Configures a single Svix setting as a mandatory setting.
   *
   * @param string $name
   *   The setting name.
   * @param string $value
   *   The setting value.
   */
  private function setSvixSetting(string $name, string $value): void {
    $settings = $this->originalMandatorySettings ?? [];
    $settings['Svix'][$name] = $value;
    $this->setMandatorySvixSettings($settings);
  }

  /**
   * Test the Impact Stack case maps to its own source setting.
   */
  public function testImpactStackUsesItsOwnSourceSetting(): void {
    $this->assertEquals(
      'svix_source_impact_stack',
      SvixIntegrationConfig::ImpactStack->getSourceIdSetting()
    );
  }

  /**
   * Test the Impact Stack case points at the Impact Stack webhook path.
   */
  public function testImpactStackWebhookPath(): void {
    $this->assertEquals(
      'civicrm/impactstack/api/webhook',
      SvixIntegrationConfig::ImpactStack->getWebhookPath()
    );
  }

  /**
   * Test fromType resolves a known integration name.
   */
  public function testFromTypeResolvesKnownIntegration(): void {
    $this->assertSame(
      SvixIntegrationConfig::ImpactStack,
      SvixIntegrationConfig::fromType('Impact Stack')
    );
  }

  /**
   * Test fromType returns NULL for an unknown integration name.
   */
  public function testFromTypeReturnsNullForUnknownIntegration(): void {
    $this->assertNull(SvixIntegrationConfig::fromType('Not An Integration'));
  }

  /**
   * Test getSourceId returns NULL when the setting is empty.
   */
  public function testGetSourceIdReturnsNullWhenUnset(): void {
    $this->setSvixSetting('svix_source_impact_stack', '');

    $this->assertNull(SvixIntegrationConfig::ImpactStack->getSourceId());
  }

  /**
   * Test getSourceId returns the configured source ID.
   */
  public function testGetSourceIdReturnsConfiguredValue(): void {
    $this->setSvixSetting('svix_source_impact_stack', 'src_impact_stack_123');

    $this->assertEquals(
      'src_impact_stack_123',
      SvixIntegrationConfig::ImpactStack->getSourceId()
    );
  }

  /**
   * Test the description template contains the value placeholder.
   */
  public function testDescriptionTemplateHasPlaceholder(): void {
    $this->assertStringContainsString(
      '{value}',
      SvixIntegrationConfig::ImpactStack->getDescriptionTemplate()
    );
  }

  /**
   * Test getWebhookUrl builds an absolute URL for the webhook path.
   */
  public function testGetWebhookUrlIsAbsolute(): void {
    $url = SvixIntegrationConfig::ImpactStack->getWebhookUrl();

    $this->assertStringContainsString('civicrm/impactstack/api/webhook', $url);
    $this->assertStringStartsWith('http', $url);
  }

}
