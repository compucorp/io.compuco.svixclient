<?php

use CRM_Svixclient_ExtensionUtil as E;

/**
 * Afform for displaying Svix Destinations SearchKit.
 */
return [
  'type' => 'search',
  'title' => E::ts('Svix Destinations'),
  'description' => E::ts('View Svix webhook destinations configured for payment processors'),
  'server_route' => 'civicrm/admin/svix/destinations',
  'permission' => [
    'administer CiviCRM',
  ],
  'navigation' => NULL,
  'icon' => 'fa-list-alt',
  'is_dashlet' => FALSE,
  'is_public' => FALSE,
  'is_token' => FALSE,
];
