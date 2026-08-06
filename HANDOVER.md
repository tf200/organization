# Handover: finishing the `organization` redesign

Paste this whole file as the opening prompt of a new session started in
`/home/payboy/src/nextcloud-docker-dev/data/apps-extra/organization`.

This supersedes `REDESIGN_HANDOVER.md`, which described the work that is now
done. Read `CLAUDE.md` first — it is short and it is the app's own guide.

---

## 1. Where things stand

The app has been moved onto the In Zicht theme and the single-column dashboard
shape, and every component is now built from the theme's primitives. It works
end to end. Branch **`taha`**, **14 commits ahead of `origin/taha`, nothing
pushed**. The theme repo (`/home/payboy/src/inzicht-nextcloud-theme`) is
**3 commits ahead of `origin/master`**, also unpushed.

Shape: three page-level tabs (Organizations · Plans · Trial defaults), panels of
expandable rows, detail opening in place with five tabs of its own
(Overview · Members · Subscription · Backups · Handover).

Gates, all currently green:

| Gate | Command | State |
|---|---|---|
| Types | `npx vue-tsc --noEmit` | **0** (was 56) |
| Lint | `npx eslint src --ext .js,.vue,.ts` | 74 problems (was 440) |
| Build | `npm run build` | green, **263 kB** (was 1,225 kB) |
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

**Always parent-qualify a local rule that overrides a property a primitive also
sets.** Reach (0,3,0) rather than relying on the cascade order:

```css
/* fragile */          .org-panel__search { width: auto; }
/* safe    */          .org-panel__toolbar .org-panel__search { width: auto; }
```

Two visible bugs came from not doing this — filters stacking one per row, and a
storage input collapsing to 26px.

**Why:** this app's CSS loads *before* the theme, so `USING-THE-THEME.md` §4 —
*"on a tie the app wins"* — **is inverted here.** At equal specificity the theme
wins. A bare `.my-class { width: auto }` in a scoped block ties
`.iz-app .iz-input` at (0,2,0) and loses. The three sibling apps use webpack,
which injects styles at runtime so theirs always land last.

The reason this is easy to get wrong — and it was got wrong once, in this file:

```
stylesheet 1   css/organization-main.css     ← 79 bytes
stylesheet 4   themes/inzicht/…/server.css
```

`organization-main.css` looks empty enough to dismiss. It is not: it is a shim
whose entire contents are `@import './main-<hash>.chunk.css'`, and an
`@import` resolves **in place**, so all 14 kB of component CSS inherits
position 1. Reading the file sizes and concluding the app's real CSS lands
somewhere later is wrong. Check `document.styleSheets[1].cssRules`.

That content hash changes on every build, which also makes cache-busting
fiddly: `fetch`ing `organization-main.css` alone leaves the browser importing
the **previous** chunk, which is still on disk because `css/` is never pruned.
The symptom is a page where theme primitives look right and every local class
is dead — the scoped `data-v-…` hash in the stale chunk no longer matches the
DOM. Bust the chunk by name too:

```js
for (const u of ['/themes/inzicht/core/css/server.css',
                 '/apps-shared/organization/css/organization-main.css',
                 '/apps-shared/organization/css/main-<hash>.chunk.css',
                 '/apps-shared/organization/js/organization-main.mjs']) {
  await fetch(u, { cache: 'reload' })
}
location.reload()
```

Everything else in `USING-THE-THEME.md` applies as written. Read it, including
the new §12 on what the theme still does not provide.

---

## 4 & 5. Done

Sections 4 (cards, array rows, pagination) and 5 (the backups and handover
tabs) are complete — commits `6b2868d` and `21853d0`. What they said to do,
and what actually happened:

- **Cards.** `.iz-card` went from 0 uses to 10. The seven local blocks each
  re-declared border, radius and padding, and were consequently missing the
  surface and shadow entirely.
- **Array rows.** Member rows are `.iz-row--card`, matching `MembersPanel`.
  They are deliberately **not** `--expandable`: they already sit inside an
  expanded organization row, and a second nested disclosure reads badly. This
  is the one place the app diverges from superadminpage on purpose.
- **Pagination.** It had never been built. `src/components/ui/Pagination.vue`
  now wraps `.iz-pagination`; `total` is optional because none of these
  endpoints report one, so it infers "there is more" from a full page.
  Exercised by temporarily setting the page size to 3 and paging org 1's jobs.
- **Backups and handover.** 1,101 lines of local structural CSS became 407
  across five files. `src/components/jobs/JobSteps.vue` and `JobEvents.vue`
  are the shared timeline the plan asked for; `src/lib/jobs.ts` owns the one
  status→tone table. Details in the commit message.

Two of the four agreed additions landed here rather than earlier: **backup step
names** and the **rollback events timeline** (`GET /rollback-jobs/{id}` and its
`/events` had never been called).

**The theme gained five primitives**, because the detail panels were mostly
unstyled without them: `.iz-kv` (with a `--rows` variant), `.iz-steps`,
`.iz-log`, `.iz-code` and `.iz-meter--indeterminate`, plus the `--iz-font-mono`
token. `.iz-kv` earns its place twice over — besides four hand-rolled copies
across the apps, Nextcloud core ships `dt, dd { display: inline-block;
padding: 12px }` and `dt { width: 130px; text-align: end }` globally, so *every*
`<dl>` in *every* one of these apps has been rendering its labels right-aligned
in a 130px gutter. A two-line pair measured 104px tall before and 36px after.
`OverviewTab` was hit by this too and is now on `.iz-kv--rows`.

**Deploying the theme is a step**: `./deploy-docker.sh master-nextcloud-1` from
the theme repo. Editing the repo alone changes nothing — the container serves a
copy under `workspace/server/themes/`, and that cost half an hour here.

---

## 6. Verified, and not

**Verified in the browser, both schemes**, by reading computed styles rather
than screenshots: the three tabs, all five detail tabs, the org and plan lists,
trial defaults, and all five modals opening, prefilling and cancelling.

**Also verified**, against the live rows rather than by eye: 7 backup jobs on
org 1 (retention keeps 7 per org, so 14 across two), 4 steps each in order with
their human names, 3 events; 3 handover jobs, 3 steps, 10 events across two
levels, and the dry-run preview rendering as chips plus its warning.

**Not verified:** no form has actually been *submitted*. Creating an
organization, converting `testorg`, saving trial defaults, deleting a plan or a
backup — all the POST/PUT/DELETE paths are unexercised, because they mutate the
dev instance. The "Start transfer" ConfirmDialog was opened and cancelled, not
confirmed; a handover job cannot be deleted once created.

**Rollback rendering is structurally verified only.** This instance has **0
rollback jobs**, so the expandable rollback row, its validation block, its
impact chips and its new events timeline have never had real data through them.
Seed one — a dry run against backup #21 or #22, the only completed full backups
— before trusting that path.

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
