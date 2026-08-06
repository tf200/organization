# Handover: finishing the `organization` redesign

Paste this whole file as the opening prompt of a new session started in
`/home/payboy/src/nextcloud-docker-dev/data/apps-extra/organization`.

This supersedes `REDESIGN_HANDOVER.md`, which described the work that is now
done. Read `CLAUDE.md` first — it is short and it is the app's own guide.

---

## 1. Where things stand

The app has been moved onto the In Zicht theme and the single-column dashboard
shape. It works end to end. Branch **`taha`**, **12 commits ahead of
`origin/taha`, nothing pushed**. The theme repo
(`/home/payboy/src/inzicht-nextcloud-theme`) is **2 commits ahead of
`origin/master`**, also unpushed.

Shape: three page-level tabs (Organizations · Plans · Trial defaults), panels of
expandable rows, detail opening in place with five tabs of its own
(Overview · Members · Subscription · Backups · Handover).

Gates, all currently green:

| Gate | Command | State |
|---|---|---|
| Types | `npx vue-tsc --noEmit` | **0** (was 56) |
| Lint | `npx eslint src --ext .js,.vue,.ts` | 100 problems (was 440) |
| Build | `npm run build` | green, **274 kB** (was 1,225 kB) |
| PHP tests | see §2 | **19 passing** |

`@nextcloud/vue` and `@nextcloud/password-confirmation` are both gone from the
bundle. Every colour and size in `src/` resolves through a theme token.

---

## 2. Environment

**Start `nc_pg` before `master-nextcloud-1`.** Booting Nextcloud without its
database runs `maintenance:install` and overwrites `config.php`, losing
`theme=inzicht`. This has happened three times.

```bash
docker start nc_pg && sleep 8
docker start master-redis-1 master-database-mysql-1
docker start master-nextcloud-1 master-proxy-1
```

- App: `http://nextcloud.local:8080/index.php/apps/organization/`
- **Log in as `admin` / `admin`.** The app is superadmin-only now; `admin2` /
  `rootroot` is an *organization* admin and correctly gets a 404.
- `overwrite.cli.url` has **no port**, so Nextcloud's post-login redirect lands
  on port 80 and 404s. Navigate to the `:8080` URL directly afterwards.
- Live DB is **PostgreSQL in `nc_pg`** — not `nc_db`, and not
  `master-database-mysql-1`, which holds an empty skeleton of the same schema
  that reads as real until you notice every table has zero rows.
  `docker exec nc_pg psql -U nextcloud -d nextcloud -c "..."`
- PHP tests run **inside the container** (`nextcloud/ocp` is stubs-only and
  ships no autoload, so `tests/bootstrap.php` loads the server's `base.php`):
  ```bash
  docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 \
      vendor/bin/phpunit
  ```
- **Reloading does not re-fetch the bundle or the theme CSS.**
  `fetch(url, {cache: 'reload'})` for each, then reload.
- Simulate dark by setting `data-themes="dark"` and `data-theme-dark=""` on
  **both** `<body>` and `<html>` — and read computed styles on a **later tick**,
  not in the same one as the mutation, or you get stale values.

Data: orgs `Test` (id 1, standard, `admin2`, active to Jul 2027, 3 members) and
`testorg` (id 2, trial, `testadmin`, expired Jul 2026, 2 members); 2 plans, both
private, both €0; 14 backup jobs; 3 handover jobs, all dry runs;
**0 rollback jobs**; `oc_appconfig` has no `organization` rows, so every trial
setting is a service default.

---

## 3. The one non-obvious thing — read before writing any CSS

**This app's CSS loads *before* the theme.** `organization-main.css` is
stylesheet index 1; the theme's `server.css` is index 4. The three sibling apps
use webpack, which injects styles at runtime so they always land last.

So `USING-THE-THEME.md` §4 — *"on a tie the app wins"* — **is inverted here.**
At equal specificity the theme wins. A bare `.my-class { width: auto }` in a
scoped block ties `.iz-app .iz-input` at (0,2,0) and loses.

Qualify local overrides on a parent to reach (0,3,0):

```css
/* loses */            .org-panel__search { width: auto; }
/* wins  */            .org-panel__toolbar .org-panel__search { width: auto; }
```

This already caused two visible bugs (filters stacking one per row, a storage
input collapsing to 26px). Assume any override of a property a primitive also
sets needs the parent qualifier.

Everything else in `USING-THE-THEME.md` applies as written. Read it.

---

## 4. Do these first, in this order

### 4a. Cards — use `.iz-card`

`.iz-card` is used **0 times here and 16 times in `superadminpage`**. Six local
blocks re-declare what it already provides (surface, 1px border, radius-lg,
shadow, `--iz-pad-card`):

| File | Class | Count |
|---|---|---|
| `src/components/organizations/tabs/OverviewTab.vue` | `.overview__block` | 3 (Contact person, Organization settings, Storage quotas) |
| `src/components/organizations/tabs/SubscriptionTab.vue` | `.subscription__block` | 2 |
| `src/components/plans/PlanRow.vue` | `.plan-row__block` | 2 |

Reference: `superadminpage/src/components/OrgDetailView.vue:89` —
`<div class="iz-card org-detail__profile-card">`, with the local class carrying
only layout. Its comment at line 377 is the pattern to copy.

Replace the class, delete the chrome from the scoped block, keep only layout.
**Deleting the local rule is the job** — leaving it in place means the primitive
is inert (and here it loses the tie outright, see §3).

### 4b. Array rows — match `MembersPanel`

The members list is a plain `<ul>/<li class="members__row">` with a local
`border-bottom`. `superadminpage` builds the same thing from primitives —
`superadminpage/src/components/MembersPanel.vue:418`:

```html
<div class="members-panel__card iz-row--card iz-row--expandable"
     :class="{ 'iz-row--expanded': expanded[member.userId] }">
  <div class="members-panel__row iz-row__header" @click="toggle(member.userId)">
    <span class="iz-identity__avatar">…</span>
    <div class="iz-identity__body members-panel__info">
      <span class="iz-identity__name">…</span>
      <span class="iz-identity__meta">…</span>
```

Ours already uses `.iz-identity__*` inside the row; what is missing is the
`.iz-row--card` shell.

**One judgement call to make, not assume:** superadminpage's member rows are
*expandable* and open onto a detail grid. Ours sit inside an already-expanded
organization row, so nesting a second expandable may read badly. Flat
`.iz-row--card` rows without `--expandable` are probably right. Decide
deliberately and say which you picked.

Same treatment for any other list of records: check the search results in the
Members tab's "Add existing" mode (`.iz-user-picker__result`, already a
primitive — leave it).

### 4c. Pagination — **it was never built**

I listed it as in scope and did not do it. There is **no `Pagination.vue`, and
`iz-pagination` appears in 0 files.** The hardcoded caps are all still there, in
`src/components/organizations/tabs/BackupsTab.vue`:

```
:468   backup jobs     limit: 20,  offset: 0
:473   rollback jobs   limit: 30,  offset: 0
:490   events          limit: 200, offset: 0
```

So backup job 21 and older are simply unreachable — there are 14 jobs today, so
this is not yet visible, but it is a real hole. The API already takes `limit`
and `offset` (`BackupController` clamps jobs to 100 and events to 200).

Use the theme's `.iz-pagination` / `.iz-pagination__pages` primitive — its
comment says it was reimplemented five times across the apps and this is the
one. Build it once in `src/components/ui/Pagination.vue`.

Also note the organization and plan lists page nothing at all: both fetch
everything and filter client-side. `PlanController::getPlans` supports
`search`/`limit`/`offset` and none of it is used. Decide whether that matters at
this scale — with 2 orgs and 2 plans it currently does not.

---

## 5. Then: Backups and Handover

`BackupsTab.vue` and `HandoverTab.vue` are the last components not *built* from
the theme. They now match it visually — every font size is on the ramp and every
colour is an `--iz-*` token — but they carry **1,103 lines of local structural
CSS** (`.job-card`, `.timeline`, `.progress-track`, `.status-indicator`,
`.rollback-item`) instead of `.iz-row--card`, `.iz-panel`, `.iz-metrics`.

For contrast: `OrgRow.vue` has **0** chrome declarations.

These are the two components with the most behaviour — 2s and 2.5s polling,
rollback validation rendering, step timelines, artifact expiry — so this is
structural surgery on the riskiest files. Do it as its own pass, and re-verify
against the real data (14 backup jobs, 3 handover jobs) rather than by eye.

Already fixed in them, do not undo:
- `Finished` / `Artifact size` were bound to `completedAt` / `fileSize`, which
  the API never returns; they now use `finishedAt` / `artifactSize`.
- Download is gated on artifact name and expiry, not just `status === completed`.
- Deleting the polled job now stops its timer.
- Status badges are `.iz-pill` with an explicit tone table; `expired` had no
  rule at all before and rendered unstyled.

---

## 6. Verified, and not

**Verified in the browser, both schemes**, by reading computed styles rather
than screenshots: the three tabs, all five detail tabs, the org and plan lists,
trial defaults, and all five modals opening, prefilling and cancelling.

**Not verified:** no form has actually been *submitted*. Creating an
organization, converting `testorg`, saving trial defaults, deleting a plan or a
backup — all the POST/PUT/DELETE paths are unexercised, because they mutate the
dev instance. Worth doing deliberately at some point.

**Known, not this app's bug:** `adminpage`'s `/api/backup-jobs` still 500s. It
does a server-to-server loopback to `getAbsoluteURL()`, which builds a portless
URL from `overwrite.cli.url`, and forwards the browser `Cookie` header. The
original handover claimed landing this branch would fix it. It does not.

---

## 7. Conventions

- **Bump `<version>` in `appinfo/info.xml` only when PHP changes.** It is at
  `1.2.1`. A bump triggers an upgrade cycle that has previously disabled apps —
  check all four are still enabled afterwards.
- **Never change `<id>`.** Production is the authority.
- **No `alert()` or `confirm()`** — use `src/components/ConfirmDialog.vue`. It
  is a Vue 3 *port* of the vendored Vue 2 dialog, so a change to the shared one
  must be ported, not copied. This is noted in `USING-THE-THEME.md` §10.
- **Run `npm run build` and commit `js/` and `css/`** with any `src/` change; a
  clean tree after building proves source and bundle agree.
- **Commit per logical change and say why**, including what was tried and
  rejected. Match the existing log.
- **Push only when asked.** `deploy.sh` does `git reset --hard origin/taha`
  against production.
