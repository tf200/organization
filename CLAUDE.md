# organization

Superadmin-only administration surface for organizations, subscriptions and
plans. Sits alongside `superadminpage`: **that app monitors the fleet, this one
administers it.** `superadminpage` calls this app's OCS API directly, so the two
are coupled — changing a response shape here can break it.

## Design System — the In Zicht theme

**Read `/home/payboy/src/inzicht-nextcloud-theme/USING-THE-THEME.md` before
touching any style.** It is the canonical guide, shared with `adminpage`,
`superadminpage` and `employee_dashboard`, and it is the file to edit when a
rule changes. It covers the token bridge, the primitive catalogue, why adding a
class without deleting the local rule does nothing, Nextcloud core's
bare-element traps, panel variants, dark mode, and the vendored files.

In short: this app defines no look of its own. `App.vue`'s root carries
`iz-app`, which supplies the tokens; chrome comes from the theme's `.iz-*`
primitives and only layout stays in a component. `src/styles/iz-app.scss` is
the one unscoped stylesheet and is deliberately layout-only.

## How this app differs from its three siblings

Everything below is a genuine delta. Anything not listed here works the way the
guide describes.

- **Vue 3 + Vite + TypeScript**, where the others are Vue 2.7 + webpack + plain
  JS. Composition API with `<script setup>`.
- **Shared types live in `src/types.ts`, never in an SFC.** Vue's compiler
  rejects `export` statements inside `<script setup>`, so a type declared there
  cannot be imported by a sibling component.
- **`ConfirmDialog.vue` is a fork, not a copy.** The guide's §10 says the
  vendored dialog must be updated in every app in the same change; that is
  impossible here, because the shared file is a Vue 2 SFC. A change to the
  shared dialog has to be **ported** into this app, not copied.
- **No `@nextcloud/vue`, and no `@nextcloud/password-confirmation`.** The
  latter declares the former as a direct dependency, so it dragged the whole
  component library into the bundle even after every `Nc*` component was gone.
  `src/lib/passwordConfirmation.ts` replaces it, POSTing to `/login/confirm` —
  the same approach `superadminpage` takes. It keeps the original signature and
  rejects with the string `'cancelled'`, which several call sites test for.
- **`src/components/ui/` holds plain-element replacements** for the `Nc*`
  components (`IzButton`, `IzModal`, `IzTextField`, `IzSelect`, `IzSwitch`,
  `IzSpinner`, `IzAvatar`, `IzDateTime`, `IzChevron`, `Pagination`). They exist
  because the theme's primitives target bare elements — core excludes
  `.button-vue` from its own rules, so `NcButton` can only be restyled with
  `:deep()` in every consumer.
- **`src/components/jobs/` holds the pieces shared by the three job kinds.**
  Backups, rollbacks and account handovers are separate services that emit an
  identical step record and event record, so `JobSteps.vue` and `JobEvents.vue`
  render all three, and `src/lib/jobs.ts` owns the one status→tone table, the
  step-key names and `isActive()`. Two private copies of that table had already
  drifted: one listed `skipped` as a *job* status, which no job ever has, and
  neither listed `expired` or `deleted`, which a backup job routinely is.
- **`js/` and `css/` are committed build outputs.** Run `npm run build` and
  commit the result with any `src/` change. The build is reproducible: a clean
  tree after building means source and bundle agree. Note `css/` accumulates a
  new content-hashed chunk per build and is never pruned.
- **`src/lib/izChart.js` is deliberately not vendored** — this app draws no
  canvas charts. Add it from the theme the day that changes.

## Commands

```bash
npm run build          # required after any src/ change; commit js/ and css/
npx vue-tsc --noEmit   # must stay at 0 errors
npx eslint src --ext .js,.vue,.ts
```

PHP tests run **inside the container**, because `nextcloud/ocp` is a stubs-only
package with no autoload — the real `OCP\*` classes come from the server, and
`tests/bootstrap.php` loads `base.php` first:

```bash
docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 \
    vendor/bin/phpunit
```

If `vendor/` is missing, install with `--ignore-platform-req=php`: the container
runs PHP 8.2 and `composer.json` asks for `^8.3`.

## Conventions

- **Bump `<version>` in `appinfo/info.xml` only when PHP changes** (`lib/`,
  `appinfo/`). Never for frontend-only work. A bump triggers an upgrade cycle,
  which has previously disabled apps whose max-version predated NC34 — check
  all four apps are still enabled afterwards.
- **Never change `<id>`.** It must equal the directory name and production is
  the authority.
- **No `alert()` or `confirm()`.** Use `ConfirmDialog.vue`; the parent owns the
  busy flag and the error string, and the dialog never closes itself, so a
  failed action stays open with its reason.
- **Surface server errors.** `src/lib/api.ts` keeps the server's own message on
  `ApiError`; render it. Messages like *"Cannot delete plan: it is used by N
  subscriptions"* used to be swallowed into `console.error`, so a click looked
  like it did nothing.
- **Verify in the browser in both colour schemes and report measurements**, not
  impressions. Several bugs here were only visible in computed styles.
- **Push only when asked.** `deploy.sh` does `git reset --hard origin/taha`
  against production.

## Environment

Start `nc_pg` **before** `master-nextcloud-1` — booting Nextcloud without its
database runs `maintenance:install` and overwrites `config.php`, losing
`theme=inzicht`. This has happened three times.

The live database is **PostgreSQL in `nc_pg`**, not `nc_db` and not
`master-database-mysql-1`, which holds an empty skeleton of the same schema that
reads as real until you notice every table has zero rows.

This working tree is bind-mounted to `/var/www/html/apps-shared/organization`,
so it *is* the deployed app — there is no separate deploy step for local work.

`overwrite.cli.url` is `http://nextcloud.local` with **no port**, so Nextcloud's
post-login redirect lands on port 80 and 404s. Navigate to
`http://nextcloud.local:8080/...` directly.

## Known gaps, deliberately left

- Subscription history exists in `oc_subscriptions_history` with a mapper and
  no UI.
- `trial_price` and `trial_currency` are read by `TrialOrganizationService` but
  `saveTrialSettings()` does not accept them, so they are editable nowhere.
- Every UI string is hardcoded English; there is no `t()` usage, matching the
  sibling apps.
- No unsaved-changes guard on any modal.
- **Every mutating backup route is `#[PasswordConfirmationRequired]`; no
  handover route is** — including the real, irreversible transfer. The UI
  matches the server rather than papering over it, so starting a transfer asks
  for confirmation but never for a password. That asymmetry is a server-side
  decision to make, not a frontend one.
- `listBackupJobs` and `listHandoverJobs` both accept a `status` filter that no
  UI uses. `GET /backups/jobs/my-organization` is never called at all.
- Backup retention keeps only the newest **7** finished jobs per organization
  (`RETENTION_JOBS`), so paging past the first page is rare in practice even
  though the controls are wired.
- `adminpage`'s `/api/backup-jobs` returns 500. Not this app's bug: it does a
  server-to-server loopback to `getAbsoluteURL()`, which builds a portless URL
  from `overwrite.cli.url`, and forwards the browser `Cookie` header.
