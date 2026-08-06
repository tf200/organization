# Organization Dashboard Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace this app's Nextcloud sidebar + list/detail layout with the single-column In Zicht dashboard the three sibling apps share, preserving every existing capability.

**Architecture:** One root `<div class="org-dashboard iz-app">` at `max-width: 1200px`, a page-level `.iz-tabs--display` bar with three tabs (Organizations, Plans, Trial defaults), and `.iz-panel--list` panels of `.iz-row--expandable` rows whose detail opens in place with its own `.iz-tabs`. All chrome comes from the theme's `.iz-*` primitives; the app keeps only layout CSS. `@nextcloud/vue` is dropped from the dashboard surface in favour of plain elements, because the primitives target plain elements and Nextcloud core explicitly excludes `.button-vue` from its own rules.

**Tech Stack:** Vue 3.5 (Composition API, `<script setup>`), Vite 7, TypeScript 5.9, `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/initial-state`, `@nextcloud/password-confirmation`. PHP 8.3 / Nextcloud 34 on the backend. In Zicht theme CSS is delivered server-wide, not as an npm package.

**Design spec:** `docs/superpowers/specs/2026-08-06-organization-dashboard-redesign-design.md` — read it first. The 147-row feature audit it references is the authority on what must survive.

---

## Global Constraints

- **Theme guide is canonical:** `/home/payboy/src/inzicht-nextcloud-theme/USING-THE-THEME.md`. Read before writing any style. Do not copy its content into this repo's `CLAUDE.md`.
- **`iz-app` on the root or nothing works.** `.iz-btn`, `.iz-input`, `.iz-select`, `.iz-chip`, `.iz-tab`, `.iz-close`, `.iz-textarea`, `.iz-user-picker__add` are all scoped `.iz-app .iz-X, element.iz-X`.
- **Chrome from the primitive, layout stays local.** Never hardcode a colour or a font size. Never put a layout property in a shared primitive.
- **Adding a primitive class without deleting the local rule does nothing.** Vue scoped CSS is specificity `(0,2,0)` — identical to `.iz-app .iz-input` — and app styles inject *after* the theme. On a tie the app wins. Verify against computed styles in the browser, not by reading the file.
- **Unscoped blocks are the inverse trap.** A bare class in an unscoped `<style>` is `(0,1,0)` and loses to the theme. Qualify it on a parent.
- **Nextcloud core fights bare elements** at `(0,1,1)`: `min-height: var(--default-clickable-area)` (34px), `padding: 7.5px 12px`, a `:focus` background repaint, and an `!important` focus outline. Use primitives, not bare `<button>`.
- **Check both colour schemes every time.** Simulate dark by setting `data-themes="dark"` and `data-theme-dark=""` on **both** `<body>` and `<html>`.
- **Reloading does not re-fetch the bundle or the theme CSS.** `fetch(url, {cache: 'reload'})` for each, then reload.
- **Bump `<version>` in `appinfo/info.xml` only when PHP changes** (`lib/`, `appinfo/`). This plan does change PHP, so it bumps once, in Task 16. Never bump for a frontend-only task.
- **Never change `<id>` in `appinfo/info.xml`.** It must stay `organization`. Production is the authority.
- **No `alert()` or `confirm()`.** Use `ConfirmDialog.vue` (Task 4). Parent owns the busy flag and the error string; the dialog never closes itself.
- **Commit per logical change, and say why** — including what was tried and rejected. Match the existing log's style.
- **Push only when asked.** `deploy.sh` does `git reset --hard origin/taha` against production at `root@185.169.252.206`.
- **`js/` and `css/` are committed build outputs.** Run `npm run build` and commit the result with each task that changes `src/`. The build is reproducible — a clean tree after building means source and bundle agree.
- **Never edit the deployed copy** inside the container or anything under `nextcloud-docker-dev/workspace/`.

### Verification gates

Every task ends with these. Exact commands, exact expectations.

| Gate | Command | Baseline today | Requirement |
|---|---|---|---|
| PHP syntax | `docker exec master-nextcloud-1 php -l /var/www/html/apps-shared/organization/<file>` | clean | stays clean |
| PHP tests | `docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 vendor/bin/phpunit` | 15 tests, 76 assertions, OK *(after Task 1)* | stays green |
| Types | `npx vue-tsc --noEmit` | **56 errors** | must decrease monotonically, **0 by Task 17** |
| Lint | `npx eslint src --ext .js,.vue,.ts` | **440 problems** (235 errors, 205 warnings) | must not increase; **new files must be clean** |
| Build | `npm run build` | exit 0, `js/organization-main.mjs` **1,225 kB** | stays green; size should fall as `@nextcloud/vue` leaves |
| Browser | manual, both schemes, 1200px and 1400px | app 500s until Task 1 | report measurements, not impressions |

**Environment.** Start `nc_pg` before `master-nextcloud-1` — booting Nextcloud without its database runs `maintenance:install` and overwrites `config.php`, losing `theme=inzicht`. This has happened three times.

```bash
docker start nc_pg && sleep 8
docker start master-redis-1 master-database-mysql-1
docker start master-nextcloud-1 master-proxy-1
```

App at `http://nextcloud.local:8080/index.php/apps/organization/`. Test users: `admin2` / `rootroot` (org admin of `Test`), and the Nextcloud admin account for the global-admin paths. Live DB is PostgreSQL in `nc_pg`, **not** `nc_db` and not `master-database-mysql-1` (which holds an empty skeleton of the same schema that reads as real until you notice every table has zero rows).

### Data on the dev instance

Orgs: `Test` (id 1, standard, admin `admin2`, subscription active to 2027-07-07, 3 members) and `testorg` (id 2, trial, admin `testadmin`, expired 2026-07-17, 2 members). Plans: `Custom Plan for Org 1` (10 members / 5 projects / 10 GiB / 50 GiB) and `Trial Plan — testorg` (3 / 1 / 100 MiB / 0), both private, both €0.00. 14 backup jobs, 3 handover jobs (all dry runs, all on org 1), 0 rollback jobs. `oc_appconfig` has no trial settings, so all six run on service defaults.

---

## File Structure

### Created

| File | Responsibility |
|---|---|
| `tests/bootstrap.php` | Load the Nextcloud server autoloader, then the app's. Three lines. |
| `phpunit.xml` | Point PHPUnit at `tests/Unit` with that bootstrap. |
| `tests/Unit/Middleware/SubscriptionMiddlewareTest.php` | Cover the NC34 public-route detection. |
| `src/styles/iz-app.scss` | The **one** unscoped global stylesheet. Root container + tab-bar layout only. No chrome. |
| `src/types.ts` | Shared interfaces — `TabKey`, `Organization`, `Member`, `Plan`. Must be a plain module: **`<script setup>` cannot contain `export` statements**, so types cannot live in an SFC. |
| `src/lib/format.ts` | `formatFileSize`, `formatDate`, `formatDateTime`. Replaces four divergent copies. |
| `src/lib/api.ts` | `ocs<T>()` wrapper: builds the OCS URL, unwraps `.ocs.data`, throws a typed `ApiError` carrying the server's message. |
| `src/composables/useAsync.ts` | `{ data, error, pending, run }` — the single source of loading and error state. |
| `src/components/ConfirmDialog.vue` | Vue 3 port of the vendored dialog. |
| `src/components/AppTabs.vue` | The three-tab bar, `localStorage`-persisted. |
| `src/components/organizations/OrgPanel.vue` | Panel, toolbar, search, two filters, row list, empty/loading. |
| `src/components/organizations/OrgRow.vue` | One expandable row: summary cells + detail tab host. |
| `src/components/organizations/tabs/OverviewTab.vue` | Contact, org settings, storage quotas, metrics. |
| `src/components/organizations/tabs/MembersTab.vue` | Segmented Current / Add existing / Create account. |
| `src/components/organizations/tabs/SubscriptionTab.vue` | Subscription fields, plan summary, convert entry point. |
| `src/components/plans/PlanPanel.vue` | Panel, toolbar, row list. |
| `src/components/plans/PlanRow.vue` | Expandable plan row + detail. |
| `src/components/trial/TrialDefaultsPanel.vue` | The six trial settings. |
| `src/components/ui/Pagination.vue` | `.iz-pagination` wrapper used by the backup and rollback lists. |
| `src/components/ui/Timeline.vue` | Event timeline, shared by backup events, rollback events and handover events. |

### Modified

`src/App.vue`, `src/main.ts`, `templates/index.php`, `lib/Middleware/SubscriptionMiddleware.php`, `lib/Controller/PageController.php`, `appinfo/info.xml`, `package.json`, and the five modals (`CreateOrgModal`, `EditOrganizationModal`, `ConvertTrialModal`, `CreatePlanModal`, `EditPlanModal`), plus `OrganizationBackup.vue` and `AccountHandover.vue`.

### Deleted

`src/components/AppNavigation.vue`, `src/views/OrganizationsView.vue`, `src/views/PlansView.vue`, `src/views/SettingsView.vue`, `src/components/Organizations/OrgList.vue`, `src/components/Organizations/OrgDetails.vue`, `src/components/Organizations/ManageMembersModal.vue`, `src/components/Plans/PlanList.vue`, `src/components/Plans/PlanDetails.vue`, `lib/Settings/AdminSection.php`, `lib/Settings/AdminSettings.php`, `templates/settings/admin.php`.

---

## Task 1: Unblock NC34 — fix `SubscriptionMiddleware`

Nothing can be verified in a browser until this lands. The app returns HTTP 500 on **every** route, including its own page.

**Files:**
- Create: `tests/bootstrap.php`, `phpunit.xml`, `tests/Unit/Middleware/SubscriptionMiddlewareTest.php`
- Modify: `lib/Middleware/SubscriptionMiddleware.php:121`

**Interfaces:**
- Consumes: nothing.
- Produces: a working app. Every later task depends on being able to load a page.

- [ ] **Step 1: Confirm the failure is real**

```bash
curl -s -u admin2:rootroot -H "OCS-APIRequest: true" \
  "http://nextcloud.local:8080/ocs/v2.php/apps/organization/organizations?format=json" | head -c 400
```

Expected: `"statuscode":996` and `Call to undefined method OC\AppFramework\Utility\ControllerMethodReflector::hasAnnotationOrAttribute()`.

- [ ] **Step 2: Add the PHPUnit bootstrap**

`nextcloud/ocp` is a psalm-stubs package with **no autoload declaration** — the real `OCP\*` classes come from the server at runtime. Without this, 7 of the 15 existing tests error with `Class or interface "OCP\IConfig" does not exist`.

Create `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../vendor/autoload.php';
```

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 3: Verify the existing suite now passes**

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 vendor/bin/phpunit
```

Expected: `OK (15 tests, 76 assertions)`.

If `vendor/` is missing, install it first — the container has PHP 8.2 and `composer.json` requires `^8.3`, so the platform check must be skipped:

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 \
  composer install --no-interaction --ignore-platform-req=php
```

Add `/vendor/` and `/.phpunit.cache/` to `.gitignore` if not already present.

- [ ] **Step 4: Write the failing test**

Create `tests/Unit/Middleware/SubscriptionMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Middleware;

use OCA\Organization\Middleware\SubscriptionMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;

class PublicProbeController extends Controller {
	#[PublicPage]
	public function open(): void {}

	public function closed(): void {}
}

class SubscriptionMiddlewareTest extends TestCase {
	private function isPublicRoute(object $controller, string $method): bool {
		$reflection = new \ReflectionMethod(SubscriptionMiddleware::class, 'isPublicRoute');
		$reflection->setAccessible(true);
		// The middleware's other collaborators are unused on this path.
		$middleware = (new \ReflectionClass(SubscriptionMiddleware::class))
			->newInstanceWithoutConstructor();
		return $reflection->invoke($middleware, $controller, $method);
	}

	public function testMethodMarkedPublicPageIsTreatedAsPublic(): void {
		$controller = (new \ReflectionClass(PublicProbeController::class))
			->newInstanceWithoutConstructor();
		$this->assertTrue($this->isPublicRoute($controller, 'open'));
	}

	public function testMethodWithoutPublicPageIsNotPublic(): void {
		$controller = (new \ReflectionClass(PublicProbeController::class))
			->newInstanceWithoutConstructor();
		$this->assertFalse($this->isPublicRoute($controller, 'closed'));
	}
}
```

- [ ] **Step 5: Run it and watch it fail**

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 \
  vendor/bin/phpunit --filter SubscriptionMiddlewareTest
```

Expected: FAIL — `Call to undefined method ControllerMethodReflector::hasAnnotationOrAttribute()`, or a `$this->reflector` null error, depending on which line is reached first.

- [ ] **Step 6: Apply the fix**

In `lib/Middleware/SubscriptionMiddleware.php`, replace the body of `isPublicRoute()`'s first check. NC34's `ControllerMethodReflector` exposes only `hasAnnotation()` and `getAnnotationParameter()`; `hasAnnotationOrAttribute()` was removed. Read the attribute natively instead, which also removes the dependency on the reflector having been primed by `beforeController()`.

```php
private function isPublicRoute($controller, $methodName): bool
{
    // NC34 dropped ControllerMethodReflector::hasAnnotationOrAttribute().
    // Read the attribute directly — it does not depend on reflector state.
    try {
        $method = new \ReflectionMethod($controller, $methodName);
        if ($method->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class) !== []) {
            return true;
        }
    } catch (\ReflectionException) {
        // Unknown method: fall through to the checks below rather than 500.
    }

    if (
        $controller instanceof LoginController &&
        in_array($methodName, ['showLoginForm', 'login', 'tryLogin'])
    ) {
        return true;
    }
    $pathInfo = $this->request->getPathInfo();
    if (in_array($pathInfo, ['/logout', '/index.php/logout'])) {
        return true;
    }
    return false;
}
```

Note the `catch (\ReflectionException)` — without it an unknown method turns a 404 into a 500.

- [ ] **Step 7: Run the tests**

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 vendor/bin/phpunit
```

Expected: `OK (17 tests, ...)` — the 15 existing plus the 2 new.

- [ ] **Step 8: Verify every route recovers**

```bash
for ep in "organizations" "organizations/1" "backups/jobs/my-organization"; do
  printf "%s  /%s\n" \
    "$(curl -s -o /dev/null -w '%{http_code}' -u admin2:rootroot -H 'OCS-APIRequest: true' \
       "http://nextcloud.local:8080/ocs/v2.php/apps/organization/$ep?format=json")" "$ep"
done
curl -s -o /dev/null -w "%{http_code}  page\n" -u admin2:rootroot \
  "http://nextcloud.local:8080/index.php/apps/organization/"
```

Expected: `200` for all four. A `403` on `plans` or `admin/settings/trial` for `admin2` is **correct** — those controllers are Nextcloud-admin-only and `admin2` is not one.

- [ ] **Step 9: Confirm `adminpage`'s 500 clears**

Load `http://nextcloud.local:8080/index.php/apps/adminpage/` as a user who is an org admin and confirm the backup-jobs panel populates instead of erroring. Record what you actually observed.

- [ ] **Step 10: Commit**

```bash
git add tests/ phpunit.xml lib/Middleware/SubscriptionMiddleware.php .gitignore
git commit -m "fix: restore NC34 compatibility in SubscriptionMiddleware

ControllerMethodReflector::hasAnnotationOrAttribute() no longer exists in
Nextcloud 34; the class exposes only hasAnnotation() and
getAnnotationParameter(). Because the middleware is registered for this
app's container, the undefined-method fatal turned every route into a 500,
including the app's own page — the app was completely unreachable.

Read the PublicPage attribute via native reflection instead. That also drops
the dependency on the reflector having been primed by beforeController(),
and the ReflectionException guard stops an unknown method turning a 404
into a 500.

Also wires up PHPUnit, which had config but no bootstrap. nextcloud/ocp is a
psalm-stubs package with no autoload declaration, so the real OCP classes
have to come from the server — 7 of the 15 existing tests were erroring on
'Class or interface OCP\\IConfig does not exist'. All 15 pass now."
```

**Do not bump `<version>` yet.** Task 16 does it once for all PHP changes.

---

## Task 2: Capture the before-state

Cheap, and the only chance to record what is being replaced.

**Files:** none.

- [ ] **Step 1: Screenshot the current UI in both schemes**

Load `/index.php/apps/organization/` as the Nextcloud admin. Capture: the organizations list, an expanded org detail, the members modal on each of its four tabs, the backups panel, the plans list, and the trial settings view.

Then set `data-themes="dark"` and `data-theme-dark=""` on both `<body>` and `<html>` and repeat.

- [ ] **Step 2: Record the baselines**

```bash
npx vue-tsc --noEmit 2>&1 | grep -c "error TS"   # expect 56
npx eslint src --ext .js,.vue,.ts 2>&1 | tail -3  # expect 440 problems
npm run build && ls -l js/organization-main.mjs   # expect ~1,225 kB
```

Write the three numbers into the task notes. Task 17 checks against them.

- [ ] **Step 3: No commit** — nothing changed.

---

## Task 3: The shell — `iz-app`, the tab bar, and the global stylesheet

Produces a page that renders three empty tabs on the theme. Everything after this fills them in.

**Files:**
- Create: `src/types.ts`, `src/styles/iz-app.scss`, `src/components/AppTabs.vue`
- Modify: `src/App.vue`, `src/main.ts`, `templates/index.php`
- Delete: `src/components/AppNavigation.vue`

**Interfaces:**
- Produces: `src/types.ts` exporting `type TabKey = 'organizations' | 'plans' | 'trial'` plus the `Organization`, `Member` and `Plan` interfaces that Tasks 6–13 consume. `AppTabs` takes `{ modelValue: TabKey; counts: Partial<Record<TabKey, number>> }` and emits `update:modelValue`.

**Why a separate types module:** Vue's compiler rejects `export` statements inside `<script setup>`, so a type declared in an SFC cannot be imported by a sibling. Everything shared lives in `src/types.ts`.

- [ ] **Step 1: Add the global stylesheet**

Create `src/styles/iz-app.scss`. **Layout only** — no colours, no font sizes, no borders. Chrome comes from the theme.

```scss
/* The one unscoped stylesheet in this app.
   Layout only: the theme owns all chrome. Anything here that sets a colour,
   a font-size, a border or a shadow is a bug — use an .iz-* primitive. */

.org-dashboard {
  background: var(--bg-page);
  max-width: 1200px;
  margin: 0 auto;
  padding: var(--spacing-lg);
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  color: var(--color-text-primary);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

/* Nextcloud pads #app-content; the dashboard does its own padding. */
#content:has(.org-dashboard) {
  background: var(--image-background);
}
```

Chosen over `adminpage`'s and `employee_dashboard`'s per-panel `margin-bottom` because flex `gap` cannot drift — theirs already has (`ProjectsMapWidget` uses `--spacing-lg` where its siblings use `--spacing-xl`).

- [ ] **Step 2: Import it once, from `main.ts`**

```ts
import { createApp } from 'vue'
import '@nextcloud/password-confirmation/style.css'
import './styles/iz-app.scss'
import App from './App.vue'

createApp(App).mount('#content')
```

- [ ] **Step 3: Put `iz-app` on the server-rendered root**

`src/App.vue` currently renders a **fragment** — `AppNavigation` plus one view as siblings — so there is nowhere in the component tree to hang the class. Belt and braces: put it on the mount target too.

`templates/index.php`:

```php
<div id="content" class="iz-app"></div>
```

- [ ] **Step 4: Create `src/types.ts`**

```ts
export type TabKey = 'organizations' | 'plans' | 'trial'

export interface Member {
  uid: string
  displayName: string
  email?: string
  role: 'admin' | 'member' | string
}

export interface Plan {
  id: number
  name: string
  maxMembers: number
  maxProjects: number
  sharedStoragePerProject: number
  privateStoragePerUser: number
  price: number
  currency: string
  isPublic: boolean
  subscriptionCount?: number
}

export interface Organization {
  id: number
  displayname: string
  type: 'standard' | 'trial'
  adminUid: string
  usercount: number
  contactFirstName?: string
  contactLastName?: string
  contactEmail?: string
  contactPhone?: string
  canAdd?: boolean
  canRemove?: boolean
  subscription: {
    id: number
    status: string
    planName?: string
    maxMembers: number
    maxProjects: number
    startedAt?: string
    endedAt?: string
  }
  plan?: Pick<Plan, 'sharedStoragePerProject' | 'privateStoragePerUser'>
  members?: Member[]
}
```

- [ ] **Step 5: Write `AppTabs.vue`**

```vue
<script setup lang="ts">
import type { TabKey } from '../types'

defineProps<{
  modelValue: TabKey
  counts: Partial<Record<TabKey, number>>
}>()
const emit = defineEmits<{ 'update:modelValue': [TabKey] }>()

const TABS: { key: TabKey; label: string }[] = [
  { key: 'organizations', label: 'Organizations' },
  { key: 'plans', label: 'Plans' },
  { key: 'trial', label: 'Trial defaults' },
]

const STORAGE_KEY = 'organization:activeTab'

function select(key: TabKey) {
  emit('update:modelValue', key)
  window.localStorage.setItem(STORAGE_KEY, key)
}
</script>

<template>
  <nav class="iz-tabs iz-tabs--display" role="tablist">
    <button
      v-for="tab in TABS"
      :key="tab.key"
      class="iz-tab"
      :class="{ 'iz-tab--active': modelValue === tab.key }"
      type="button"
      role="tab"
      :aria-selected="modelValue === tab.key"
      @click="select(tab.key)">
      {{ tab.label }}
      <span v-if="counts[tab.key] !== undefined" class="iz-tab__count">{{ counts[tab.key] }}</span>
    </button>
  </nav>
</template>
```

No `<style>` block. The tab bar is entirely theme chrome.

Note `.iz-tabs--display` uppercases the labels via `text-transform`, so write them in sentence case.

- [ ] **Step 6: Rewrite `App.vue`**

Superadmin-only, so the permission branching, the mode chip and the `showPlans` gate all go.

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AppTabs from './components/AppTabs.vue'
import type { TabKey } from './types'

const VALID: TabKey[] = ['organizations', 'plans', 'trial']
const activeTab = ref<TabKey>('organizations')

onMounted(() => {
  const stored = window.localStorage.getItem('organization:activeTab')
  if (stored && (VALID as string[]).includes(stored)) {
    activeTab.value = stored as TabKey
  }
})
</script>

<template>
  <div class="org-dashboard iz-app">
    <AppTabs v-model="activeTab" :counts="{}" />
    <section v-if="activeTab === 'organizations'">Organizations</section>
    <section v-else-if="activeTab === 'plans'">Plans</section>
    <section v-else>Trial defaults</section>
  </div>
</template>
```

- [ ] **Step 7: Delete the sidebar**

```bash
git rm src/components/AppNavigation.vue
```

- [ ] **Step 8: Build and verify**

```bash
npm run build
npx vue-tsc --noEmit 2>&1 | grep -c "error TS"
```

Expected: build green; type errors **unchanged or lower** (the views still exist).

- [ ] **Step 9: Verify in the browser, both schemes**

Reload with cache busting, then check in dev tools that the root element carries `iz-app` and that `getComputedStyle` on it resolves `--bg-card` to a real colour. **If a token resolves to empty, the bridge is not applied** — an undefined custom property invalidates the whole declaration silently rather than falling back.

Confirm the three tabs render with the theme's underline treatment, that the active one is accented in both schemes, and that the choice survives a reload.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: single-column iz-app shell with a three-tab bar

Replaces NcAppNavigation with the page-level .iz-tabs--display bar the
sibling apps use, and puts iz-app on the root so the theme's token bridge
and its .iz-app-scoped primitives apply at all — there was no iz-app
anywhere on this branch, so every primitive would have been inert.

Container uses flex + gap rather than the per-panel margin-bottom adminpage
and employee_dashboard use; theirs has already drifted between --spacing-lg
and --spacing-xl. iz-app.scss is deliberately layout-only.

Tab choice persists to localStorage, matching superadminpage."
```

---

## Task 4: `ConfirmDialog.vue` — the Vue 3 port

Needed by every destructive action in later tasks. The sibling apps' copy is a Vue 2 SFC and cannot be reused as-is.

**Files:** Create `src/components/ConfirmDialog.vue`

**Interfaces:**
- Produces: props `{ title: string; message?: string; confirmLabel?: string; cancelLabel?: string; busyLabel?: string; danger?: boolean; alertOnly?: boolean; busy?: boolean; error?: string }`; emits `confirm` and `cancel`.

**The contract, which is the whole point:** the parent owns `busy` and `error`, and **the dialog never closes itself**. A failed action stays open with its reason attached. It also refuses to cancel while `busy`.

- [ ] **Step 1: Write it**

```vue
<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'

const props = withDefaults(defineProps<{
  title: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  busyLabel?: string
  danger?: boolean
  alertOnly?: boolean
  busy?: boolean
  error?: string
}>(), {
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  busyLabel: 'Working…',
  danger: false,
  alertOnly: false,
  busy: false,
})

const emit = defineEmits<{ confirm: []; cancel: [] }>()
const confirmButton = ref<HTMLButtonElement | null>(null)

function cancel() {
  if (props.busy) return          // never abandon an in-flight action
  emit('cancel')
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') cancel()
}

onMounted(() => {
  confirmButton.value?.focus()
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="iz-modal-backdrop" @click.self="cancel">
    <div class="iz-modal confirm-dialog" role="dialog" aria-modal="true">
      <div class="iz-modal__header">
        <h4 class="iz-panel__title">{{ title }}</h4>
        <button class="iz-close iz-close--sm" type="button" :disabled="busy"
                aria-label="Close" @click="cancel">&times;</button>
      </div>
      <div class="iz-modal__body">
        <p v-if="message" class="iz-modal__confirm-text">{{ message }}</p>
        <slot />
        <div v-if="error" class="iz-error">{{ error }}</div>
      </div>
      <div class="iz-modal__footer">
        <button v-if="!alertOnly" class="iz-btn" type="button" :disabled="busy" @click="cancel">
          {{ cancelLabel }}
        </button>
        <button ref="confirmButton" type="button"
                class="iz-btn" :class="danger ? 'iz-btn--danger' : 'iz-btn--primary'"
                :disabled="busy" @click="emit('confirm')">
          <span v-if="busy" class="iz-spinner"></span>
          {{ busy ? busyLabel : confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Layout only — the theme sets no width on .iz-modal by design. */
.confirm-dialog { max-width: 460px; width: 100%; }
</style>
```

- [ ] **Step 2: Type-check and lint the new file**

```bash
npx vue-tsc --noEmit 2>&1 | grep ConfirmDialog || echo "clean"
npx eslint src/components/ConfirmDialog.vue
```

Expected: both clean. **New files must be lint-clean** even though the repo has 440 pre-existing problems.

- [ ] **Step 3: Commit**

```bash
git add src/components/ConfirmDialog.vue
git commit -m "feat: Vue 3 ConfirmDialog carrying the vendored contract

The sibling apps share a Vue 2 SFC that a Vue 3 app cannot import, so this
is a port rather than a copy. Contract preserved exactly: the parent owns
busy and error, the dialog never closes itself, and it refuses to cancel
while busy — so a failed action stays open with its reason attached instead
of vanishing.

Replaces window.confirm before the real account handover, and adds the
confirmation that Apply Rollback has never had despite restoring a database
and files."
```

---

## Task 5: Shared plumbing — API, async state, formatting

Removes four divergent `formatFileSize` copies and gives every later task one way to surface an error.

**Files:** Create `src/lib/format.ts`, `src/lib/api.ts`, `src/composables/useAsync.ts`

**Interfaces:**
- Produces:
  - `formatFileSize(bytes: number | null | undefined): string`
  - `formatDateTime(raw: string | null | undefined): string`
  - `ocs<T>(path: string, init?: OcsInit): Promise<T>` throwing `ApiError`
  - `class ApiError extends Error { readonly status: number }`
  - `useAsync<T>(fn: () => Promise<T>)` → `{ data, error, pending, run }`

- [ ] **Step 1: `src/lib/format.ts`**

Two copies of `formatFileSize` exist today with different guards — `OrgDetails.vue` guards only `bytes === 0` and renders `NaN undefined` for null; `ConvertTrialModal.vue` guards `!bytes || bytes === 0`. Take the safe one.

```ts
const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const

/** Binary divisors with the decimal labels the existing UI uses. */
export function formatFileSize(bytes: number | null | undefined): string {
  if (!bytes || bytes <= 0) return '0 B'
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return `${parseFloat((bytes / Math.pow(1024, i)).toFixed(2))} ${UNITS[i]}`
}

/**
 * The server emits 'Y-m-d H:i:s' in UTC with no trailing Z, which browsers
 * parse as local time. Normalise before formatting or every timestamp is
 * silently offset.
 */
export function formatDateTime(raw: string | null | undefined): string {
  if (!raw) return '—'
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
    ? raw.replace(' ', 'T') + 'Z'
    : raw
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

/** Date only, for expiry and subscription bounds. */
export function formatDate(raw: string | null | undefined): string {
  if (!raw) return '—'
  return formatDateTime(raw).replace(/,? \d{2}:\d{2}$/, '')
}
```

The UTC normalisation is a fix: `OrganizationBackup.vue`'s `formatDate` omits it, so every backup timestamp is currently shifted by the viewer's offset.

- [ ] **Step 2: `src/lib/api.ts`**

```ts
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message)
    this.name = 'ApiError'
  }
}

type OcsInit = {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  params?: Record<string, unknown>
  body?: Record<string, unknown>
  /** Send as application/x-www-form-urlencoded — the backup endpoints expect it. */
  form?: boolean
}

/**
 * Unwraps ocs.data and turns every failure into an ApiError carrying the
 * server's own message. Nothing in this app used to surface those: twenty-plus
 * call sites failed into console.error and the user just watched a spinner stop.
 */
export async function ocs<T>(path: string, init: OcsInit = {}): Promise<T> {
  const { method = 'GET', params, body, form = false } = init
  try {
    const { data } = await axios.request({
      url: generateOcsUrl(`apps/organization/${path}`),
      method,
      params,
      data: form && body
        ? new URLSearchParams(body as Record<string, string>)
        : body,
      headers: form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : undefined,
    })
    return data?.ocs?.data as T
  } catch (e: unknown) {
    // @nextcloud/password-confirmation rejects with the string 'cancelled'.
    if (e === 'cancelled') throw new ApiError('Password confirmation was cancelled.', 0)
    const err = e as { response?: { status?: number; data?: { ocs?: { meta?: { message?: string } } } } }
    throw new ApiError(
      err.response?.data?.ocs?.meta?.message || 'The server could not complete that request.',
      err.response?.status ?? 0,
    )
  }
}
```

- [ ] **Step 3: `src/composables/useAsync.ts`**

```ts
import { ref, shallowRef } from 'vue'
import { ApiError } from '../lib/api'

export function useAsync<T>(fn: (...args: never[]) => Promise<T>) {
  const data = shallowRef<T | null>(null)
  const error = ref<string>('')
  const pending = ref(false)

  async function run(...args: never[]): Promise<T | null> {
    pending.value = true
    error.value = ''
    try {
      const result = await fn(...args)
      data.value = result
      return result
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : String(e)
      return null
    } finally {
      pending.value = false   // buttons always re-enable — preserved behaviour
    }
  }

  return { data, error, pending, run }
}
```

`finally { pending = false }` is deliberate preservation: today's code always re-enables on failure. What changes is that `error` is now rendered.

- [ ] **Step 4: Verify**

```bash
npx vue-tsc --noEmit 2>&1 | grep -E "lib/(format|api)|composables" || echo "clean"
npx eslint src/lib src/composables
```

Expected: both clean.

- [ ] **Step 5: Commit**

```bash
git add src/lib src/composables
git commit -m "feat: shared api, async-state and formatting helpers

One formatFileSize replaces four copies that disagreed on null handling —
OrgDetails guarded only bytes===0 and rendered 'NaN undefined'.

formatDateTime normalises the server's 'Y-m-d H:i:s' UTC strings, which have
no trailing Z and so were being parsed as local time; every backup timestamp
in the UI is currently offset by the viewer's zone.

ocs() turns failures into an ApiError carrying the server's own message.
Twenty-plus call sites currently swallow those into console.error, including
'Cannot delete plan: it is used by N subscriptions', so the user clicks and
nothing visible happens. useAsync keeps the existing always-re-enable
behaviour and adds the error surface."
```

---

## Task 6: Organizations panel and rows

**Files:**
- Create: `src/components/organizations/OrgPanel.vue`, `src/components/organizations/OrgRow.vue`
- Modify: `src/App.vue`
- Delete: `src/components/Organizations/OrgList.vue`

**Interfaces:**
- Consumes: `ocs`, `useAsync`, `formatDate`, `formatFileSize`.
- Produces: `OrgPanel` emits nothing; owns its own fetch. `OrgRow` props `{ org: Organization; expanded: boolean }`, emits `toggle`.
- Type, defined here and used by Tasks 7–9 and 14:

```ts
export interface Organization {
  id: number
  displayname: string
  type: 'standard' | 'trial'
  adminUid: string
  usercount: number
  contactFirstName?: string
  contactLastName?: string
  contactEmail?: string
  contactPhone?: string
  canAdd?: boolean
  canRemove?: boolean
  subscription: {
    id: number
    status: string
    planName?: string
    maxMembers: number
    maxProjects: number
    startedAt?: string
    endedAt?: string
  }
  plan?: { sharedStoragePerProject: number; privateStoragePerUser: number }
  members?: Member[]
}

export interface Member {
  uid: string
  displayName: string
  email?: string
  role: 'admin' | 'member' | string
}
```

- [ ] **Step 1: Write `OrgRow.vue`'s summary**

The theme's expandable row has exactly one sanctioned design — chevron **on the right, last element**, chevron-down rotating 180°, muted → accent on hover and while open. Expandable rows deliberately do **not** take the −4px card lift; that reads as "navigates away" and these open in place.

```vue
<template>
  <article class="iz-row iz-row--card iz-row--expandable"
           :class="{ 'iz-row--expanded': expanded }">
    <div class="iz-row__header" role="button" tabindex="0"
         :aria-expanded="expanded"
         @click="emit('toggle')"
         @keydown.enter.prevent="emit('toggle')"
         @keydown.space.prevent="emit('toggle')">
      <span class="iz-identity__avatar"
            :class="{ 'iz-identity__avatar--soft': org.type === 'trial' }">
        {{ org.displayname.charAt(0) }}
      </span>
      <div class="org-row__ident">
        <b>{{ org.displayname }}</b>
        <span>ID {{ org.id }} · {{ org.subscription.planName || 'Custom' }} · {{ org.adminUid }}</span>
      </div>
      <div class="org-row__seats">
        <span>{{ org.usercount }} / {{ org.subscription.maxMembers }} members</span>
        <div class="iz-meter">
          <div class="iz-meter__fill" :class="seatTone" :style="{ width: seatPct + '%' }"></div>
        </div>
      </div>
      <div class="iz-row__actions">
        <span class="iz-badge" :class="org.type === 'trial' ? 'iz-badge--cat-5' : 'iz-badge--muted'">
          {{ org.type === 'trial' ? 'Trial' : 'Standard' }}
        </span>
        <span class="iz-pill" :class="statusTone">
          <span class="iz-dot"></span>{{ statusLabel }}
        </span>
        <svg class="iz-row__chevron" :class="{ 'iz-row__chevron--open': expanded }"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </div>
    </div>
    <div v-if="expanded" class="iz-row__detail"><slot /></div>
  </article>
</template>
```

- [ ] **Step 2: Map status through an explicit table, never build a class from data**

`'prefix--' + row.status` silently emits a class that may not exist. Map with a neutral fallback:

```ts
const STATUS_TONE: Record<string, string> = {
  active: 'iz-pill--success',
  expired: 'iz-pill--danger',
  cancelled: 'iz-pill--warning',
  paused: 'iz-pill--warning',
}
const statusTone = computed(() => STATUS_TONE[props.org.subscription.status] ?? 'iz-pill--muted')
const statusLabel = computed(() => {
  const s = props.org.subscription.status
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Unknown'
})

const seatPct = computed(() => {
  const max = Number(props.org.subscription.maxMembers) || 0
  if (max <= 0) return 0                       // today this renders NaN%
  return Math.min((props.org.usercount / max) * 100, 100)
})
const seatTone = computed(() =>
  seatPct.value >= 100 ? 'iz-meter__fill--danger'
  : seatPct.value >= 80 ? 'iz-meter__fill--warn'
  : 'iz-meter__fill--ok')
```

The `max <= 0` guard is a fix — `OrgDetails.vue` currently computes `NaN%` when `maxMembers` is 0.

- [ ] **Step 3: Write `OrgPanel.vue`**

Panel header with count and create action, toolbar with search plus the two new filters, then the rows. Use `.iz-panel--list` — **not** `.iz-panel`. Every panel that pads its own header and body is the `--list` case; the base class double-pads it (20 + 16) and insets the header's hover tint away from the card edge.

Search must match `displayname` **and** the stringified `id`, matching today's behaviour. Filters are new and client-side.

- [ ] **Step 4: Distinguish "no organizations" from "no matches"**

Today one empty state serves both, so a filtered-out list says "Get started by creating a new organization."

```vue
<div v-if="pending" class="org-panel__state"><span class="iz-spinner iz-spinner--lg"></span></div>
<div v-else-if="error" class="iz-error">
  {{ error }}
  <button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="load">Try again</button>
</div>
<div v-else-if="organizations.length === 0" class="iz-empty">
  No organizations yet. Create one to get started.
</div>
<div v-else-if="filtered.length === 0" class="iz-empty">
  No organizations match “{{ search }}”.
  <button class="iz-btn iz-btn--plain iz-btn--sm" type="button" @click="clearFilters">Clear filters</button>
</div>
```

- [ ] **Step 5: Wire into `App.vue`, delete `OrgList.vue`**

```bash
git rm src/components/Organizations/OrgList.vue
```

- [ ] **Step 6: Verify**

```bash
npm run build
npx vue-tsc --noEmit 2>&1 | grep -c "error TS"    # must be < 56
npx eslint src/components/organizations
```

- [ ] **Step 7: Browser check, both schemes**

Confirm: both real orgs render; `Test` shows `3 / 10 members` with an ok-toned meter and an Active pill; `testorg` shows `2 / 3`, a Trial badge and an Expired pill. Search `testorg`, then search `1` and confirm the ID match still works. Expand and collapse; confirm the chevron rotates 180° and turns accent, and that the row does **not** lift on hover.

Then open dev tools and compare the theme's `.iz-row--card` declarations against anything the component sets on the same element. **Any overlap is a leftover scoped rule that will silently win.**

- [ ] **Step 8: Commit**

---

## Task 7: Overview tab

**Files:** Create `src/components/organizations/tabs/OverviewTab.vue`

**Interfaces:** Consumes `Organization` from Task 6, `formatFileSize` from Task 5.

- [ ] **Step 1: Build the three fieldsets and the metrics strip**

Preserve exactly: `contactFullName` from first + last trimmed with `'Not set'` when empty; `mailto:` and `tel:` links or `'Not set'`; the monospace organization ID; both permission tags always rendered (`Add users` / `Cannot add users`, `Remove users` / `Cannot remove users`); storage quotas via `formatFileSize`; and the `Expires: <date> | Never` line on the plan metric.

- [ ] **Step 2: Replace the `'Loading...'` string placeholder**

Today storage renders the literal `'Loading...'` until `organization.plan` arrives. Use `.iz-state` instead.

- [ ] **Step 3: Drop the decorative progress bar**

The Projects Limit bar is hardcoded `style="width: 100%"`. A full bar beside a number reads as "at capacity". The number stands alone.

- [ ] **Step 4–6:** Verify (type-check, lint, browser both schemes against org 1's real contact data — `fgasdf fasdf`, `fasd@gmail.com`, `42345234`), then commit.

---

## Task 8: Members tab

Absorbs three of `ManageMembersModal`'s four tabs into an `.iz-segment`.

**Files:**
- Create: `src/components/organizations/tabs/MembersTab.vue`
- Delete: `src/components/Organizations/ManageMembersModal.vue`

- [ ] **Step 1: Preserve the seat maths exactly**

```ts
const maxMembers = computed(() => Number(props.org.subscription?.maxMembers || 0))
const seatsLeft = computed(() => Math.max(maxMembers.value - members.value.length, 0))
```

`seatsLeft` gates: the Add-existing and Create-account segments (hidden at 0), the warning tone at `<= 3`, and the banner *"Member limit reached. Upgrade subscription to add more members."* at 0.

- [ ] **Step 2: Add-existing mode — preserve the 300 ms debounce and the client-side filter**

`GET organizations/{id}/available-users?search=` then remove anyone already a member via a `Set` of existing uids. Keep the *"No users found matching “X”"* message. Build it on `.iz-user-picker`.

- [ ] **Step 3: Make results keyboard-reachable**

Today they are `<div>`s with `@click` — no `role`, no `tabindex`, no Enter handling. Use real `<button>` elements inside `.iz-user-picker__result`.

- [ ] **Step 4: Create-account mode**

Four fields — `User ID *`, `Password *`, `Display Name`, `Email` — with the same validation and the same `null`-for-blank payload behaviour. Submit disabled until both required fields have non-whitespace content.

- [ ] **Step 5: Per-row busy state, not one shared flag**

Today a single `loading` ref disables every remove button and both submits at once. Key the busy state by uid.

- [ ] **Step 6: Disable the admin's remove button rather than hiding it**

`v-if="member.role !== 'admin'"` reads as a missing feature. Render it disabled with `title="Organization admins cannot be removed"`.

- [ ] **Step 7: Confirm before removing**

Removal is destructive and unlogged, and today has no confirmation at all. Use `ConfirmDialog`.

- [ ] **Step 8–10:** Verify against org 2 (2/3 members, so `seatsLeft === 1` and the pill is warning-toned), lint, commit.

---

## Task 9: Subscription tab

**Files:** Create `src/components/organizations/tabs/SubscriptionTab.vue`

- [ ] **Step 1:** Render Subscription ID (monospace), Status, Started, Ended with the `'No end date'` fallback, and the plan's four limits.
- [ ] **Step 2:** Surface an `.iz-error` banner when the subscription is expired.
- [ ] **Step 3:** Host the Convert-to-standard button, gated on `org.type === 'trial'`.
- [ ] **Step 4:** **Do not** build the subscription history table — explicitly out of scope.
- [ ] **Step 5–7:** Verify against org 2 (expired 2026-07-17), lint, commit.

---

## Task 10: Backups tab

The largest component in the app — 1,397 lines — and the one carrying the most defects.

**Files:**
- Create: `src/components/ui/Timeline.vue`, `src/components/ui/Pagination.vue`
- Modify: `src/components/Organizations/OrganizationBackup.vue` → `src/components/organizations/tabs/BackupsTab.vue`

- [ ] **Step 1: Fix the two dead fields**

The detail panel binds `completedAt` and `fileSize`. `OrganizationBackupService::mapJobRow` returns **`finishedAt`** and **`artifactSize`**. So "Completed" always renders an em-dash and "File Size" never renders at all. Rebind both.

- [ ] **Step 2: Gate Download on the controller's real rules**

Today: `v-if="job.status === 'completed'"`. `BackupDownloadController` additionally requires a non-empty `artifactName`, a non-expired `expiresAt`, and `artifactExists()`. Its failure is a `NotFoundResponse`, and because the UI navigates with `window.location.href`, that is a full page navigation to a Nextcloud 404 — losing all component state. Disable the button with a reason instead.

Job 22 on the dev instance is a live example: completed, but past its 24-hour window.

- [ ] **Step 3: Retire the local `--status-*-rgb` custom properties**

Three locally-defined RGB triplets build translucent fills. Use `.iz-pill` tones instead. Note there is no `--status-warning-rgb`, so two warning treatments inline a raw fallback — that inconsistency goes too.

- [ ] **Step 4: Render the four step names** *(confirmed in scope)*

`steps[]` comes back on every expand and is discarded. Map `collect_db` → "Collect database records", `export_deck` → "Export Deck boards", `export_files` → "Export shared files", `finalize` → "Finalize archive", with the raw key as fallback.

- [ ] **Step 5: Surface the silent incremental → full upgrade**

`runJob` rewrites an incremental to full when no baseline exists and logs *"Incremental backup upgraded to full backup"*. The user only finds out by expanding the job afterwards. Say so before submit.

- [ ] **Step 6: Fetch rollback events and steps** *(confirmed in scope)*

`getRollbackJob` and `listRollbackEvents` exist and are never called. Reuse `Timeline.vue`.

- [ ] **Step 7: Add pagination** *(confirmed in scope)*

Hard caps of 20 jobs / 30 rollbacks / 200 events with no controls mean job 21 and older are unreachable. The API already takes `limit` and `offset`.

- [ ] **Step 8: Fix the poll-timer leak**

Deleting the job being polled leaves a 2 s timer hitting a 404 forever, because the `stopPolling()` inside the tick is unreachable once `fetchJob` returns null. Stop it in the delete handler.

- [ ] **Step 9: Per-row rollback busy state**

`creatingRollback` is one shared boolean, so one in-flight rollback disables every rollback button on the page.

- [ ] **Step 10: Confirm before Apply Rollback**

It restores a database and files and currently has no confirmation whatsoever. Use `ConfirmDialog` with `danger`.

- [ ] **Step 11: Give warnings a warning tone**

`.rollback-validation-list.warnings` recolours to `--color-text-maxcontrast` — grey reads as disabled, not as a warning.

- [ ] **Step 12: Add the third validation state**

When `canApply` is `undefined` the UI says "Validation blocked" with neither class applied. Add a neutral "Not validated".

- [ ] **Step 13–15:** Preserve everything else — both create entry points, the Full/Incremental picker, all four status treatments and the unknown-status pass-through, the amber expiry stamp, the per-job error banner, indeterminate progress for queued and running, the always-available delete, and the 2 s / 2.5 s poll cadences. Verify against the 14 real jobs, lint, commit.

---

## Task 11: Handover tab

**Files:** Modify `src/components/Organizations/AccountHandover.vue` → `src/components/organizations/tabs/HandoverTab.vue`

- [ ] **Step 1: Replace `window.confirm`**

`window.confirm('Start the real transfer? This will modify ownership and memberships.')` becomes `ConfirmDialog`. Repo convention forbids native dialogs — they cannot be themed and cannot hold an error.

- [ ] **Step 2: Preserve the switches and their defaults**

`removeSourceFromGroups` defaults **off**, `remapDeckContent` defaults **on**.

- [ ] **Step 3: Preserve the idempotency key**

`crypto.randomUUID()` with the `${Date.now()}-${Math.random()}` fallback for non-secure contexts, sent as `Idempotency-Key`.

- [ ] **Step 4: Explain why the buttons are disabled**

`isFormValid` requires both selected and `source !== target`, but nothing tells the user which rule they are failing.

- [ ] **Step 5: Make polling survive a single failure**

One failed request calls `stopPolling()` permanently; the user must click Refresh. Back off and retry instead.

- [ ] **Step 6: Resume polling after retry**

`retryJob` does not restart it, so a retried job never auto-updates.

- [ ] **Step 7: Preserve `formatStepName`**

`projectcreator` → "Project Creator Ownership Transfer", `deck` → "Deck Boards & Cards Remapping", `finalize` → "Finalization & Cleanup".

- [ ] **Step 8–10:** Verify against the three real dry-run jobs on org 1 — job 3 shows `admin → admin2`, attempt 1, both steps skipped, and the warning *"Deck ownership count is unavailable on this instance"*. Lint, commit.

---

## Task 12: Plans tab

**Files:**
- Create: `src/components/plans/PlanPanel.vue`, `src/components/plans/PlanRow.vue`
- Delete: `src/components/Plans/PlanList.vue`, `src/components/Plans/PlanDetails.vue`

- [ ] **Step 1:** Same panel-and-row shape as organizations, so the tabs behave identically.
- [ ] **Step 2: Disable Delete when the plan is in use.** `subscriptionCount` is already displayed; the server always refuses with *"Cannot delete plan: it is used by N subscriptions"*, and that message is swallowed. Disable with the count as the reason, and surface the message if it still fails.
- [ ] **Step 3: Confirm before deleting.** Today only `confirmPassword()` guards it.
- [ ] **Step 4: Always show currency.** `plan.price ? \`${price} ${currency}\` : 'Free'` hides a stored value; both dev plans have EUR that never surfaces. Keep `Free` as the price label, show the currency separately.
- [ ] **Step 5: Drop `"Per month"`** — no billing period exists anywhere in the schema.
- [ ] **Step 6: Drop both decorative `width: 100%` bars.**
- [ ] **Step 7–9:** Verify, lint, commit.

---

## Task 13: Modals onto `.iz-modal`

**Files:** Modify `CreateOrgModal.vue`, `EditOrganizationModal.vue`, `ConvertTrialModal.vue`, `CreatePlanModal.vue`, `EditPlanModal.vue`

- [ ] **Step 1:** Replace `NcModal` with `.iz-modal-backdrop` / `.iz-modal` / `__header` / `__body` / `__footer`, and `NcTextField` with `.iz-label` + `.iz-input`. Re-implement Escape and click-outside, which `NcModal` provided.
- [ ] **Step 2: Preserve every field, default and conversion.** All 14 create-org fields, the GB↔bytes getter/setter pairs including the asymmetric `sharedStoragePerProject` / `privateStorage` naming, the hidden `price: 0` / `currency: 'EUR'`, the trial toggle collapsing two sections, and the `'trial'` name filter plus the `Name (price currency)` option label in convert.
- [ ] **Step 3: Wrap each in a real `<form>`** so Enter submits — no modal has one today.
- [ ] **Step 4: Autofocus the first field.**
- [ ] **Step 5: Add `confirmPassword()` to `EditOrganizationModal`** — the only write modal without it.
- [ ] **Step 6: Make the trial summary read the real defaults.** *"7 days · 3 members · 1 project · 100MB storage"* is a hardcoded string; change a default and it lies.
- [ ] **Step 7: Implement `onPlanChange`.** It is an empty stub whose own comment says it should populate the limits.
- [ ] **Step 8: Surface errors.** Every one of these currently fails into `console.error` with the modal simply staying open.
- [ ] **Step 9–11:** Verify each modal in both schemes, lint, commit.

---

## Task 14: Trial defaults tab, and deleting the dead settings code

**Files:**
- Create: `src/components/trial/TrialDefaultsPanel.vue`
- Delete: `src/views/SettingsView.vue`, `lib/Settings/AdminSection.php`, `lib/Settings/AdminSettings.php`, `templates/settings/admin.php`

- [ ] **Step 1: Confirm the PHP is genuinely dead before deleting**

```bash
grep -n "settings" appinfo/info.xml || echo "no <settings> block — section was never registered"
grep -n "registerSettings\|registerSection" lib/AppInfo/Application.php || echo "not registered in code either"
```

Both must come back empty. Nextcloud discovers admin sections from `info.xml`; with no `<settings>` block these three files have never been reachable.

- [ ] **Step 2: Fix the duration round-trip — this is data corruption**

`SettingsView` loads `parseInt(data.duration)` and saves `` `${n} days` ``. A stored `"1 month"` becomes `"1 days"` from merely opening and saving, without touching the field. The config accepts any `DateInterval::createFromDateString()` string and the field must too.

Keep it a text input, and show what it resolves to:

```ts
const resolvedEnd = computed(() => {
  // Mirror of DateInterval::createFromDateString for the common shapes.
  const m = /^\s*(\d+)\s*(day|days|week|weeks|month|months|year|years)\s*$/i.exec(duration.value)
  if (!m) return null
  const n = Number(m[1])
  const unit = m[2].toLowerCase().replace(/s$/, '')
  const d = new Date()
  if (unit === 'day') d.setDate(d.getDate() + n)
  else if (unit === 'week') d.setDate(d.getDate() + n * 7)
  else if (unit === 'month') d.setMonth(d.getMonth() + n)
  else d.setFullYear(d.getFullYear() + n)
  return d
})
```

Render `Ends <date> for a trial started today.` when it parses, and `.iz-state` saying the server will validate it when it does not. **Never reject a string the server would accept** — the preview is advisory.

- [ ] **Step 3: Fix the storage rounding drift**

`toFixed(3)` turns the 104,857,600-byte default into `0.098` GB and re-saves **105,226,467**. Use a value + unit pair (MB/GB) and display the exact byte count beneath.

- [ ] **Step 4: Always send all six settings**

`saveTrialSettings()` defaults every omitted parameter, so a partial PUT silently resets the rest.

- [ ] **Step 5: Do not add `trial_price` / `trial_currency`** — explicitly out of scope.

- [ ] **Step 6: Do not build the "trials created from these defaults" panel** — explicitly out of scope.

- [ ] **Step 7: Verify the round-trip does not drift**

```bash
docker exec nc_pg psql -U nextcloud -d nextcloud -c \
  "SELECT configkey, configvalue FROM oc_appconfig WHERE appid='organization' ORDER BY configkey;"
```

Baseline: **no trial rows at all** — every value is a service default. Set duration to `1 month`, save, re-query, reload the page, save again unchanged, and confirm `trial_duration` is still exactly `1 month` and `trial_shared_storage_per_project` is still exactly `104857600`.

- [ ] **Step 8: Delete and commit**

```bash
git rm src/views/SettingsView.vue lib/Settings/AdminSection.php lib/Settings/AdminSettings.php templates/settings/admin.php
```

---

## Task 15: Delete the remaining shell and `@nextcloud/vue`

**Files:**
- Delete: `src/views/OrganizationsView.vue`, `src/views/PlansView.vue`, `src/components/Organizations/OrgDetails.vue`
- Modify: `package.json`

- [ ] **Step 1: Remove the view shells** now that panels own their own fetching.
- [ ] **Step 2: Confirm no `@nextcloud/vue` imports remain in the dashboard**

```bash
grep -rn "@nextcloud/vue" src/ || echo "clean"
```

- [ ] **Step 3: Declare `@vueuse/components` or drop it**

`ManageMembersModal` imported `vOnClickOutside` from it, but it is **not in `package.json`** — it resolves only as a transitive of `@nextcloud/vue`. Removing `@nextcloud/vue` will break it. Either add it explicitly or drop the directive.

- [ ] **Step 4: Remove `@nextcloud/vue` from dependencies** if nothing imports it, and re-run `npm install`.

- [ ] **Step 5: Measure the bundle**

```bash
npm run build && ls -l js/organization-main.mjs
```

Baseline **1,225 kB**. Report the new figure — a large drop is the evidence that `@nextcloud/vue` is genuinely gone.

- [ ] **Step 6: Commit.**

---

## Task 16: Backend gate and the single version bump

**Files:** Modify `lib/Controller/PageController.php`, `appinfo/info.xml`

- [ ] **Step 1: Pre-flight — confirm no org admin relies on this app**

**Ask the user to confirm against production before proceeding.** This gate change locks out every non-global-admin.

- [ ] **Step 2: Make the page superadmin-only**

```php
$userId = $user->getUID();
if (!$this->groupManager->isAdmin($userId)) {
    return new NotFoundResponse();
}

$this->initialState->provideInitialState('settings', [
    'appId' => $this->appName,
]);
```

`UserMapper` and the membership lookup become unused here — remove the injection if nothing else in the class needs it.

- [ ] **Step 3: Bump the version once**

`1.2.0` → `1.2.1` in `appinfo/info.xml`, covering Task 1's middleware fix, this gate change, and Task 14's deletions. Leave `<max-version>` at `34`. **Never change `<id>`.**

- [ ] **Step 4: Watch the upgrade cycle**

A version bump makes Nextcloud run an upgrade, and that cycle has previously disabled apps whose max-version predated NC34.

```bash
docker exec -u www-data master-nextcloud-1 php occ upgrade 2>&1 | tail -20
docker exec -u www-data master-nextcloud-1 php occ app:list | grep -E "adminpage|superadminpage|employee_dashboard|organization"
```

All four must still be enabled. If one dropped out, re-enable it and say so.

- [ ] **Step 5: Confirm migrations did not re-run**

```bash
docker exec nc_pg psql -U nextcloud -d nextcloud -t -c \
  "SELECT count(*) FROM oc_migrations WHERE app='organization';"
```

Expected: still **14**.

- [ ] **Step 6: Commit.**

---

## Task 17: Final verification

**Files:** none — this task only measures.

- [ ] **Step 1: Types to zero**

```bash
npx vue-tsc --noEmit
```

**Expected: 0 errors.** Baseline was 56, and 19 of those were in files this plan deletes while most of the rest were `ButtonType` errors from `NcButton`, which is gone. If any remain, fix them here.

- [ ] **Step 2: Lint no worse**

```bash
npx eslint src --ext .js,.vue,.ts 2>&1 | tail -3
```

Baseline **440 problems**. Must not exceed it, and every file created by this plan must be clean.

- [ ] **Step 3: Tests green**

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 vendor/bin/phpunit
docker exec master-nextcloud-1 sh -c 'find /var/www/html/apps-shared/organization/lib -name "*.php" -exec php -l {} \;' | grep -v "No syntax errors" || echo "php lint clean"
```

- [ ] **Step 4: Build green and committed**

```bash
npm run build && git status --short
```

A clean tree proves the committed bundle matches source.

- [ ] **Step 5: Walk every screen in both schemes, at 1200px and 1400px**

Organizations list; each of the five detail tabs; plans list and detail; trial defaults; all six modals; and the loading, empty and error state of each panel.

For each, confirm no hardcoded colour survives — the known offenders were `#8e44ad` and `#2c3e50` in `OrgDetails.vue:460-461`, `#8e44ad` and `#27ae60` in `PlanDetails.vue:292-293`, `color: orange` in `AccountHandover.vue:954`, `color: white` on two `OrgDetails` badges, and 13 `rgba(0, 0, 0, …)` shadows across the modals.

- [ ] **Step 6: Audit for silent overrides**

For every element carrying an `.iz-*` class, compare the properties the theme rule sets against those any app rule sets on the same element in computed styles. Overlap is a leftover. **Reading the files is not reliable — this has been proven twice** in the sibling apps, where a search field and three selects were each overriding about forty properties while wearing the primitive's class.

- [ ] **Step 7: Report measurements, not impressions**

Type-error count before and after, lint count before and after, bundle size before and after, and the specific computed values checked. Several bugs in this programme were only visible in computed styles.

- [ ] **Step 8: Update `CLAUDE.md`**

Point the design section at `USING-THE-THEME.md` and keep only this app's deltas — Vue 3 versus the siblings' Vue 2.7, Vite versus webpack, the committed `js/` and `css/` outputs, and the PHPUnit bootstrap. **Do not copy the guide's content in**; that is exactly the drift it exists to end. Use `../employee_dashboard/CLAUDE.md` as the model.

- [ ] **Step 9: Do not push.** Report status and ask.

---

## Deferred

Recorded so they are not lost, and deliberately not built:

- Subscription history table (`oc_subscriptions_history` has the rows and a mapper, no UI)
- "Trials created from these defaults" panel
- `trial_price` / `trial_currency` — read by `TrialOrganizationService`, editable nowhere; needs `saveTrialSettings()` widened
- i18n — every string is hardcoded English, as in all four apps
- Unsaved-changes guards on modals
- Hardening `saveTrialSettings()` against partial PUTs server-side (Task 14 avoids it client-side)
- Pruning the 34 accumulated `css/main-*.chunk.css` build artifacts
- The `Backup#listMyOrganizationBackupJobs` endpoint, which this app never calls but `adminpage` does
