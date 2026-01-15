# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

See [README.md](README.md) for full documentation including:
- Architecture and design
- Installation and configuration
- Usage examples and API reference
- Adding new payment processors
- Multi-site support

**Quick Summary:** CiviCRM extension providing Svix webhook client for payment extensions (Stripe, GoCardless). Enables 100+ sites to share a single Svix Ingest account.

## Build & Development Commands

```bash
# Install dependencies
composer install

# Run tests
phpunit9

# Run single test
phpunit9 --filter testMethodName tests/phpunit/Path/To/Test.php

# Run linter
./bin/vendor/bin/phpcs --standard=phpcs-ruleset.xml <file>

# Enable extension
cv ext:enable io.compuco.svixclient
```

## Key Files

| Component | Location |
|-----------|----------|
| Middleware Service | `Civi/Svixclient/Service/SvixWebhookMiddleware.php` |
| Processor Config | `Civi/Svixclient/Enum/SvixProcessorConfig.php` |
| Low-Level Client | `CRM/Svixclient/Client.php` |
| Filter Strategy | `Civi/Svixclient/Filter/` |
| Entity Schema | `schema/SvixDestination.entityType.php` |
| Tests | `tests/phpunit/` |

## CiviCRM Patterns

```php
// Settings
\Civi::settings()->get('svix_api_key')

// Logging
\Civi::log()->info('message', ['context' => $value]);
\Civi::log()->warning('message');
\Civi::log()->error('message');

// Exceptions
throw new CRM_Core_Exception('Error message');

// API4 (preferred over API3)
$result = \Civi\Api4\SvixDestination::get(FALSE)  // FALSE = bypass permissions
  ->addWhere('id', '=', $id)
  ->execute()
  ->first();
```

---

## Code Standards

- PHP 8.1+ with `declare(strict_types=1)`
- Drupal coding standard (see `phpcs-ruleset.xml`)
- Class naming: `CRM_Extension_Class` or `Civi\Extension\Class`

**Auto-Generated Files (Do Not Edit):**
- `svixclient.civix.php`
- `CRM/Svixclient/DAO/*.php`

---

## Security Guidelines

**Webhook Security:**
- Never log or expose API keys, webhook secrets, or signing keys
- Always verify webhook signatures before processing
- Validate webhook payload data before acting on it
- Use parameterized queries (prevent SQL injection)
- Sanitize user input (prevent XSS)

**Sensitive Data:**
- Credentials in `civicrm.settings.php` must never be committed
- Filter scripts may contain account identifiers - escape properly

---

## Commit Message Convention

**Format:**
```
CIVIMM-123: Short description of change
```

**Rules:**
- Keep under 72 characters
- Use present tense ("Add", "Fix", "Refactor")
- Include the issue key from branch name
- **NO AI attribution** (no "Co-Authored-By: Claude", no "Generated with...")

**Examples:**
```
CIVIMM-456: Add null check for destination lookup
CIVIMM-789: Fix filter script escaping
CIVIMM-101: Refactor Client to use dependency injection
```

---

## Handling PR Review Feedback

**NEVER blindly implement feedback.** Always think critically.

**Process:**
1. Analyze each suggestion - does it make technical sense?
2. Ask clarifying questions if unsure
3. Explain your analysis for each change
4. Get approval before implementing

**Red Flags - Stop and Ask:**
- Changes affecting database constraints
- Changes to type checking logic
- Suggestions contradicting architectural decisions
- "Consistency" arguments without technical justification

---

## Safety Checklist

- [ ] Run tests before committing
- [ ] Run linter before committing
- [ ] Never remove tests to make them pass
- [ ] Never commit files with API keys
- [ ] Never push without human review
- [ ] Always prefix commits with issue ID
