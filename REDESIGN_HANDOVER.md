# Handover: bring the `organization` app onto the In Zicht design

Paste this whole file as the opening prompt of a new session started in
`/home/payboy/src/nextcloud-docker-dev/data/apps-extra/organization`.

**The job:** three sibling apps have been migrated onto a shared theme and a
shared layout language. `organization` is the fourth and has had none of it.
Before any theming can happen its layout has to change — it is currently a
Nextcloud sidebar app and the other three are single-column dashboards.

**Do the design first, and do not start coding.** Brainstorm the layout with the
user and show options as an artifact before touching a component. The layout
decision is theirs, not yours.

---

## 1. What has already been done, and where

Four repos, all pushed and in sync as of 2026-08-06:

| Repo | Path | Head |
|---|---|---|
| Theme | `/home/payboy/src/inzicht-nextcloud-theme` | `1dd64bd` |
| adminpage | `../adminpage` (branch `main`) | `fc2f361` |
| superadminpage | `../superadminpage` (branch `master`) | `76d7180` |
| employee_dashboard | `../employee_dashboard` (branch `master`) | `e555ee3` |

Note the branch names differ — adminpage is `main`, the other two are `master`.

Over the preceding sessions those three apps were moved off their own hardcoded
palettes and onto the theme: token bridge, `.iz-*` primitives for chrome,
dead-code removal, one shared confirmation dialog, a shared Chart.js bridge, and
a full light/dark pass. `organization` was never touched.

---

## 2. Where the theming lives

**`/home/payboy/src/inzicht-nextcloud-theme/USING-THE-THEME.md` is the canonical
guide. Read it before writing any style.** It is shared by all the apps and is
the file to edit when a rule changes — do not copy its content into an app's
`CLAUDE.md`, which is exactly the drift it was created to end.

The CSS itself is section 8 of
`themes/inzicht/core/css/server.css` in that repo — the `.iz-*` primitives and
`--iz-*` tokens. Deploy with `./deploy-docker.sh master-nextcloud-1`. Never edit
the deployed copy inside the container or anything under
`nextcloud-docker-dev/workspace/`; both are build outputs.

The short version, but read the guide for the reasoning and the traps:

- An app's root element carries **`iz-app`**, which supplies the generic token
  names (`--bg-card`, `--color-text-primary`, `--accent`, `--radius-card`, the
  badge pairs, the spacing scale) and is the ancestor the `.iz-app`-scoped
  primitives require.
- **Chrome from the primitive, layout stays local.** Never hardcode a colour or
  a font size.
- **Adding a primitive class without deleting the local rule does nothing** —
  scoped CSS ties with the theme on specificity and wins on injection order.
  This bit twice; audit the live DOM rather than reading the file.
- Nextcloud core styles bare `button`/`input`/`select` at (0,1,1) and will fight
  you — the 34px `min-height`, the padding that crushes icon buttons, the
  `:focus` repaint, the `!important` focus outline.
- Check **both colour schemes** every time.

Each app's `CLAUDE.md` now points at the guide and keeps only its own
app-specific deltas. Read `../employee_dashboard/CLAUDE.md` for the most
recently written example.

---

## 3. What "match the layout" means, concretely

All three apps share one shape. This is what `organization` needs to look like.

**The container.** Root element is `<div class="<app>-dashboard iz-app">` with
`max-width: 1200px; margin: 0 auto; padding: var(--spacing-lg)`. Identical in all
three — check `Dashboard.vue` in each.

**Single column, no sidebar, no list/detail split.** Content is a vertical stack
of panels. Nothing is docked to the left, nothing splits the viewport.

**A header at the top.** `employee_dashboard` has the most developed version:
`DashboardHeader.vue` is sticky, carries identity and status counts on a top
tier that never collapses, a filter row that folds away once it sticks, and a
project switcher below. Worth reading before designing this app's header — the
sticky mechanics in it are subtle and are documented in its `CLAUDE.md`.

**A KPI strip under it, where the app has fleet-level numbers.** Four `.iz-kpi`
cards in `repeat(4, minmax(0, 1fr))`. `adminpage` and `superadminpage` both do
this. The `.iz-kpi` primitive encodes an alignment contract — the four cards'
headers, figures, charts and footers all line up — so use it rather than
rebuilding it.

**Then panels.** `.iz-panel` cards stacked down the page, most of them
collapsible with a header and a chevron. Use **`.iz-panel--list`** for any panel
that pads its own header and body, which is every collapsible one.

**Detail opens in place, not in a second pane.** Three patterns exist and all
three are already in use — expandable rows (`.iz-row--expandable`, the chevron
rotating 180°), a drawer panel below the list, or an `.iz-modal` with
`.iz-tabs`. Pick one for this app; do not invent a fourth.

Look at, in this order: `../employee_dashboard/src/components/Dashboard.vue` and
`DashboardHeader.vue`, then `../superadminpage/src/components/Dashboard.vue`
(closest domain — it also manages organizations), then
`../adminpage/src/components/Dashboard.vue`.

---

## 4. The state of `organization` — read this before planning

Four things are true and each one changes the shape of the work.

**a) The frontend is not on `main`.** The checked-out branch has **no frontend at
all** — 25 files, all PHP, no `src/`, no `package.json`, no navigation entry. The
UI is on **`origin/taha`, 38 commits ahead**, which also carries newer routes,
three extra background jobs, an admin settings section and
`max-version="34"` (`main` still says `31`). Everything in point (b) below
describes `origin/taha`.

**Decide what happens to that branch before anything else.** Redesigning against
`main` means designing against nothing; redesigning on `taha` and then having it
merged badly loses the work.

**b) Its current layout is the thing being replaced.** On `taha`:

- `src/App.vue` renders `AppNavigation` plus one of three views
- `AppNavigation.vue` is an `NcAppNavigation` sidebar — Organizations,
  Subscription Plans, Trial Settings
- `views/OrganizationsView.vue` and `views/PlansView.vue` are each an
  `NcAppContent` with a `#list` pane and a `#default` detail pane
- `views/SettingsView.vue` is a settings form, not a fleet view — it may not
  belong in a dashboard shape at all, and that is worth deciding explicitly
- `OrgDetails.vue` stacks six sections into the narrow detail pane: Contact
  Person, Subscription Details, Organization Settings, Storage Quotas, Members,
  Backups

**c) The stack does not match either.** `organization` is **Vue 3 + Vite +
TypeScript + `@nextcloud/vue` 9**. The other three are **Vue 2.7 + webpack +
plain JS with no component library**. "Match the layout" and "match the stack"
are two separate decisions — raise them separately with the user.

The theme is plain CSS and does not care which framework emitted the markup, so
the layout and the primitives can be adopted on Vue 3 as-is. The catch is the
vendored files: `ConfirmDialog.vue` and `src/lib/izChart.js` are Vue 2 SFCs
shared by the other apps, so a Vue 3 app cannot reuse them without a port or a
duplicate.

**d) Landing `taha` fixes a live bug elsewhere.** `adminpage`'s
`/api/backup-jobs` currently returns 500: it proxies to
`/ocs/v2.php/apps/organization/backups/jobs/my-organization`, which does not
exist on `main` and does exist on `taha`. Worth confirming once the branch
question is settled.

---

## 5. Conventions established across these repos

- **Bump `<version>` in `appinfo/info.xml` only when PHP changes** (`lib/`,
  `appinfo/`). Never for frontend-only work. The version is what makes Nextcloud
  run an upgrade cycle, and that cycle has side effects — it disabled two apps
  whose max-version predated NC34.
- **Never change `<id>` in `appinfo/info.xml`.** It must equal the directory
  name, and **production is the authority** on what that is. Changing an id to
  match a dev checkout broke production once already.
- **No `alert()` or `confirm()`.** Use the vendored `ConfirmDialog.vue` pattern —
  parent owns the busy flag and the error string, dialog never closes itself, so
  a failed action stays open with its reason.
- **Verify in the browser, in both schemes, and report what was observed** —
  measurements, not impressions. Several bugs in this work were only visible in
  computed styles.
- **Commit per logical change, and say why in the message**, including what was
  tried and rejected. The commit log in these four repos is written that way and
  is worth matching.
- **Push only when asked.**

---

## 6. Environment

**Start `nc_pg` before `master-nextcloud-1`.** If Nextcloud boots without its
database it runs `maintenance:install` and overwrites `config.php`, losing
`theme=inzicht`, the pgsql connection and the instance secrets. This has happened
three times. No data is lost, but the instance must be repaired by hand.

```bash
docker start nc_pg && sleep 8          # wait for pg_isready
docker start master-redis-1 master-database-mysql-1
docker start master-nextcloud-1 master-proxy-1
```

- **Live DB is PostgreSQL in `nc_pg`** — not `nc_db`, and not
  `master-database-mysql-1`, which holds an empty skeleton of the same schema
  that reads as real until you notice every table has zero rows.
  `docker exec nc_pg psql -U nextcloud -d nextcloud -c "..."`
- **occ:** `docker exec -u www-data master-nextcloud-1 php occ <cmd>`
- **Browser:** apps serve at `http://nextcloud.local:8080/index.php/apps/<id>/`.
  If `/etc/hosts` has lost its `nextcloud.local` line — WSL restarts drop it and
  there is no passwordless sudo — use the container IP instead
  (`docker inspect master-nextcloud-1`) after adding it to `trusted_domains`.
- **Reloading a page does not re-fetch the bundle or the theme CSS.**
  `fetch(url, {cache: 'reload'})` for each, then reload.
- **Simulate dark mode** by setting `data-themes="dark"` and `data-theme-dark=""`
  on both `<body>` and `<html>`.
- Real data: orgs `Test` (standard, admin_uid `admin2`, subscription active to
  Jul 2027, 3 members) and `testorg` (trial, `testadmin`, expired Jul 2026, 2
  members); two plans, both private. `admin2`'s password is `rootroot`.

---

## 7. Where to start

1. Settle the `origin/taha` question with the user — everything else depends on
   it.
2. Read `USING-THE-THEME.md`, then `employee_dashboard`'s `Dashboard.vue`,
   `DashboardHeader.vue` and `CLAUDE.md`.
3. Read `taha`'s `App.vue`, `AppNavigation.vue` and the three views to see what
   is actually being replaced.
4. Brainstorm the layout with the user and **show options in an artifact** —
   current shape versus proposals, using real data from the dev instance, and be
   explicit about which numbers are invented.
5. Only after the user picks: write the plan, then implement.

Ask about the stack (Vue 3 vs porting to 2.7) as its own question. Do not decide
it silently by picking a layout that assumes one.
