<?php

use CRM_Svixclient_ExtensionUtil as E;

/**
 * Managed entities for Svix Destinations admin interface.
 *
 * Creates:
 * - Navigation menu: Administer > Svix Client > Destinations
 * - SavedSearch: SvixDestinations
 * - SearchDisplay: SvixDestinations_Table
 */
return [
  [
    'name' => 'Navigation_SvixClient',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'svix_client',
        'label' => E::ts('Svix Client'),
        'permission' => [
          'administer CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Administer',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 100,
      ],
      'match' => [
        'name',
        'domain_id',
      ],
    ],
  ],
  [
    'name' => 'Navigation_SvixDestinations',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'svix_destinations',
        'label' => E::ts('Destinations'),
        'url' => 'civicrm/admin/svix/destinations',
        'permission' => [
          'administer CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'svix_client',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 1,
      ],
      'match' => [
        'name',
        'domain_id',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_SvixDestinations',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'SvixDestinations',
        'label' => E::ts('Svix Destinations'),
        'api_entity' => 'SvixDestination',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'source_id',
            'svix_destination_id',
            'payment_processor_id.name',
            'payment_processor_id.payment_processor_type_id:label',
            'created_by.display_name',
            'created_date',
          ],
          'orderBy' => [],
          'where' => [],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_SvixDestinations_SearchDisplay_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'SvixDestinations_Table',
        'label' => E::ts('Svix Destinations Table'),
        'saved_search_id.name' => 'SvixDestinations',
        'type' => 'table',
        'settings' => [
          'description' => E::ts('View Svix webhook destinations configured for payment processors'),
          'sort' => [
            ['created_date', 'DESC'],
          ],
          'limit' => 50,
          'pager' => [
            'show_count' => TRUE,
            'expose_limit' => TRUE,
          ],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'dataType' => 'Integer',
              'label' => E::ts('ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'payment_processor_id.name',
              'dataType' => 'String',
              'label' => E::ts('Payment Processor'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'payment_processor_id.payment_processor_type_id:label',
              'dataType' => 'String',
              'label' => E::ts('Type'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'source_id',
              'dataType' => 'String',
              'label' => E::ts('Svix Source ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'svix_destination_id',
              'dataType' => 'String',
              'label' => E::ts('Svix Destination ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'created_by.display_name',
              'dataType' => 'String',
              'label' => E::ts('Created By'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'created_date',
              'dataType' => 'Timestamp',
              'label' => E::ts('Created Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'links',
              'label' => E::ts('Actions'),
              'links' => [
                [
                  'path' => '',
                  'icon' => 'fa-trash',
                  'text' => E::ts('Delete'),
                  'style' => 'danger',
                  'condition' => [],
                  'task' => 'delete',
                  'entity' => 'SvixDestination',
                  'join' => '',
                  'target' => 'crm-popup',
                ],
              ],
            ],
          ],
          'actions' => TRUE,
          'classes' => [
            'table',
            'table-striped',
          ],
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];
