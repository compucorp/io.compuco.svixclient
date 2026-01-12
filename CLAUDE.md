# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**io.compuco.svixclient** is a CiviCRM extension providing a Svix webhook client for payment extensions (Stripe, GoCardless). It enables 100+ CiviCRM sites to share a single Svix Ingest platform account, routing webhooks to the correct site using JavaScript filters.

## Build & Development Commands

```bash
# Install dependencies
composer install

# Run tests
phpunit9

# Run linter (checks changed files against base branch)
./bin/vendor/bin/phpcs --standard=phpcs-ruleset.xml <file>

# Install linter tools
cd bin && ./install-php-linter

# Enable extension
cv ext:enable io.compuco.svixclient
```

## Architecture

### Core Components

1. **CRM_Svixclient_Client** (`CRM/Svixclient/Client.php`)
   - Main Svix API client
   - Methods: `createDestination()`, `deleteDestination()`, `getDestination()`, `verifyWebhook()`
   - API key from `\Civi::settings()->get('svix_api_key')` or `SVIX_API_KEY` env var
   - Uses Svix SDK for webhook signature verification only
   - Uses REST API (cURL) for Ingest destination management (no SDK support)

2. **SvixDestination Entity** (`schema/SvixDestination.entityType.php`)
   - Table: `civicrm_svix_destination`
   - Stores mapping between Svix destinations and CiviCRM payment processors
   - FK to payment_processor with CASCADE delete

3. **Filter Builders** (`CRM/Svixclient/FilterBuilder/`)
   - `FilterBuilderInterface` - contract for filter builders
   - `AbstractFilterBuilder` - base class with `escapeJsString()` helper (uses `json_encode` for secure JS escaping)
   - Payment extensions (Stripe, GoCardless) implement their own builders

4. **API4 Endpoints** (`Civi/Api4/`)
   - `SvixDestination` - DAOEntity for CRUD operations
   - `Svix::verifyWebhook()` - webhook signature verification

### Multi-Site Design

- **Shared across sites**: API key, source IDs (one per payment provider)
- **Unique per site**: Destinations, JS filters (based on account/org ID), webhook URLs

## CiviCRM Patterns

- **Settings**: Access via `\Civi::settings()->get('svix_api_key')`
- **Logging**: `\Civi::log()->info()`, `->warning()`, `->error()`
- **Exceptions**: Use `CRM_Core_Exception`
- **Class naming**: `CRM_Extension_Class` (PEAR-style PSR-0)
- **API4 permissions**: Check `CRM_Core_Permission::check()`

## Testing

- Framework: PHPUnit 9
- Bootstrap: `tests/phpunit/bootstrap.php`
- Base class: `BaseHeadlessTest` (headless + transactional)
- Test locations: `tests/phpunit/CRM/` and `tests/phpunit/Civi/`

Run a single test:
```bash
phpunit9 --filter testMethodName tests/phpunit/Path/To/Test.php
```

## Code Style

- PHP 8.3 required (`declare(strict_types=1)`)
- Drupal coding standard with CiviCRM exceptions (see `phpcs-ruleset.xml`)
- Excluded from linting: `*.civix.php`, `CRM/Svixclient/DAO/*`, `vendor/`, `mixin/`

---

## Critical Areas (Writing & Reviewing Code)

These guidelines apply when **writing new code** and **reviewing existing code**. Always consider these areas proactively.

### Security

**Webhook Security:**
- Never log or expose Svix API keys, webhook secrets, or signing keys
- Always verify webhook signatures before processing (`Svix::verifyWebhook()`)
- Validate all webhook payload data before acting on it
- Check for SQL injection in dynamic queries (use parameterized queries)
- Sanitize all user input before rendering (XSS prevention)
- Ensure proper authentication/authorization for API endpoints

**Sensitive Data Handling:**
- Svix API keys and webhook secrets are sensitive credentials
- All Svix API calls should use proper error handling to avoid exposing keys
- Credentials stored in `civicrm.settings.php` or env vars must never be committed
- Filter scripts may contain account identifiers - escape properly with `escapeJsString()`

### Performance

- Identify N+1 query issues in destination/processor lookups
- Avoid unnecessary Svix API calls (use cached destination records)
- Review database queries in BAO classes for optimization
- Batch destination operations where possible

### Code Quality

- Services should be focused and follow single responsibility principle
- Use meaningful names following CiviCRM conventions (`CRM_*` or `Civi\*`)
- Handle Svix API exceptions properly
- All service methods should have proper return type declarations
- Use dependency injection for service dependencies

---

## Commit Message Convention

All commits must start with the branch prefix (issue ID) followed by a short imperative description.

**Format:**
```
COMCL-123: Short description of change
```

**Rules:**
- Keep summaries under 72 characters
- Use present tense ("Add", "Fix", "Refactor")
- Claude must include the correct issue key when committing
- Be specific and descriptive
- **DO NOT add any AI attribution or co-authorship lines** (no "Generated with Claude Code", no "Co-Authored-By: Claude")

**Examples:**
```
COMCL-456: Add null check for destination lookup
COMCL-789: Fix filter script escaping for special characters
COMCL-101: Refactor Client class to use dependency injection
```

---

## Handling PR Review Feedback

When receiving PR review comments, **NEVER blindly implement feedback**. Always think critically.

**Required Process:**
1. **Analyze Each Suggestion:** Does it make technical sense? What are the implications?
2. **Ask Clarifying Questions:** If unsure about reasoning, ask the user
3. **Explain Your Analysis:** For each change, explain WHY you're making it (or not)
4. **Get Approval Before Implementing:** Show what you plan to change, wait for confirmation

**Red Flags - Stop and Ask Questions:**
- Changes that affect database constraints (NOT NULL, foreign keys)
- Changes to type checking logic (null checks, empty checks)
- Suggestions that contradict architectural decisions
- "Consistency" arguments without technical justification

---

## CiviCRM API Usage

**Prefer API4 over API3** for all new code.

```php
// ✅ PREFERRED: API4 with permission bypass for internal operations
$destination = \Civi\Api4\SvixDestination::get(FALSE)
  ->addSelect('id', 'svix_destination_id', 'payment_processor_id')
  ->addWhere('id', '=', $destinationId)
  ->execute()
  ->first();

// ❌ AVOID: API3 (legacy)
$destination = civicrm_api3('SvixDestination', 'getsingle', [
  'id' => $destinationId,
]);
```

**When to use API4 with `FALSE` (bypass permissions):**
- IPN/webhook handlers (anonymous context)
- Internal service operations
- Background processing jobs

---

## Safety & Best Practices

- Never commit code without running **tests** and **linting**
- Never remove or weaken tests to make them pass
- Always review Claude's suggestions before execution
- Always prefix commits with the issue ID (COMCL-###)
- Never push commits automatically without human review
- Never commit `civicrm.settings.php` or any file containing API keys
- Never modify auto-generated files (`svixclient.civix.php`, DAO classes) manually

**Auto-Generated Files (Do Not Edit Manually):**
- `svixclient.civix.php` (regenerate with civix)
- `CRM/Svixclient/DAO/*.php` (regenerate from XML schemas)
