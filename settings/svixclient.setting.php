<?php

use CRM_Svixclient_ExtensionUtil as E;

return [
  'svix_api_key' => [
    'name' => 'svix_api_key',
    'type' => 'String',
    'default' => '',
    'html_type' => 'password',
    'title' => E::ts('Svix API Key'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Your Svix API key for webhook management. Can also be set via SVIX_API_KEY environment variable.'),
    'help_text' => E::ts('This key is used to authenticate with the Svix service. Keep it secure.'),
    'settings_pages' => ['svixclient' => ['weight' => 10]],
  ],
  'svix_stripe_source_id' => [
    'name' => 'svix_stripe_source_id',
    'type' => 'String',
    'default' => '',
    'html_type' => 'text',
    'title' => E::ts('Stripe Source ID'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Svix Ingest source ID for Stripe webhooks (e.g., src_stripe_xxx). Created in Svix Dashboard.'),
    'help_text' => E::ts('This is the source ID created in Svix Dashboard for receiving Stripe Connect webhooks.'),
    'settings_pages' => ['svixclient' => ['weight' => 20]],
  ],
  'svix_gocardless_source_id' => [
    'name' => 'svix_gocardless_source_id',
    'type' => 'String',
    'default' => '',
    'html_type' => 'text',
    'title' => E::ts('GoCardless Source ID'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Svix Ingest source ID for GoCardless webhooks (e.g., src_gocardless_xxx). Created in Svix Dashboard.'),
    'help_text' => E::ts('This is the source ID created in Svix Dashboard for receiving GoCardless webhooks.'),
    'settings_pages' => ['svixclient' => ['weight' => 30]],
  ],
];
