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

All Svix configuration is stored in `civicrm.settings.php` (not in the database). Add the following to your settings file:

```php
// Svix webhook routing settings
// Server URL - use EU server for EU accounts (API keys ending in .eu)
$civicrm_setting['Svix']['svix_server_url'] = 'https://api.eu.svix.com';  // or https://api.svix.com for US

// Svix API key (test or live)
$civicrm_setting['Svix']['svix_api_key'] = 'sk_your_svix_api_key';

// Source IDs (one per payment provider, shared across all sites)
$civicrm_setting['Svix']['svix_stripe_source_id'] = 'src_stripe_xxx';
$civicrm_setting['Svix']['svix_gocardless_source_id'] = 'src_gocardless_xxx';
```

| Setting | Description |
|---------|-------------|
| `svix_server_url` | Svix API server URL. Use `https://api.eu.svix.com` for EU accounts, `https://api.svix.com` for US (default) |
| `svix_api_key` | Your Svix API key. Can also be set via `SVIX_API_KEY` environment variable |
| `svix_stripe_source_id` | Svix source ID for Stripe webhooks |
| `svix_gocardless_source_id` | Svix source ID for GoCardless webhooks |

**Important:** The signing secret for webhook verification is stored per-destination in the database (fetched automatically from Svix API when creating destinations).

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
// Build the routing filter using the built-in helper
$filter = CRM_Svixclient_Client::buildRoutingFilter('account', 'acct_xxx');

// Create the Svix client
$client = new CRM_Svixclient_Client();

// Create destination in Svix
$sourceId = \Civi::settings()->get('svix_stripe_source_id');
$destination = $client->createDestination(
    sourceId: $sourceId,
    url: 'https://mysite.org/civicrm/payment/ipn/stripe',
    description: 'MySite Stripe Webhooks'
);

// Set the transformation (filter script) - must be done separately
$client->setTransformation($sourceId, $destination['id'], $filter);

// Get the signing secret for webhook verification
$signingSecret = $client->getDestinationSecret($sourceId, $destination['id']);

// Store in CiviCRM database (including signing secret)
\Civi\Api4\SvixDestination::create()
    ->addValue('source_id', $sourceId)
    ->addValue('svix_destination_id', $destination['id'])
    ->addValue('payment_processor_id', $processorId)
    ->addValue('signing_secret', $signingSecret)
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

## Building Routing Filters

This extension uses the **Strategy Pattern** for building JavaScript filter functions that route webhooks to the correct destination.

### Architecture

```
FilterStrategyInterface (contract)
         ↑
    implements
         │
SimpleFieldFilter (concrete strategy)
         │
    used by
         ↓
Client::buildFilter() / Client::buildRoutingFilter()
```

### Basic Usage (Convenience Method)

For simple field matching, use the static convenience method:

```php
// Stripe: match by 'account' field
$filter = CRM_Svixclient_Client::buildRoutingFilter('account', 'acct_xxx');

// GoCardless: match by 'organisation_id' field
$filter = CRM_Svixclient_Client::buildRoutingFilter('organisation_id', 'OR000123');

// Nested field paths are supported
$filter = CRM_Svixclient_Client::buildRoutingFilter('links.organisation', 'OR000123');
```

### Strategy Pattern Usage (Extensible)

For more control or custom filters, use the Strategy Pattern directly:

```php
use Civi\Svixclient\Filter\SimpleFieldFilter;

// Create a filter strategy
$filter = new SimpleFieldFilter('account', 'acct_xxx');

// Build the JavaScript using the Client
$js = CRM_Svixclient_Client::buildFilter($filter);
```

### Generated JavaScript

Both methods generate a JavaScript filter function like:

```javascript
function handler(input) {
    if (input.account !== 'acct_xxx') return null;
    return { payload: input };
}
```

### Custom Filter Strategies

For complex filtering logic, implement `FilterStrategyInterface`:

```php
use Civi\Svixclient\Filter\FilterStrategyInterface;

class GoCardlessEventsFilter implements FilterStrategyInterface {

    private string $organisationId;

    public function __construct(string $organisationId) {
        $this->organisationId = $organisationId;
    }

    public function build(): string {
        $escaped = json_encode($this->organisationId);
        $escaped = substr($escaped, 1, -1); // Remove quotes

        return <<<JS
function handler(input) {
    const isForThisOrg = input.events?.some(
        event => event.links?.organisation === '{$escaped}'
    );
    if (!isForThisOrg) return null;
    return { payload: input };
}
JS;
    }
}

// Usage
$filter = new GoCardlessEventsFilter('OR000123');
$js = CRM_Svixclient_Client::buildFilter($filter);
$client->setTransformation($sourceId, $destinationId, $js);
```

### Why Strategy Pattern?

This design follows SOLID principles and prevents autoloading issues:

| Benefit | Description |
|---------|-------------|
| **Open/Closed** | Add new filter types without modifying Client |
| **No Inheritance Issues** | Payment extensions use composition, not inheritance |
| **Testable** | Each filter strategy can be unit tested independently |
| **Extensible** | Custom filters for complex webhook structures |

### Filter Script Requirements

The JavaScript filter function must:

1. Be named `handler` and accept an `input` parameter
2. Return `null` to reject the webhook (not for this destination)
3. Return `{ payload: input }` to accept and forward the webhook
4. Handle the specific webhook payload structure of your payment provider

### Security: String Escaping

The `buildRoutingFilter()` method automatically escapes values using `json_encode()` to prevent
JavaScript injection. This handles:

- Single quotes (`'` → `\'`)
- Backslashes (`\` → `\\`)
- Newlines, tabs, and other control characters
- Unicode characters

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
| signing_secret | string | Webhook signing secret for verification |

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

## Build Branches

This extension uses automated build branches that include the `vendor/` folder with all dependencies. This is required for Drupal makefile deployments.

### Branch Naming Pattern

| Source | Build Branch | Purpose |
|--------|--------------|---------|
| `master` | `master-build` | Production deployment |
| `feature-branch` | `feature-branch-build` | Testing before merge |

### How It Works

1. **PR opened/updated** → Creates `{branch-name}-build` for testing
2. **PR merged** → Updates `{base-branch}-build` (e.g., `master-build`)
3. **PR closed** → Deletes `{branch-name}-build` (cleanup)

### Usage in Makefile

```yaml
# Production (use master-build)
io.compuco.svixclient:
  download:
    type: "git"
    url: "git@github.com:compucorp/io.compuco.svixclient.git"
    branch: "master-build"
  destination: "modules/contrib/civicrm/ext"

# Testing a PR (use feature-branch-build)
io.compuco.svixclient:
  download:
    type: "git"
    url: "git@github.com:compucorp/io.compuco.svixclient.git"
    branch: "CIVIMM-454-feature-build"
  destination: "modules/contrib/civicrm/ext"
```

## License

AGPL-3.0
