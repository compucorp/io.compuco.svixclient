<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Generic\DAOEntity;

/**
 * SvixDestination entity.
 *
 * Stores Svix webhook destination configurations for payment processors.
 * Each destination represents a webhook endpoint registered in Svix
 * that routes webhooks to a specific CiviCRM site.
 *
 * Provided by the Svix Client extension.
 *
 * @searchable primary
 * @package Civi\Api4
 */
class SvixDestination extends DAOEntity {

  /**
   * {@inheritdoc}
   */
  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'get' => ['access CiviCRM'],
      'create' => ['administer CiviCRM'],
      'update' => ['administer CiviCRM'],
      'delete' => ['administer CiviCRM'],
    ];
  }

}
