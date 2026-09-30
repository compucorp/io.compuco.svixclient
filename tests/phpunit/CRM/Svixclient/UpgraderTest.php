<?php

use Civi\Api4\PaymentProcessor;
use Civi\Api4\SvixDestination;

/**
 * Tests for the Svix Client upgrader.
 *
 * @group headless
 */
class CRM_Svixclient_UpgraderTest extends BaseHeadlessTest {

  /**
   * Asserts the backfill takes the payment processor's type name.
   */
  public function testBackfillTypeUsesPaymentProcessorTypeName(): void {
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Upgrader Test Processor')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();

    // A row as it looked before the upgrade: no type recorded yet.
    $id = $this->insertUntypedDestination('ep_backfill', (int) $processor['id']);

    $this->runBackfill();

    $this->assertSame('Dummy', $this->storedType($id));
  }

  /**
   * Asserts a row whose processor cannot be resolved still gets a value.
   */
  public function testBackfillTypeFallsBackWhenProcessorIsUnresolvable(): void {
    $id = $this->insertUntypedDestination('ep_orphan', NULL);

    $this->runBackfill();

    $this->assertSame('Unknown', $this->storedType($id));
  }

  /**
   * Asserts the backfill leaves rows that already have a type alone.
   */
  public function testBackfillTypeDoesNotOverwriteAnExistingType(): void {
    $created = SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_keep')
      ->addValue('svix_destination_id', 'ep_keep')
      ->addValue('type', 'Impact Stack')
      ->execute()
      ->first();

    $this->runBackfill();

    $this->assertSame('Impact Stack', $this->storedType((int) $created['id']));
  }

  /**
   * Asserts re-running the task against a current schema is a no-op.
   */
  public function testUpgrade1001IsIdempotentWhenSchemaIsCurrent(): void {
    $upgrader = $this->upgrader();

    $this->assertTrue($upgrader->upgrade_1001());
    $this->assertTrue($upgrader->upgrade_1001());

    $this->assertTrue(
      CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_svix_destination', 'type')
    );
    $this->assertTrue(
      CRM_Core_BAO_SchemaHandler::checkIfIndexExists('civicrm_svix_destination', 'index_type')
    );
  }

  /**
   * Builds an upgrader with the task context its logging needs.
   *
   * @return \CRM_Svixclient_Upgrader
   *   The upgrader under test.
   */
  private function upgrader(): CRM_Svixclient_Upgrader {
    $upgrader = new CRM_Svixclient_Upgrader();

    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = Log::singleton('null');

    $property = new ReflectionProperty(CRM_Svixclient_Upgrader::class, 'ctx');
    $property->setAccessible(TRUE);
    $property->setValue($upgrader, $ctx);

    return $upgrader;
  }

  /**
   * Runs the private backfill step against the destination table.
   */
  private function runBackfill(): void {
    $method = new ReflectionMethod(CRM_Svixclient_Upgrader::class, 'backfillType');
    $method->setAccessible(TRUE);
    $method->invoke($this->upgrader(), 'civicrm_svix_destination');
  }

  /**
   * Inserts a destination with an empty type, as pre-upgrade rows have.
   *
   * @param string $destinationId
   *   The Svix destination ID to record.
   * @param int|null $paymentProcessorId
   *   The payment processor to link, or NULL for none.
   *
   * @return int
   *   The new record's ID.
   */
  private function insertUntypedDestination(string $destinationId, ?int $paymentProcessorId): int {
    $params = [
      1 => ['src_upgrader', 'String'],
      2 => [$destinationId, 'String'],
    ];

    $processorValue = 'NULL';
    if ($paymentProcessorId !== NULL) {
      $processorValue = '%3';
      $params[3] = [$paymentProcessorId, 'Integer'];
    }

    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_svix_destination
         (source_id, svix_destination_id, `type`, payment_processor_id, created_date)
       VALUES (%1, %2, '', {$processorValue}, NOW())",
      $params
    );

    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

  /**
   * Reads the stored type for a destination row.
   *
   * @param int $id
   *   The destination record ID.
   *
   * @return string|null
   *   The stored type.
   */
  private function storedType(int $id): ?string {
    $type = CRM_Core_DAO::singleValueQuery(
      'SELECT `type` FROM civicrm_svix_destination WHERE id = %1',
      [1 => [$id, 'Integer']]
    );

    return $type === NULL ? NULL : (string) $type;
  }

}
