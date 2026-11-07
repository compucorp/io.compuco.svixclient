<?php

declare(strict_types=1);

namespace Civi\Svixclient\Filter;

use PHPUnit\Framework\TestCase;

/**
 * Tests for AnyFieldFilter.
 *
 * @group headless
 */
class AnyFieldFilterTest extends TestCase {

  /**
   * Test that filter implements FilterStrategyInterface.
   */
  public function testImplementsFilterStrategyInterface(): void {
    $filter = new AnyFieldFilter(['account'], 'acct_123');

    $this->assertInstanceOf(FilterStrategyInterface::class, $filter);
  }

  /**
   * Test build() matches any field and cancels when none match.
   */
  public function testBuildMatchesAnyFieldAndCancelsOtherwise(): void {
    $js = (new AnyFieldFilter(['account', 'data.object.on_behalf_of'], 'acct_123'))->build();

    $this->assertStringContainsString('function handler(webhook)', $js);
    $this->assertStringContainsString("(webhook.payload.account === 'acct_123')", $js);
    $this->assertStringContainsString('|| (webhook.payload.data && webhook.payload.data.object', $js);
    $this->assertStringContainsString("webhook.payload.data.object.on_behalf_of === 'acct_123'", $js);
    $this->assertStringContainsString('if (!matches)', $js);
    $this->assertStringContainsString('webhook.cancel = true', $js);
    $this->assertStringContainsString('return webhook', $js);
  }

  /**
   * Test every intermediate object is checked before the comparison.
   */
  public function testBuildChecksEachLevelOfNestedPaths(): void {
    $js = (new AnyFieldFilter(['data.object.transfer_data.destination'], 'acct_123'))->build();

    $this->assertStringContainsString(
      "webhook.payload.data && webhook.payload.data.object && webhook.payload.data.object.transfer_data && webhook.payload.data.object.transfer_data.destination === 'acct_123'",
      $js
    );
  }

  /**
   * Test the routing value is escaped.
   */
  public function testBuildEscapesValue(): void {
    $js = (new AnyFieldFilter(['account'], "acct_'; webhook.cancel = false; '"))->build();

    $this->assertStringContainsString("acct_\\'; webhook.cancel = false; \\'", $js);
  }

  /**
   * Test an empty routing value is rejected.
   *
   * An empty value would match platform events that lack the fields and
   * route other sites' events to this destination.
   */
  public function testEmptyValueIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AnyFieldFilter(['account'], '');
  }

  /**
   * Test at least one field is required.
   */
  public function testNoFieldsIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AnyFieldFilter([], 'acct_123');
  }

  /**
   * Test field paths must be plain property accessors.
   */
  public function testFieldThatIsNotAPropertyPathIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new AnyFieldFilter(["account || 'x'"], 'acct_123');
  }

}
