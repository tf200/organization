# Developer Agent Guidelines - Organization App

This document provides instructions for agentic coding tools operating on the `organization` Nextcloud app.

## Repository Overview
The `organization` app manages multi-tenant entities, subscriptions, and plans.
- **Namespace:** `OCA\Organization`
- **Architecture:** MVC (Controllers -> Services -> Mappers -> Entities)
- **Nextcloud Version:** min 31, max 34

## Build, Lint, and Test Commands

See `CLAUDE.md` for the authoritative guide — build commands, the theme, and this app's deltas from its three siblings. `composer.json` and `phpunit.xml` both exist; PHP tests run inside the container because `nextcloud/ocp` ships no autoload.

### Linting & Static Analysis
- **Syntax Check:** `find lib -name "*.php" -exec php -l {} \;`
- **Psalm (Static Analysis):** `composer run psalm` (Requires `psalm.xml`)
- **Coding Standard Check:** `composer run cs:check` (Uses `nextcloud/coding-standard`)
- **Coding Standard Fix:** `composer run cs:fix`

### Testing
- **Run all tests:** `composer run test` (or `vendor/bin/phpunit`)
- **Run a single test file:** `vendor/bin/phpunit tests/Unit/Service/SubscriptionServiceTest.php`
- **Run a single test method:** `vendor/bin/phpunit --filter testCreateSubscription tests/Unit/Service/SubscriptionServiceTest.php`

*Note: If `tests/` or `composer.json` are missing, agents should propose creating them based on the `nextcloud/app_template` standards.*

## Coding Guidelines

### 1. General PHP Standards
- **Strict Types:** Every PHP file must begin with `declare(strict_types=1);`.
- **Indentation:** Use **4 spaces** for indentation.
- **PHP Version:** Target PHP 8.2+. Use modern features like constructor property promotion.

### 2. Naming Conventions
- **Classes:** `PascalCase` (e.g., `OrganizationMapper`).
- **Methods/Variables:** `camelCase` (e.g., `getSubscription`).
- **Database Tables:** `oc_organization_*` (snake_case).
- **Files:** Match class name (PSR-4).

### 3. Architecture & Patterns
- **Controllers:** Handle OCS/HTTP requests. Use PHP Attributes for metadata (e.g., `#[NoAdminRequired]`, `#[AuthorizedAdminSetting]`).
- **Services:** Contain business logic. Controllers should delegate to Services.
- **Mappers:** Handle all database interactions using `OCP\IDBConnection`. Avoid raw SQL in Services or Controllers.
- **Entities:** Simple data objects representing database rows, extending `OCP\AppFramework\Db\Entity`.
- **Dependency Injection:** Use constructor injection with promoted properties. Type-hint against OCP interfaces (e.g., `LoggerInterface`, `IRequest`).

### 4. Database & Migrations
- Use Nextcloud's migration system in `lib/Migration/`.
- Entities must match the schema defined in migrations.

### 5. Error Handling
- **API Errors:** Throw `OCP\AppFramework\OCS\OCSException` or `OCP\AppFramework\OCS\OCSNotFoundException`.
- **Logging:** Inject `Psr\Log\LoggerInterface` and log errors with context: `$this->logger->error('Message', ['exception' => $e]);`.
- **Transactions:** Wrap multi-step database operations in `$this->db->beginTransaction()` and `rollBack()` on failure.

### 6. Imports & Type Hinting
- Group imports into three blocks: `OCP` (Nextcloud core), `OCA\Organization` (Local), then internal PHP/PSR classes.
- Alphabetize imports within each group.
- Use native type hints for all parameters and return types where possible (PHP 8.2+).
- Use `mixed` sparingly; prefer specific types or union types.

### 7. Documentation (Docblocks)
- Use standard PHPDoc for methods, especially to document `@throws` exceptions.
- Specify array types in docblocks (e.g., `/** @return Subscription[] */`).
- Keep descriptions concise but informative.

### 8. Database Transactions
- For any operation modifying multiple tables (e.g., creating an organization AND a subscription), always use `$this->db->beginTransaction()` and `$this->db->commit()` / `$this->db->rollBack()`.
- Wrap the logic in a `try-catch` block to ensure `rollBack()` is called on failure.

## Business Context & Logic

### Entity Relationships
- **Organization (1) <-> (1) Subscription:** Every organization must have exactly one subscription (active or inactive).
- **Subscription (N) <-> (1) Plan:** Many subscriptions can share the same plan definitions.
- **Subscription (1) <-> (N) SubscriptionHistory:** Every change to a subscription's status or limits should be logged in the history table.

### Subscription Lifecycle
- **active:** The organization has full access.
- **paused:** The organization exists but access is restricted via middleware.
- **cancelled:** The subscription is marked for termination but may still be active until `ended_at`.
- **expired:** The `ended_at` date has passed; access is restricted.

### Limit Enforcement
The app tracks several key limits:
- `max_members`: Maximum number of users in the Nextcloud group.
- `max_projects`: App-specific project limit.
- `storage_quota`: Enforced at the organization level.

## Proactive Improvements
Agents should actively look for:
- **N+1 Query Problems:** Especially in controllers listing organizations. Use Mappers that perform JOINs or optimized subqueries.
- **Inconsistent Types:** Ensure integer IDs from the database are cast correctly using `(int)`.
- **Missing Log Context:** Always include the exception and relevant IDs in logger calls: `$this->logger->error('...', ['orgId' => $id, 'exception' => $e]);`.

### Subscription Enforcement
The `SubscriptionMiddleware` intercepts requests to routes that require an active subscription. It checks the status and expiration date of the organization's subscription before allowing access.

### Background Jobs
The `CheckExpiredSubscriptions` job runs periodically to mark subscriptions as `expired` if their `ended_at` date has passed.

## Rules & Intelligence
- **Cursor/Copilot:** No specific `.cursorrules` or `.github/copilot-instructions.md` found. Default to these guidelines.
- **Proactivity:** If you notice missing type hints or inconsistent docblocks, fix them. If a service is becoming too large, suggest refactoring into smaller domain services.

## Common File Paths
- **Routes:** `appinfo/routes.php`
- **App Bootstrap:** `lib/AppInfo/Application.php`
- **Business Logic:** `lib/Service/`
- **Database Layer:** `lib/Db/`
- **Background Jobs:** `lib/BackgroundJob/`
- **Templates:** `templates/` (use only for error pages or simple HTML)
