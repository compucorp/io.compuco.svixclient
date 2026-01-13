<?php

declare(strict_types=1);

/**
 * Interface for Svix filter builders.
 *
 * Filter builders generate JavaScript filter functions for Svix Ingest
 * that route webhooks to the correct destination based on account/org ID.
 */
interface CRM_Svixclient_FilterBuilder_FilterBuilderInterface {

  /**
   * Build the JavaScript filter function.
   *
   * @return string
   *   The JavaScript filter function as a string.
   *
   * @throws \InvalidArgumentException
   *   If required parameters are not set.
   */
  public function build(): string;

}
