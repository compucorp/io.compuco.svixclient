<?php

namespace Civi\Api4;

/**
 * Tests for the SvixDestination API4 entity.
 *
 * @group headless
 */
class SvixDestinationTest extends \BaseHeadlessTest {

  /**
   * Test creating a SvixDestination record.
   */
  public function testCreateDestination(): void {
    // Create a payment processor for the FK relationship.
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();

    $result = SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_test_123')
      ->addValue('svix_destination_id', 'dest_test_456')
      ->addValue('payment_processor_id', $processor['id'])
      ->addValue('created_by', 'unit_test')
      ->execute();

    $this->assertCount(1, $result);
    $destination = $result->first();
    $this->assertEquals('src_test_123', $destination['source_id']);
    $this->assertEquals('dest_test_456', $destination['svix_destination_id']);
    $this->assertEquals($processor['id'], $destination['payment_processor_id']);
    $this->assertEquals('unit_test', $destination['created_by']);
    $this->assertNotEmpty($destination['created_date']);
  }

  /**
   * Test getting SvixDestination records.
   */
  public function testGetDestination(): void {
    // Create a payment processor.
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor 2')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();

    // Create a destination.
    SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_get_test')
      ->addValue('svix_destination_id', 'dest_get_test')
      ->addValue('payment_processor_id', $processor['id'])
      ->addValue('created_by', 'get_test')
      ->execute();

    // Get the destination.
    $result = SvixDestination::get(FALSE)
      ->addWhere('svix_destination_id', '=', 'dest_get_test')
      ->execute();

    $this->assertCount(1, $result);
    $this->assertEquals('src_get_test', $result->first()['source_id']);
  }

  /**
   * Test deleting a SvixDestination record.
   */
  public function testDeleteDestination(): void {
    // Create a payment processor.
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor 3')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();

    // Create a destination.
    $created = SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_delete_test')
      ->addValue('svix_destination_id', 'dest_delete_test')
      ->addValue('payment_processor_id', $processor['id'])
      ->addValue('created_by', 'delete_test')
      ->execute()
      ->first();

    $id = $created['id'];

    // Delete the destination.
    SvixDestination::delete(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();

    // Verify it's deleted.
    $result = SvixDestination::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();

    $this->assertCount(0, $result);
  }

  /**
   * Test CASCADE delete when payment processor is deleted.
   */
  public function testCascadeDeleteOnPaymentProcessorDelete(): void {
    // Create a payment processor.
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor Cascade')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->execute()
      ->first();

    // Create a destination linked to the processor.
    $destination = SvixDestination::create(FALSE)
      ->addValue('source_id', 'src_cascade_test')
      ->addValue('svix_destination_id', 'dest_cascade_test')
      ->addValue('payment_processor_id', $processor['id'])
      ->addValue('created_by', 'cascade_test')
      ->execute()
      ->first();

    // Delete the payment processor.
    PaymentProcessor::delete(FALSE)
      ->addWhere('id', '=', $processor['id'])
      ->execute();

    // Verify the destination is also deleted.
    $result = SvixDestination::get(FALSE)
      ->addWhere('id', '=', $destination['id'])
      ->execute();

    $this->assertCount(0, $result);
  }

}
