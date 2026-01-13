<?php

use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use PHPUnit\Framework\TestCase;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Base test class for Svix Client extension tests.
 */
abstract class BaseHeadlessTest extends TestCase implements
  HeadlessInterface,
  TransactionalInterface {

  /**
   * {@inheritdoc}
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

}
