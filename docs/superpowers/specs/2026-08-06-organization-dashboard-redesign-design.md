# Design: bring `organization` onto the In Zicht dashboard shape

**Date:** 2026-08-06
**Branch:** `taha` (local, tracking `origin/taha`)
**Status:** design approved, plan not yet written

---

## 1. What this is

`organization` is the fourth of four sibling Nextcloud apps. The other three —
`adminpage`, `superadminpage`, `employee_dashboard` — were migrated onto a shared
theme and a shared single-column dashboard layout. This app has had none of it: it
is still a Nextcloud sidebar app with a list/detail split, on Vue 3 with
`@nextcloud/vue`, with no `iz-app` anywhere and every component's styles scoped.

This redesign changes its layout and its chrome. It is **not** a feature change.

### Decisions already taken

| Question | Decision |
|---|---|
| Which branch | Work on `taha`, merge to `main` later |
| Stack | Stay Vue 3 + Vite + TS; drop `@nextcloud/vue` from the dashboard surface |
| Audience | **Superadmin only** — this app is an extension of `superadminpage` |
| Structure | **Option B** — three page-level tabs, everything in-app |
| Scope | **Restyle + fixes only.** No new surfaces. |

### Why superadmin-only matters

`superadminpage` already manages organizations for global admins and calls this
app's OCS API directly (`MembersPanel`, `SubscriptionPanel`, `HandoverPanel`,
`CreateOrgModal`). The division is: **`superadminpage` monitors the fleet,
`organization` administers it.** This app therefore does not carry a KPI strip —
that would duplicate `PlatformKpiStrip`.

---

## 2. Prerequisite: the app is broken on NC34

**This blocks everything and must be step one of the plan.**

`lib/Middleware/SubscriptionMiddleware.php:121` calls
`ControllerMethodReflector::hasAnnotationOrAttribute()`, which does not exist in
Nextcloud 34. The class now exposes only `hasAnnotation()` and
`getAnnotationParameter()`.

The middleware is registered for this app's container
(`Application.php:34`, no `$global` flag), so every request into the app fails:

```
HTTP 500  /ocs/v2.php/apps/organization/organizations
HTTP 500  /ocs/v2.php/apps/organization/organizations/1
HTTP 500  /ocs/v2.php/apps/organization/backups/jobs/my-organization
HTTP 500  /index.php/apps/organization/          ← the page itself
```

Message: `Call to undefined method
OC\AppFramework\Utility\ControllerMethodReflector::hasAnnotationOrAttribute()`.

**This corrects the handover.** It claimed landing `taha` would fix `adminpage`'s
`/api/backup-jobs` 500 because the route was missing on `main`. The route does
exist on `taha` — the 500 has a second, deeper cause, and landing `taha` alone
does not fix it.

**Fix.** Replace the call with a native attribute check:

```php
$reflectionMethod = new \ReflectionMethod($controller, $methodName);
if ($reflectionMethod->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class) !== []) {
    return true;
}
```

One occurrence, this app only. No sibling app uses the removed API.

Until this lands, nothing can be verified in a browser.

---

## 3. Structure

Root element:

```html
<div class="org-dashboard iz-app">
```

```css
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
```

Taken from `superadminpage`'s container, because flex + `gap` cannot drift the way
`adminpage`'s and `employee_dashboard`'s per-panel `margin-bottom` already has.

**No header component.** With three tabs and no identity to display, there is
nothing for one to carry. `employee_dashboard`'s sticky header and its
IntersectionObserver sentinel are not adopted.

### Page-level tabs

`.iz-tabs .iz-tabs--display`, matching `superadminpage`. Choice persisted to
`localStorage["organization:activeTab"]`.

1. **Organizations** (count badge)
2. **Plans** (count badge)
3. **Trial defaults** (no count — it is a form, not a collection)

### Detail-in-place

Expandable row (`.iz-row--card.iz-row--expandable`) with `.iz-tabs` inside
`.iz-row__detail`. Chevron last, on the right, rotating 180° — the theme's one
sanctioned expandable design. No second pane, no full-page modal for detail.

Organization detail tabs: **Overview · Members · Subscription · Backups ·
Handover**.

This is what gives `OrganizationBackup.vue` (1,397 lines) and
`AccountHandover.vue` (1,000 lines) room to exist; today they are squeezed into a
third of the viewport.

---

## 4. Component plan

### New

| File | Purpose |
|---|---|
| `src/styles/iz-app.scss` | The one **unscoped** global stylesheet, imported from `main.ts`. Layout only — no chrome. |
| `src/components/ConfirmDialog.vue` | Vue 3 port of the vendored dialog. Parent owns `busy` and `error`; the dialog never closes itself. |
| `src/components/organizations/OrgPanel.vue` | Panel, toolbar, row list, empty and loading states |
| `src/components/organizations/OrgRow.vue` | Expandable row + summary cells |
| `src/components/organizations/tabs/OverviewTab.vue` | Contact, org settings, storage quotas |
| `src/components/organizations/tabs/MembersTab.vue` | Segmented Current / Add existing / Create account |
| `src/components/organizations/tabs/SubscriptionTab.vue` | Subscription fields + convert entry point |
| `src/components/plans/PlanPanel.vue`, `PlanRow.vue` | Same shape as organizations |
| `src/components/trial/TrialDefaultsPanel.vue` | Trial defaults, from `SettingsView.vue` |
| `src/lib/format.ts` | One `formatFileSize`, replacing four divergent copies |
| `src/composables/useApi.ts` | axios wrapper that surfaces errors instead of swallowing them |

### Modified (restyled, behaviour preserved)

- `src/App.vue` — `iz-app` root, tab bar, no `AppNavigation`, no permission branching
- `src/main.ts` — import the global stylesheet
- `src/components/Organizations/OrganizationBackup.vue` → Backups tab
- `src/components/Organizations/AccountHandover.vue` → Handover tab
- The five modals — `CreateOrgModal`, `EditOrganizationModal`, `ConvertTrialModal`,
  `CreatePlanModal`, `EditPlanModal` — onto `.iz-modal`
- `templates/index.php` — `<div id="content" class="iz-app">` as a belt-and-braces hook
- `lib/Controller/PageController.php` — drop `$isOrganizationAdmin` from the gate
- `lib/Middleware/SubscriptionMiddleware.php` — the NC34 fix

### Deleted

| File | Reason |
|---|---|
| `src/components/AppNavigation.vue` | Replaced by the tab bar |
| `src/views/OrganizationsView.vue`, `PlansView.vue` | `NcAppContent` shells replaced by panels |
| `src/views/SettingsView.vue` | Content moves to `TrialDefaultsPanel.vue` |
| `src/components/Organizations/OrgList.vue`, `OrgDetails.vue` | Redistributed into panel/row/tabs |
| `src/components/Organizations/ManageMembersModal.vue` | Becomes the Members tab |
| `src/components/Plans/PlanList.vue`, `PlanDetails.vue` | Redistributed |
| `lib/Settings/AdminSection.php`, `AdminSettings.php` | Dead code — never registered |
| `templates/settings/admin.php` | Same |

**On the dead settings code:** `appinfo/info.xml` has no `<settings>` block and
`Application.php::register()` registers only middleware, the notifier and three
Talk listeners. Nextcloud discovers admin sections from `info.xml`, so these three
files have never been reachable. Deleting them is free.

---

## 5. The theme contract

Read `/home/payboy/src/inzicht-nextcloud-theme/USING-THE-THEME.md` before writing
any style. Rules that bite hardest here:

- **`iz-app` on the root or nothing works.** `.iz-btn`, `.iz-input`, `.iz-select`,
  `.iz-chip`, `.iz-tab`, `.iz-close` are all scoped `.iz-app .iz-X, element.iz-X`.
- **Chrome from the primitive, layout stays local.** Never hardcode a colour or a
  font size.
- **Adding a primitive class without deleting the local rule does nothing.** Vue
  scoped CSS is `(0,2,0)`, the same as `.iz-app .iz-input`, and app styles inject
  after the theme. On a tie the app wins. Audit the live DOM, not the file.
- **Unscoped blocks are the inverse trap** — a bare class there is `(0,1,0)` and
  loses to the theme. Qualify on a parent.
- Nextcloud core styles bare `button`/`input`/`select` at `(0,1,1)`, including a
  34px `min-height` and an `!important` focus outline.
- **Check both colour schemes every time.**

Primitives this design uses: `.iz-panel` / `--list`, `.iz-row--card` /
`--expandable` / `--expanded`, `.iz-row__header` / `__actions` / `__chevron` /
`__detail`, `.iz-tabs` / `--display`, `.iz-tab` / `__count`, `.iz-btn` and tones,
`.iz-segment`, `.iz-pill` / `.iz-badge` and tones, `.iz-identity`, `.iz-meter`,
`.iz-metrics`, `.iz-input` / `.iz-select` / `.iz-label`, `.iz-table` /
`.iz-table-wrap`, `.iz-modal` and parts, `.iz-user-picker`, `.iz-empty`,
`.iz-error`, `.iz-inset`, `.iz-state`, `.iz-spinner`, `.iz-pagination`.

`src/lib/izChart.js` is **not** vendored — this app draws no canvas charts.

---

## 6. Error handling — the largest single change

Across the 18 components there is **no user-visible error message** outside
`SettingsView` and two server-persisted `errorMessage` banners. Twenty-plus call
sites — every create, every delete, every fetch, the real account transfer, the
rollback apply — fail into `console.error`. The user watches a spinner stop.

Every mutation and fetch gets a visible failure state via `.iz-error`, placed at
the point of failure.

**What must be preserved is the shape of the failure:** the modal stays open, the
data stays intact, the button re-enables. That is the `ConfirmDialog` contract.

Server messages that exist today and are currently swallowed must surface —
notably `Cannot delete plan: it is used by N subscriptions` and the eleven
rollback rejection strings from `OrganizationRollbackService`.

---

## 7. Feature preservation

The authoritative audit is 147 rows, compiled by reading all 18 `src/` files,
`templates/settings/admin.php` and four controllers line by line:

**87 preserved · 7 moved · 37 fixed · 11 dropped · 5 added.**

### Scope resolution on the 5 "added" rows

"Restyle + fixes only" excluded three surfaces by name. The other four additions
were raised separately and **confirmed in scope**. Final line:

| Addition | In / out | Reasoning |
|---|---|---|
| Type and status filters on the org list | **In** | Two `.iz-select` controls in a toolbar that is being built anyway; near-zero cost |
| Backup step names (`collect_db`, `export_deck`, `export_files`, `finalize`) | **In** | The API already returns `steps[]` on every expand and the component discards it — rendering it is display work, not a feature |
| Rollback events and steps timeline | **In** | Same: `getRollbackJob` and `listRollbackEvents` exist and are never called. Reuses the backup timeline component |
| Pagination past 20 jobs / 30 rollbacks / 200 events | **In** | `.iz-pagination` exists in the theme and the API already takes `limit` / `offset`. Without it, job 21 and older are unreachable |
| `trial_price` / `trial_currency` fields | **Out** | Named in your exclusion. Needs `saveTrialSettings()` widened — a PHP API change |
| Subscription history table | **Out** | Named in your exclusion |
| "Trials created from these defaults" panel | **Out** | Named in your exclusion |

### The 11 drops, all deliberate

1–4. Mode chip, `showPlans` gate, client-side permission guard, auto-select-single-org
— all unreachable once the app is superadmin-only.
5–6. "View Details" overflow action in both lists — calls the identical emit as the
row click.
7–9. Three progress bars hardcoded to `width: 100%` — they encode no data.
10. `"Per month"` under plan pricing — no billing period exists in the schema.
11. `SettingsView`'s three-card grouping — six fields do not need three cards.

### Fixes that are real bugs, not polish

- **`completedAt` / `fileSize`** in the backup detail panel are bound to properties
  `mapJobRow` never returns. Bind to `finishedAt` / `artifactSize`.
- **Download** is offered for any `completed` job, but
  `BackupDownloadController` also requires a non-empty artifact name, a
  non-expired `expiresAt` and an existing file. Its 404 is a full page
  navigation that loses all state.
- **Trial duration round-trip corrupts data.** `SettingsView` reads
  `parseInt(data.duration)` and writes `` `${n} days` ``, so a stored
  `"1 month"` silently becomes `"1 days"` — from merely opening and saving.
  The field must stay interval-capable.
- **Storage rounding drifts.** `toFixed(3)` turns the 104,857,600-byte default
  into `0.098` GB and re-saves 105,226,467. Use a value + unit pair and show the
  byte count.
- **Delete Plan** stays enabled while subscriptions reference the plan; the server
  always refuses.
- **`window.confirm`** before the real handover, and **no confirmation at all**
  before Apply Rollback, which restores a database and files.
- **Poll timer leaks** after deleting the polled backup job; handover polling dies
  permanently after one failed request and does not resume on retry.
- **`onPlanChange`** in `CreateOrgModal` is an empty stub whose own comment says it
  should populate limits.
- **`EditOrganizationModal`** lacks `confirmPassword()` while create and convert
  have it.
- Hardcoded `#8e44ad`, `#2c3e50`, `color: orange` and 13 `rgba(0,0,0,…)` shadows —
  none adapt to dark mode.

### Explicitly out of scope

Subscription history table, "trials created from these defaults" panel,
`trial_price` / `trial_currency` fields, i18n, unsaved-changes guards, and any
change to the OCS API's shape. All viable follow-ups.

---

## 8. Versioning

`<version>` is bumped **only when PHP changes**. This work does change PHP — the
middleware fix, the `PageController` gate, and the settings deletions — so
`1.2.0 → 1.2.1`.

A version bump makes Nextcloud run an upgrade cycle, and that cycle has previously
disabled apps whose max-version predated NC34. Verify the other three apps are
still enabled afterwards.

`<id>` must remain `organization`. Never change it — production is the authority.

---

## 9. Verification

- Browser, **both colour schemes**, at 1200px and 1400px.
- Report measurements from computed styles, not impressions. Several bugs in this
  programme were only visible there.
- Reloading does not re-fetch the bundle or the theme CSS:
  `fetch(url, {cache: 'reload'})` for each, then reload.
- Simulate dark by setting `data-themes="dark"` and `data-theme-dark=""` on **both**
  `<body>` and `<html>`.
- Audit the live DOM for leftover scoped rules that tie with the theme on
  specificity and win on injection order.
- Real data: orgs `Test` (standard, `admin2`, active to Jul 2027, 3 members) and
  `testorg` (trial, `testadmin`, expired Jul 2026, 2 members); 2 plans, both
  private; 14 backup jobs; 3 handover jobs, all dry runs. `admin2` / `rootroot`.

There is no test or lint tooling in any of the four apps. Verification is manual.

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| NC34 middleware bug blocks all verification | Fix first, before any UI work |
| Version bump triggers an upgrade cycle with side effects | Check all four apps still enabled after |
| Scoped-CSS specificity tie silently keeps old styles | Audit computed styles, not source |
| `deploy.sh` does `git reset --hard origin/taha` against production | Do not push without asking; confirm before merging to `main` |
| An org admin may currently rely on reaching this app | Confirm against production before dropping the org-admin path |
| `@vueuse/components` is imported but never declared in `package.json` | Declare it, or drop the one `v-click-outside` usage |

---

## 11. Current environment state

- Working tree on branch `taha`; `main` is 38 commits behind and untouched.
- App **enabled at 1.2.0**. All 14 migrations were already recorded, so none re-ran.
  Data intact: 2 orgs, 2 plans, 5 members, 2 subscriptions, 14 backup jobs,
  3 handover jobs.
- `oc_appconfig` has **no rows** for `organization` beyond what enabling wrote, so
  every trial setting runs on its service default — and those defaults are exactly
  what minted `testorg`'s plan.
- The app returns HTTP 500 on every route until § 2 is fixed.
