<?php
use CRM_Svixclient_ExtensionUtil as E;

return [
  'name' => 'SvixDestination',
  'table' => 'civicrm_svix_destination',
  'class' => 'CRM_Svixclient_DAO_SvixDestination',
  'getInfo' => fn() => [
    'title' => E::ts('Svix Destination'),
    'title_plural' => E::ts('Svix Destinations'),
    'description' => E::ts('Stores Svix webhook destination configurations for payment processors'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique SvixDestination ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'source_id' => [
      'title' => E::ts('Svix Source ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Svix source ID (e.g., src_stripe_xxx)'),
    ],
    'svix_destination_id' => [
      'title' => E::ts('Svix Destination ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Svix destination ID returned from API'),
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment Processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('FK to Payment Processor'),
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'created_by' => [
      'title' => E::ts('Created By'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('User or process that created this destination'),
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'default' => 'CURRENT_TIMESTAMP',
      'description' => E::ts('Date and time the destination was created'),
    ],
  ],
  'getIndices' => fn() => [
    'index_svix_destination_id' => [
      'fields' => [
        'svix_destination_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'index_payment_processor_id' => [
      'fields' => [
        'payment_processor_id' => TRUE,
      ],
    ],
  ],
  'getPaths' => fn() => [],
];
