<?php
declare(strict_types = 1);

/**
 * Collection of upgrade steps.
 */
final class CRM_Svixclient_Upgrader extends \CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Fallback type for destinations whose payment processor can't be resolved.
   */
  private const FALLBACK_TYPE = 'Unknown';

  /**
   * Support destinations that do not belong to a payment processor.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws CRM_Core_Exception
   */
  public function upgrade_1001(): bool {
    $this->ctx->log->info('Applying update 1001 - add type column to civicrm_svix_destination');
    $table = 'civicrm_svix_destination';

    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists($table, 'type')) {
      CRM_Core_DAO::executeQuery(
        "ALTER TABLE `{$table}`
         ADD COLUMN `type` varchar(255) NULL COMMENT 'The integration this destination belongs to. For payment processors this is the payment processor type name (e.g. \"Stripe Connect\"); for other integrations it is the integration name (e.g. \"Impact Stack\").'
         AFTER `svix_destination_id`"
      );
    }

    $this->backfillType($table);

    CRM_Core_DAO::executeQuery(
      "ALTER TABLE `{$table}`
       MODIFY `type` varchar(255) NOT NULL COMMENT 'The integration this destination belongs to. For payment processors this is the payment processor type name (e.g. \"Stripe Connect\"); for other integrations it is the integration name (e.g. \"Impact Stack\").'"
    );

    if (!CRM_Core_BAO_SchemaHandler::checkIfIndexExists($table, 'index_type')) {
      CRM_Core_DAO::executeQuery("CREATE INDEX `index_type` ON `{$table}` (`type`)");
    }

    CRM_Core_DAO::executeQuery(
      "ALTER TABLE `{$table}`
       MODIFY `payment_processor_id` int unsigned NULL COMMENT 'FK to Payment Processor. Only set for payment processor integrations; NULL for other integration types.'"
    );

    return TRUE;
  }

  /**
   * Populates `type` for rows that do not have a value yet.
   *
   * @param string $table
   *   The destination table name.
   */
  private function backfillType(string $table): void {
    CRM_Core_DAO::executeQuery(
      "UPDATE `{$table}` sd
       INNER JOIN `civicrm_payment_processor` pp ON pp.id = sd.payment_processor_id
       INNER JOIN `civicrm_payment_processor_type` ppt ON ppt.id = pp.payment_processor_type_id
       SET sd.`type` = ppt.name
       WHERE sd.`type` IS NULL OR sd.`type` = ''"
    );

    CRM_Core_DAO::executeQuery(
      "UPDATE `{$table}` SET `type` = %1 WHERE `type` IS NULL OR `type` = ''",
      [1 => [self::FALLBACK_TYPE, 'String']]
    );
  }

}
