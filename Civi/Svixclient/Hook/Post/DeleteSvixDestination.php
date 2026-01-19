<?php

declare(strict_types=1);

namespace Civi\Svixclient\Hook\Post;

/**
 * Hook handler for deleting Svix destinations after CiviCRM deletes.
 *
 * This class handles the hook_civicrm_post event for SvixDestination delete
 * operations. After a destination record is deleted from CiviCRM, this hook
 * ensures the corresponding destination is also deleted from Svix.
 */
class DeleteSvixDestination {

  /**
   * The entity ID.
   */
  private ?int $id;

  /**
   * The DAO object containing the deleted record's data.
   *
   * @var object|null
   */
  private ?object $objectRef;

  /**
   * Constructor.
   *
   * @param int|null $id
   *   The ID of the entity.
   * @param object|null $objectRef
   *   The DAO object containing the record data.
   */
  public function __construct(?int $id, ?object $objectRef) {
    $this->id = $id;
    $this->objectRef = $objectRef;
  }

  /**
   * Run the hook handler.
   */
  public function run(): void {
    if (!$this->shouldRun()) {
      return;
    }

    $destination = $this->getDestinationData();
    if ($destination === NULL) {
      return;
    }

    $this->deleteFromSvix($destination);
  }

  /**
   * Determine if this hook should run.
   *
   * @return bool
   *   TRUE if this hook should process the event.
   */
  private function shouldRun(): bool {
    return !empty($this->id);
  }

  /**
   * Get the destination data from the DAO object.
   *
   * @return array|null
   *   The destination data, or NULL if missing required fields.
   */
  private function getDestinationData(): ?array {
    if (empty($this->objectRef)) {
      \Civi::log()->warning('Cannot delete from Svix: objectRef is empty', [
        'id' => $this->id,
      ]);
      return NULL;
    }

    $sourceId = $this->objectRef->source_id ?? NULL;
    $svixDestinationId = $this->objectRef->svix_destination_id ?? NULL;

    if (empty($sourceId) || empty($svixDestinationId)) {
      \Civi::log()->warning('Cannot delete from Svix: missing source_id or svix_destination_id', [
        'id' => $this->id,
        'source_id' => $sourceId,
        'svix_destination_id' => $svixDestinationId,
      ]);
      return NULL;
    }

    return [
      'source_id' => $sourceId,
      'svix_destination_id' => $svixDestinationId,
    ];
  }

  /**
   * Delete the destination from Svix.
   *
   * @param array $destination
   *   The destination data containing source_id and svix_destination_id.
   */
  private function deleteFromSvix(array $destination): void {
    try {
      $client = $this->createClient();
      $client->deleteDestination($destination['source_id'], $destination['svix_destination_id']);

      \Civi::log()->info('Deleted destination from Svix after CiviCRM delete', [
        'id' => $this->id,
        'source_id' => $destination['source_id'],
        'svix_destination_id' => $destination['svix_destination_id'],
      ]);
    }
    catch (\Exception $e) {
      // Log but don't throw - the CiviCRM record is already deleted.
      \Civi::log()->error('Failed to delete destination from Svix', [
        'id' => $this->id,
        'source_id' => $destination['source_id'],
        'svix_destination_id' => $destination['svix_destination_id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Create a Svix client instance.
   *
   * @return \CRM_Svixclient_Client
   *   The Svix client.
   */
  protected function createClient(): \CRM_Svixclient_Client {
    return new \CRM_Svixclient_Client();
  }

}
