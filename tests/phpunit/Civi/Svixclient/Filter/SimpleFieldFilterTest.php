<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

use PHPUnit\Framework\TestCase;

/**
 * Tests for SimpleFieldFilter.
 *
 * @group headless
 */
class SimpleFieldFilterTest extends TestCase {

  /**
   * Test that filter implements FilterStrategyInterface.
   */
  public function testImplementsFilterStrategyInterface(): void {
    $filter = new SimpleFieldFilter('account', 'acct_123');

    $this->assertInstanceOf(FilterStrategyInterface::class, $filter);
  }

  /**
   * Test build() returns valid JavaScript for Stripe account field.
   */
  public function testBuildReturnsValidJavaScriptForStripe(): void {
    $filter = new SimpleFieldFilter('account', 'acct_1234567890');
    $js = $filter->build();

    $this->assertStringContainsString('function handler(webhook)', $js);
    $this->assertStringContainsString("webhook.payload.account !== 'acct_1234567890'", $js);
    $this->assertStringContainsString('webhook.cancel = true', $js);
    $this->assertStringContainsString('return webhook', $js);
  }

  /**
   * Test build() returns valid JavaScript for GoCardless organisation field.
   */
  public function testBuildReturnsValidJavaScriptForGoCardless(): void {
    $filter = new SimpleFieldFilter('organisation_id', 'OR000123');
    $js = $filter->build();

    $this->assertStringContainsString('function handler(webhook)', $js);
    $this->assertStringContainsString("webhook.payload.organisation_id !== 'OR000123'", $js);
    $this->assertStringContainsString('webhook.cancel = true', $js);
    $this->assertStringContainsString('return webhook', $js);
  }

  /**
   * Test build() supports nested field paths.
   */
  public function testBuildSupportsNestedFieldPaths(): void {
    $filter = new SimpleFieldFilter('links.organisation', 'OR000123');
    $js = $filter->build();

    $this->assertStringContainsString('function handler(webhook)', $js);
    $this->assertStringContainsString("webhook.payload.links.organisation !== 'OR000123'", $js);
    $this->assertStringContainsString('webhook.cancel = true', $js);
    $this->assertStringContainsString('return webhook', $js);
  }

  /**
   * Test build() properly escapes single quotes in values.
   */
  public function testBuildEscapesSingleQuotes(): void {
    $filter = new SimpleFieldFilter('account', "acct_test'quote");
    $js = $filter->build();

    // Single quote should be escaped.
    $this->assertStringContainsString("acct_test\\'quote", $js);
    // Raw unescaped string should not appear.
    $this->assertStringNotContainsString("'acct_test'quote'", $js);
  }

  /**
   * Test build() properly escapes backslashes.
   */
  public function testBuildEscapesBackslashes(): void {
    $filter = new SimpleFieldFilter('account', 'acct_test\\value');
    $js = $filter->build();

    // Backslash should be escaped.
    $this->assertStringContainsString('acct_test\\\\value', $js);
  }

  /**
   * Test build() handles unicode characters.
   */
  public function testBuildHandlesUnicodeCharacters(): void {
    $filter = new SimpleFieldFilter('account', 'acct_tëst_üñíçödé');
    $js = $filter->build();

    // Unicode should be preserved (JSON_UNESCAPED_UNICODE).
    $this->assertStringContainsString('acct_tëst_üñíçödé', $js);
  }

  /**
   * Test that the generated JavaScript has correct structure.
   */
  public function testGeneratedJavaScriptStructure(): void {
    $filter = new SimpleFieldFilter('account', 'acct_test');
    $js = $filter->build();

    // Should start with function declaration.
    $this->assertStringStartsWith('function handler(webhook)', $js);

    // Should contain the conditional check on webhook.payload.
    $this->assertMatchesRegularExpression('/if\s*\(webhook\.payload\.account\s*!==/', $js);

    // Should contain cancel mechanism and return.
    $this->assertStringContainsString('webhook.cancel = true', $js);
    $this->assertStringContainsString('return webhook', $js);
  }

}
