<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Action\Svix\VerifyWebhook;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Svix utility API.
 *
 * Provides utility actions for working with Svix webhooks,
 * including webhook verification.
 *
 * @package Civi\Api4
 */
class Svix extends AbstractEntity {

  /**
   * Verify an incoming webhook from Svix.
   *
   * @param bool $checkPermissions
   *   Whether to check permissions.
   *
   * @return \Civi\Api4\Action\Svix\VerifyWebhook
   *   The verify webhook action.
   */
  public static function verifyWebhook(bool $checkPermissions = TRUE): VerifyWebhook {
    return (new VerifyWebhook(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * {@inheritdoc}
   */
  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * {@inheritdoc}
   */
  public static function permissions(): array {
    return [
      'verifyWebhook' => ['access CiviCRM'],
    ];
  }

}
