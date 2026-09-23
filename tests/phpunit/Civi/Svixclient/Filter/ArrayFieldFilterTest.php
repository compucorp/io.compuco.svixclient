<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

use Civi\Svixclient\Enum\SvixProcessorConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArrayFieldFilter.
 *
 * @group headless
 */
class ArrayFieldFilterTest extends TestCase {

  /**
   * Test that filter implements FilterStrategyInterface.
   */
  public function testImplementsFilterStrategyInterface(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000123');

    $this->assertInstanceOf(FilterStrategyInterface::class, $filter);
  }

  /**
   * Test build() returns valid JavaScript for GoCardless events array.
   */
  public function testBuildReturnsValidJavaScriptForGoCardless(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000123');
    $js = $filter->build();

    $this->assertStringContainsString('function handler(webhook)', $js);
    $this->assertStringContainsString('webhook.payload.events || []', $js);
    $this->assertStringContainsString('for (var i = 0; i < items.length; i++)', $js);
    $this->assertStringContainsString("items[i].links && items[i].links.organisation === 'OR000123'", $js);
    $this->assertStringContainsString('return webhook', $js);
    $this->assertStringContainsString('webhook.cancel = true', $js);
  }

  /**
   * Test build() generates null-safe checks for nested field path.
   */
  public function testBuildGeneratesNullSafeChecks(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000123');
    $js = $filter->build();

    // Should check items[i].links before accessing items[i].links.organisation.
    $this->assertStringContainsString('items[i].links && items[i].links.organisation', $js);
  }

  /**
   * Test build() handles single-segment nested field (no intermediate checks).
   */
  public function testBuildHandlesSingleSegmentField(): void {
    $filter = new ArrayFieldFilter('events', 'organisation', 'OR000123');
    $js = $filter->build();

    // No intermediate null check needed — direct equality.
    $this->assertStringContainsString("items[i].organisation === 'OR000123'", $js);
    // Should NOT contain intermediate && check.
    $this->assertStringNotContainsString('items[i].organisation && items[i].organisation.', $js);
  }

  /**
   * Test build() handles deeply nested field path.
   */
  public function testBuildHandlesDeeplyNestedFieldPath(): void {
    $filter = new ArrayFieldFilter('data', 'a.b.c', 'value');
    $js = $filter->build();

    $this->assertStringContainsString('webhook.payload.data || []', $js);
    $this->assertStringContainsString("items[i].a && items[i].a.b && items[i].a.b.c === 'value'", $js);
  }

  /**
   * Test build() properly escapes single quotes in values.
   */
  public function testBuildEscapesSingleQuotes(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', "OR000'123");
    $js = $filter->build();

    $this->assertStringContainsString("OR000\\'123", $js);
    $this->assertStringNotContainsString("'OR000'123'", $js);
  }

  /**
   * Test build() properly escapes backslashes.
   */
  public function testBuildEscapesBackslashes(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000\\123');
    $js = $filter->build();

    $this->assertStringContainsString('OR000\\\\123', $js);
  }

  /**
   * Test build() handles unicode characters.
   */
  public function testBuildHandlesUnicodeCharacters(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR_tëst_üñíçödé');
    $js = $filter->build();

    $this->assertStringContainsString('OR_tëst_üñíçödé', $js);
  }

  /**
   * Test that the generated JavaScript has correct structure.
   */
  public function testGeneratedJavaScriptStructure(): void {
    $filter = new ArrayFieldFilter('events', 'links.organisation', 'OR000123');
    $js = $filter->build();

    // Should start with function declaration.
    $this->assertStringStartsWith('function handler(webhook)', $js);

    // Should contain the array iteration.
    $this->assertMatchesRegularExpression('/var items = webhook\.payload\.events \|\| \[\]/', $js);

    // Should contain the for loop.
    $this->assertMatchesRegularExpression('/for\s*\(var i = 0;\s*i < items\.length;\s*i\+\+\)/', $js);

    // Should contain early return on match.
    $this->assertMatchesRegularExpression('/if\s*\(items\[i\]\.links && items\[i\]\.links\.organisation === \'OR000123\'\)\s*\{/', $js);

    // Should contain cancel mechanism after loop.
    $this->assertStringContainsString('webhook.cancel = true', $js);
  }

  /**
   * Test SvixProcessorConfig returns correct filter for Stripe.
   */
  public function testProcessorConfigReturnsSimpleFilterForStripe(): void {
    $filter = SvixProcessorConfig::StripeConnect->getFilterStrategy('acct_123');

    $this->assertInstanceOf(SimpleFieldFilter::class, $filter);
  }

  /**
   * Test SvixProcessorConfig returns correct filter for GoCardless.
   */
  public function testProcessorConfigReturnsArrayFilterForGoCardless(): void {
    $filter = SvixProcessorConfig::Gocardless->getFilterStrategy('OR000123');

    $this->assertInstanceOf(ArrayFieldFilter::class, $filter);
  }

  /**
   * Test GoCardless filter from SvixProcessorConfig generates correct JS.
   */
  public function testProcessorConfigGoCardlessFilterGeneratesCorrectJs(): void {
    $filter = SvixProcessorConfig::Gocardless->getFilterStrategy('OR000123');
    $js = $filter->build();

    $this->assertStringContainsString('webhook.payload.events || []', $js);
    $this->assertStringContainsString("items[i].links.organisation === 'OR000123'", $js);
  }

}
