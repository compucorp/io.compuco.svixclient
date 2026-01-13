# CiviCRM Svix Client

A CiviCRM extension that provides a Svix webhook client for payment extensions (Stripe, GoCardless) to register webhook destinations without credentials in environment variables.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    SVIX INGEST ARCHITECTURE                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│  YOUR PLATFORM ACCOUNTS                                                     │
│  ══════════════════════                                                     │
│                                                                             │
│  ┌─────────────────────────────┐    ┌─────────────────────────────┐        │
│  │ GoCardless Platform Account │    │ Stripe Connect Platform     │        │
│  │                             │    │                             │        │
│  │ Webhook URL:                │    │ Webhook URL:                │        │
│  │ ingest.svix.com/src_gc_xxx  │    │ ingest.svix.com/src_stripe  │        │
│  │                             │    │                             │        │
│  │ 100+ connected orgs         │    │ 100+ connected accounts     │        │
│  └──────────────┬──────────────┘    └──────────────┬──────────────┘        │
│                 │                                   │                       │
│                 └─────────────────┬─────────────────┘                       │
│                                   │                                         │
│                                   ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                     SVIX INGEST (Cloud)                              │   │
│  │                                                                      │   │
│  │  Sources (Webhook Entry Points):                                     │   │
│  │  ┌───────────────────────┐    ┌───────────────────────┐             │   │
│  │  │ GoCardless Source     │    │ Stripe Source         │             │   │
│  │  │ ID: src_gc_xxx        │    │ ID: src_stripe_xxx    │             │   │
│  │  │ Verify: HMAC-SHA256   │    │ Verify: Stripe-Sig    │             │   │
│  │  └───────────────────────┘    └───────────────────────┘             │   │
│  │                                                                      │   │
│  │  Destinations (created by CiviCRM during OAuth):                     │   │
│  │  ┌────────────────────────────────────────────────────────────────┐ │   │
│  │  │ dest_001: site1.org + filter(org==OR001)                       │ │   │
│  │  │ dest_002: site1.org + filter(acct==acct_001)                   │ │   │
│  │  │ dest_003: site2.org + filter(org==OR002)                       │ │   │
│  │  │ dest_004: site2.org + filter(acct==acct_002)                   │ │   │
│  │  │ ...100+ destinations                                           │ │   │
│  │  └────────────────────────────────────────────────────────────────┘ │   │
│  │                                                                      │   │
│  │  Features:                                                           │   │
│  │  • Signature verification (Stripe native, HMAC for GoCardless)      │   │
│  │  • JavaScript filtering/transformation                               │   │
│  │  • Automatic retry (exponential backoff)                            │   │
│  │  • Dead letter queue                                                 │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                   │                                         │
│                                   ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                      CIVICRM SITES (100+)                            │   │
│  │  Each site registers itself in Svix during OAuth callback            │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Requirements

- CiviCRM 6.4+
- PHP 8.1+
- Svix PHP SDK (for webhook signature verification)

## Installation

1. Install the extension in your CiviCRM extensions directory
2. Run `composer install` in the extension directory
3. Enable the extension via CiviCRM UI or CLI:
   ```bash
   cv ext:enable io.compuco.svixclient
   ```

## Configuration

### Settings

Configure the following settings in CiviCRM or via environment variables:

| Setting | Environment Variable | Description |
|---------|---------------------|-------------|
| `svix_api_key` | `SVIX_API_KEY` | Your Svix API key |
| `svix_stripe_source_id` | - | Svix source ID for Stripe |
| `svix_gocardless_source_id` | - | Svix source ID for GoCardless |

### One-Time Setup (Platform Admin)

1. Create Sources in Svix Dashboard:
   - Create a Stripe source → get `src_stripe_xxx`
   - Create a GoCardless source → get `src_gocardless_xxx`

2. Configure webhook URLs in provider dashboards:
   - Stripe: Point Connect webhook to the Stripe Source URL
   - GoCardless: Point webhook to the GoCardless Source URL

3. Store source IDs in CiviCRM settings

## Usage

### Creating a Destination

```php
// In your payment extension (e.g., Stripe)
// Use your extension's filter builder (see "Implementing Filter Builders" below)
$filter = (new CRM_Stripe_Svix_FilterBuilder())
    ->setAccountId('acct_xxx')
    ->build();

// Create the Svix client
$client = new CRM_Svixclient_Client();

// Create destination in Svix
$sourceId = \Civi::settings()->get('svix_stripe_source_id');
$destination = $client->createDestination(
    sourceId: $sourceId,
    url: 'https://mysite.org/civicrm/payment/ipn/stripe',
    description: 'MySite Stripe Webhooks',
    filterScript: $filter
);

// Store in CiviCRM database
\Civi\Api4\SvixDestination::create()
    ->addValue('source_id', $sourceId)
    ->addValue('svix_destination_id', $destination['id'])
    ->addValue('payment_processor_id', $processorId)
    ->addValue('created_by', 'stripe_oauth')
    ->execute();
```

### Deleting a Destination

```php
$client = new CRM_Svixclient_Client();

// Delete from Svix
$client->deleteDestination($sourceId, $destinationId);

// Delete from CiviCRM database
\Civi\Api4\SvixDestination::delete()
    ->addWhere('svix_destination_id', '=', $destinationId)
    ->execute();
```

### Verifying Webhooks

```php
// Using the API
$result = \Civi\Api4\Svix::verifyWebhook()
    ->setPayload($rawPayload)
    ->setHeaders([
        'svix-id' => $headers['svix-id'],
        'svix-timestamp' => $headers['svix-timestamp'],
        'svix-signature' => $headers['svix-signature'],
    ])
    ->setSecret($webhookSecret)
    ->execute();

if ($result->first()['valid']) {
    // Process the webhook
}

// Or using the Client class directly
try {
    CRM_Svixclient_Client::verifyWebhook($payload, $headers, $secret);
    // Webhook is valid
} catch (\Exception $e) {
    // Verification failed
}
```

## Implementing Filter Builders (For Payment Extensions)

Payment extensions (Stripe, GoCardless, etc.) should implement their own filter builders
by extending the abstract base class provided by this extension.

### Interface

Your filter builder must implement `CRM_Svixclient_FilterBuilder_FilterBuilderInterface`:

```php
interface CRM_Svixclient_FilterBuilder_FilterBuilderInterface {
    public function build(): string;
}
```

### Stripe Example

Create `CRM/Stripe/Svix/FilterBuilder.php` in your Stripe extension:

```php
<?php

class CRM_Stripe_Svix_FilterBuilder extends CRM_Svixclient_FilterBuilder_AbstractFilterBuilder {

  private ?string $accountId = NULL;

  public function setAccountId(string $accountId): self {
    $this->accountId = $accountId;
    return $this;
  }

  public function build(): string {
    if (empty($this->accountId)) {
      throw new \InvalidArgumentException('Account ID is required');
    }

    $escapedAccountId = $this->escapeJsString($this->accountId);

    return <<<JS
function handler(input) {
    if (input.account !== '{$escapedAccountId}') return null;
    return { payload: input };
}
JS;
  }

}
```

Usage in Stripe extension:

```php
$filter = (new CRM_Stripe_Svix_FilterBuilder())
    ->setAccountId($stripeAccountId)
    ->build();
```

### GoCardless Example

Create `CRM/Gocardless/Svix/FilterBuilder.php` in your GoCardless extension:

```php
<?php

class CRM_Gocardless_Svix_FilterBuilder extends CRM_Svixclient_FilterBuilder_AbstractFilterBuilder {

  private ?string $organisationId = NULL;

  public function setOrganisationId(string $organisationId): self {
    $this->organisationId = $organisationId;
    return $this;
  }

  public function build(): string {
    if (empty($this->organisationId)) {
      throw new \InvalidArgumentException('Organisation ID is required');
    }

    $escapedOrgId = $this->escapeJsString($this->organisationId);

    return <<<JS
function handler(input) {
    const isForThisOrg = input.events?.some(
        event => event.links?.organisation === '{$escapedOrgId}'
    );
    if (!isForThisOrg) return null;
    return { payload: input };
}
JS;
  }

}
```

Usage in GoCardless extension:

```php
$filter = (new CRM_Gocardless_Svix_FilterBuilder())
    ->setOrganisationId($gcOrganisationId)
    ->build();
```

### Filter Script Requirements

The JavaScript filter function must:

1. Be named `handler` and accept an `input` parameter
2. Return `null` to reject the webhook (not for this destination)
3. Return `{ payload: input }` to accept and forward the webhook
4. Handle the specific webhook payload structure of your payment provider

### Security: String Escaping

Always use `$this->escapeJsString()` when embedding values in JavaScript filter scripts. This method uses `json_encode()` internally to properly escape:

- Single quotes (`'` → `\'`)
- Backslashes (`\` → `\\`)
- Newlines, tabs, and other control characters (`\n`, `\r`, `\t`)
- Unicode characters

This prevents JavaScript injection attacks if account IDs contain malicious characters.

## API Reference

### Entities

#### SvixDestination

| Field | Type | Description |
|-------|------|-------------|
| id | int | Primary key |
| source_id | string | Svix source ID |
| svix_destination_id | string | Svix destination ID |
| payment_processor_id | int | FK to payment processor |
| created_by | string | Creator identifier |
| created_date | timestamp | Creation timestamp |

### API Actions

```bash
# List destinations
cv api4 SvixDestination.get

# Create destination record
cv api4 SvixDestination.create source_id=src_xxx svix_destination_id=dest_xxx payment_processor_id=1

# Delete destination record
cv api4 SvixDestination.delete +w id=1

# Verify webhook
cv api4 Svix.verifyWebhook payload='{"data":"test"}' headers='{"svix-id":"msg_xxx"}' secret=whsec_xxx
```

## Admin Interface

View configured Svix destinations via the CiviCRM admin menu:

**Administer → Svix Client → Destinations**

This SearchKit-based view displays:
- Destination ID
- Payment Processor name and type
- Svix Source ID and Destination ID
- Created by and creation date

Requires "administer CiviCRM" permission.

## Multi-Site Support

This extension supports 100+ CiviCRM sites sharing a single Svix platform account.

| Component | Behavior |
|-----------|----------|
| API Key | Same across all sites (platform credential) |
| Source IDs | Same across all sites (one per provider) |
| Destinations | Unique per site (created during OAuth) |
| Filters | Unique per site (based on connected account/org ID) |
| Webhook URLs | Unique per site |
| DB Records | Each site stores only its own destination(s) |

## Development

### Running Tests

```bash
# Install dev dependencies
composer install

# Run all PHPUnit tests
phpunit9

# Run a specific test
phpunit9 --filter testEscapeJsStringStripeAccountId
```

## License

AGPL-3.0
