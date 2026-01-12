<?php

/**
 * @file
 * Tests for the AbstractFilterBuilder class.
 *
 * @group headless
 */

/**
 * Tests for the AbstractFilterBuilder class.
 */
class CRM_Svixclient_FilterBuilder_AbstractFilterBuilderTest extends BaseHeadlessTest {

  /**
   * Test filter builder that exposes the protected escapeJsString method.
   *
   * @var TestableFilterBuilder
   */
  private TestableFilterBuilder $filterBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->filterBuilder = new TestableFilterBuilder();
  }

  /**
   * Test escapeJsString with a Stripe account ID.
   */
  public function testEscapeJsStringStripeAccountId(): void {
    $result = $this->filterBuilder->publicEscapeJsString('acct_1234567890abcdef');
    $this->assertEquals('acct_1234567890abcdef', $result);
  }

  /**
   * Test escapeJsString with a GoCardless organisation ID.
   */
  public function testEscapeJsStringGoCardlessOrgId(): void {
    $result = $this->filterBuilder->publicEscapeJsString('OR0001234567');
    $this->assertEquals('OR0001234567', $result);
  }

  /**
   * Test escapeJsString with single quotes.
   */
  public function testEscapeJsStringSingleQuotes(): void {
    $result = $this->filterBuilder->publicEscapeJsString("test'value");
    $this->assertEquals("test\\'value", $result);
  }

  /**
   * Test escapeJsString with double quotes.
   *
   * Double quotes don't need escaping in single-quoted JS strings.
   */
  public function testEscapeJsStringDoubleQuotes(): void {
    $result = $this->filterBuilder->publicEscapeJsString('test"value');
    $this->assertEquals('test"value', $result);
  }

  /**
   * Test escapeJsString with backslashes.
   */
  public function testEscapeJsStringBackslashes(): void {
    $result = $this->filterBuilder->publicEscapeJsString('test\\value');
    $this->assertEquals('test\\\\value', $result);
  }

  /**
   * Test escapeJsString with mixed special characters.
   */
  public function testEscapeJsStringMixed(): void {
    $result = $this->filterBuilder->publicEscapeJsString("test'with\"mixed\\chars");
    $this->assertEquals("test\\'with\"mixed\\\\chars", $result);
  }

  /**
   * Test escapeJsString with newlines.
   *
   * Newlines must be escaped to prevent breaking JavaScript strings.
   */
  public function testEscapeJsStringNewlines(): void {
    $result = $this->filterBuilder->publicEscapeJsString("test\nvalue\rwith\r\nnewlines");
    $this->assertEquals('test\\nvalue\\rwith\\r\\nnewlines', $result);
  }

  /**
   * Test escapeJsString with tab characters.
   */
  public function testEscapeJsStringTabs(): void {
    $result = $this->filterBuilder->publicEscapeJsString("test\tvalue");
    $this->assertEquals('test\\tvalue', $result);
  }

  /**
   * Test escapeJsString prevents JavaScript injection via Stripe-like ID.
   *
   * Malicious input trying to break out of the JS string context.
   */
  public function testEscapeJsStringStripeInjectionAttempt(): void {
    // Attempt to inject: acct_'; alert('xss'); //.
    $malicious = "acct_'; alert('xss'); //";
    $result = $this->filterBuilder->publicEscapeJsString($malicious);
    // Single quotes should be escaped, preventing the injection.
    $this->assertEquals("acct_\\'; alert(\\'xss\\'); //", $result);
  }

  /**
   * Test escapeJsString prevents JavaScript injection via GoCardless-like ID.
   *
   * Malicious input with newline trying to break the JS function.
   */
  public function testEscapeJsStringGoCardlessInjectionAttempt(): void {
    // Attempt to inject: OR001\n'; return null; //.
    $malicious = "OR001\n'; return null; //";
    $result = $this->filterBuilder->publicEscapeJsString($malicious);
    // Newline and single quotes should be escaped.
    $this->assertEquals("OR001\\n\\'; return null; //", $result);
  }

  /**
   * Test escapeJsString with empty string.
   */
  public function testEscapeJsStringEmpty(): void {
    $result = $this->filterBuilder->publicEscapeJsString('');
    $this->assertEquals('', $result);
  }

  /**
   * Test escapeJsString with unicode characters.
   */
  public function testEscapeJsStringUnicode(): void {
    $result = $this->filterBuilder->publicEscapeJsString('test_café_123');
    $this->assertEquals('test_café_123', $result);
  }

}

/**
 * Testable filter builder that exposes protected methods.
 */
class TestableFilterBuilder extends CRM_Svixclient_FilterBuilder_AbstractFilterBuilder {

  /**
   * Public wrapper for the protected escapeJsString method.
   *
   * @param string $value
   *   The value to escape.
   *
   * @return string
   *   The escaped value.
   */
  public function publicEscapeJsString(string $value): string {
    return $this->escapeJsString($value);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): string {
    return 'function handler(input) { return input; }';
  }

}
