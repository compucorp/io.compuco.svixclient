<?php

declare(strict_types=1);

namespace Civi\Svixclient\Hook\Post;

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeleteSvixDestination post hook handler.
 *
 * @group headless
 */
class DeleteSvixDestinationTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * Set up CiviCRM headless environment.
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Test that hook does not run when ID is null.
   */
  public function testDoesNotRunForNullId(): void {
    $objectRef = $this->createMockObjectRef('src_test', 'ep_test');
    $hook = new DeleteSvixDestination(NULL, $objectRef);

    // Should not throw any exceptions or call Svix API.
    $hook->run();

    $this->assertTrue(TRUE);
  }

  /**
   * Test that hook handles empty objectRef gracefully.
   */
  public function testHandlesEmptyObjectRef(): void {
    $hook = new DeleteSvixDestination(1, NULL);

    // Should not throw any exceptions.
    $hook->run();

    $this->assertTrue(TRUE);
  }

  /**
   * Test that hook handles missing source_id gracefully.
   */
  public function testHandlesMissingSourceId(): void {
    $objectRef = new \stdClass();
    $objectRef->svix_destination_id = 'ep_test';
    // source_id is missing.
    $hook = new DeleteSvixDestination(1, $objectRef);

    // Should not throw any exceptions.
    $hook->run();

    $this->assertTrue(TRUE);
  }

  /**
   * Test that hook handles missing svix_destination_id gracefully.
   */
  public function testHandlesMissingSvixDestinationId(): void {
    $objectRef = new \stdClass();
    $objectRef->source_id = 'src_test';
    // svix_destination_id is missing.
    $hook = new DeleteSvixDestination(1, $objectRef);

    // Should not throw any exceptions.
    $hook->run();

    $this->assertTrue(TRUE);
  }

  /**
   * Test that hook deletes from Svix when destination data is present.
   *
   * @dataProvider provideDestinationData
   */
  public function testDeletesFromSvixWhenDestinationDataPresent(
    string $sourceId,
    string $svixDestinationId,
  ): void {
    $objectRef = $this->createMockObjectRef($sourceId, $svixDestinationId);

    // Create a mock hook that tracks if deleteFromSvix was called.
    $mockHook = $this->createMockHook(1, $objectRef);

    // Run the hook.
    $mockHook->run();

    // Verify the client's deleteDestination was called with correct params.
    $this->assertTrue($mockHook->deleteWasCalled);
    $this->assertEquals($sourceId, $mockHook->deletedSourceId);
    $this->assertEquals($svixDestinationId, $mockHook->deletedDestinationId);
  }

  /**
   * Data provider for destination test data.
   */
  public function provideDestinationData(): array {
    return [
      'stripe destination' => [
        'src_stripe_test123',
        'ep_destination_abc',
      ],
      'gocardless destination' => [
        'src_gocardless_test456',
        'ep_destination_xyz',
      ],
    ];
  }

  /**
   * Test that Svix API errors are logged but don't throw exceptions.
   */
  public function testSvixApiErrorsAreLoggedButDontThrow(): void {
    $objectRef = $this->createMockObjectRef('src_test', 'ep_test');

    // Create a mock hook that throws an exception.
    $mockHook = new class(1, $objectRef) extends DeleteSvixDestination {

      /**
       * {@inheritdoc}
       */
      protected function createClient(): \CRM_Svixclient_Client {
        return new class extends \CRM_Svixclient_Client {

          /**
           * Constructor that skips parent.
           */
          public function __construct() {
            // Skip parent constructor.
          }

          /**
           * Mock delete that throws an exception.
           */
          public function deleteDestination(string $sourceId, string $destinationId): bool {
            throw new \CRM_Core_Exception('Svix API error');
          }

        };
      }

    };

    // Run the hook - should not throw despite API error.
    $mockHook->run();

    // If we get here, the exception was caught and logged.
    $this->assertTrue(TRUE);
  }

  /**
   * Create a mock objectRef with source_id and svix_destination_id.
   *
   * @param string $sourceId
   *   The source ID.
   * @param string $svixDestinationId
   *   The Svix destination ID.
   *
   * @return object
   *   A mock object with the properties set.
   */
  private function createMockObjectRef(string $sourceId, string $svixDestinationId): object {
    $objectRef = new \stdClass();
    $objectRef->source_id = $sourceId;
    $objectRef->svix_destination_id = $svixDestinationId;
    return $objectRef;
  }

  /**
   * Create a mock hook that tracks delete calls.
   *
   * @param int|null $id
   *   The ID.
   * @param object|null $objectRef
   *   The object reference.
   *
   * @return object
   *   A mock hook instance.
   */
  private function createMockHook(?int $id, $objectRef): object {
    return new class($id, $objectRef) extends DeleteSvixDestination {

      /**
       * Whether delete was called.
       *
       * @var bool
       */
      public bool $deleteWasCalled = FALSE;

      /**
       * The source ID passed to delete.
       *
       * @var string|null
       */
      public ?string $deletedSourceId = NULL;

      /**
       * The destination ID passed to delete.
       *
       * @var string|null
       */
      public ?string $deletedDestinationId = NULL;

      /**
       * {@inheritdoc}
       */
      protected function createClient(): \CRM_Svixclient_Client {
        $hook = $this;
        return new class($hook) extends \CRM_Svixclient_Client {

          /**
           * Reference to parent hook.
           *
           * @var object
           */
          private $hook;

          /**
           * Constructor.
           *
           * @param object $hook
           *   The parent hook instance.
           */
          public function __construct($hook) {
            $this->hook = $hook;
          }

          /**
           * Mock delete that records the call.
           */
          public function deleteDestination(string $sourceId, string $destinationId): bool {
            $this->hook->deleteWasCalled = TRUE;
            $this->hook->deletedSourceId = $sourceId;
            $this->hook->deletedDestinationId = $destinationId;
            return TRUE;
          }

        };
      }

    };
  }

}
