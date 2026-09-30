# SEO plugin — delivery plan

How the plugin gets built: **eight small features, each shippable on its
own**, in an order where every one leaves the site working and delivers
something you can see. The *what* and *why* live in
[`seo-plugin-design.md`](seo-plugin-design.md) (A = agreed decision, G = gap,
S = solution, D = deferred); this file is only the *order and slicing*.

Status of the plan itself: **order approved 2026-09-30** (F1 → F2 → F3 → F4 → F5 → F6 → F7 → F8, F2 and F3 kept as separate changes). F1–F3 are built (see `decisions.md`); the rest is planned.

## 1. Principles

- **Vertical slices.** Each feature ends in something observable on the live
  site or CLI (a URL that stops 404ing, a tag that appears), not a layer.
- **Main stays green.** Each feature lands with tests, `composer check`
  green, and the site rendering as before unless the feature says otherwise.
- **Lazy first.** Build the smallest thing that satisfies the agreed
  decision; anything speculative is in the design doc's Deferred list, not
  here.
- **One commit per feature, only when asked** (repo rule). Features are
  written so they can be committed separately.

**Definition of done (every feature):**

1. Tests for the new logic. **Plugin behavior is tested in the plugin**
   (`plugins/TheMusicDev/Seo/tests/`, own `--testsuite seo`, nothing there may
   depend on the host or another plugin — use the test subjects in
   `tests/Subject/`). Host classes (e.g. `App\Seo\JobPostingSubject`) are
   tested in the host's `tests/`. MariaDB test DB, children-first
   `deleteAll([])` in setUp.
2. `composer check` green (phpcs, phpstan level 8, phpunit).
3. Curl/browser check of the visible result on the dev server.
4. Docs updated: this file's status column, the design doc if a decision
   moved, plugin README, and the CLAUDE.md Seo entry/session log.
5. Left uncommitted until the maintainer says to commit.

## 2. Overview

| # | Feature | Delivers | Fixes | Depends on | Size | Status |
|---|---|---|---|---|---|---|
| F1 | Page index | `seo_pages` table filled by `bin/cake seo rebuild` from subjects | — (foundation) | — | M | **built** |
| F2 | Sitemap | `/sitemap.xml` + `/sitemap-index.xml` from live rows; numbered files past `pageSize` | G1 | F1 | S | **built** |
| F3 | Static pages | `StaticPageSubject` — home/about/contact/careers index in the index and sitemap (apply pages are per job and `noindex`, so not listed) | G1 (complete) | F1, F2 | S | **built** (uncommitted) |
| F4 | Head helper | `$this->Seo->head()` prints title, canonical, robots, OG, Twitter from the row | G3 | F1, F3 | M | planned |
| F5 | Structured data | JSON-LD built by subjects, stored, rendered as one `@graph`; `JobPosting` live on careers pages | G2 | F4 | M | planned |
| F6 | Redirects & 410 | old paths 301, removed pages 410, via plugin middleware | G5 | F1 | M | planned |
| F7 | robots.txt | generated from config, non-prod disallow-all | G4 | F2 | S | planned |
| F8 | Sync command | `bin/cake seo sync <subject> <id>` for `Queue.Execute` | — | F1 | S | planned |

Sizes: S ≈ a focused sitting, M ≈ a day or so, no estimate promised.

```
F1 ─┬─ F2 ─┬─ F3 ─── F4 ─── F5
    │      └─ F7
    ├─ F6
    └─ F8
```

**Suggested order: F1 → F2 → F3 → F4 → F5 → F6 → F7 → F8.** F2 is the first
visible win (crawlers get a real sitemap), F5 is the biggest SEO payoff, F6–F8
can slip without blocking anything before them. F6, F7 and F8 are independent
of each other and can be reordered freely.

## 3. Features

### F1. Page index

**Goal:** the plugin can turn subjects into rows in `seo_pages`, and keep them
correct.

**In scope**
- Migration: `seo_pages` with **every column the design needs, created once**
  so later features add no migration for it — `subject`, `subject_id`
  (unique together, A5), `path` (unique, indexed, A7), `title`,
  `description`, `robots`, `lastmod`, `schema` (JSON, nullable — used by F5),
  `status` (`live`|`gone`, A9), timestamps. Column names are working names.
- `SubjectInterface` (`rows()`, an id per row, `toPage($row)`), `PageData`
  value object, `TableSubject` base (`query()` finder → rows, primary key →
  id) (A4, A16).
- `Seo.subjects` config loading (`app_default.php` stays empty; host wins).
- `SeoPagesTable` + `SeoPage` entity.
- `bin/cake seo rebuild`: for every subject, upsert rows by (subject, id),
  mark rows no longer returned by the subject as `gone` (A9), revive a
  republished row with the same path. Prints counts per subject (A6).
- Host: `App\Seo\JobPostingSubject` (path `/careers/{slug}/`, title
  `meta_title ?: title`, description, lastmod `modified`), wired in
  `config/app.php`.

**Out of scope:** rendering anything, redirects when a path changes (F6),
`schema` content (F5).

**Done when:** rebuild on the dev DB creates one live row per published job
posting; unpublishing a posting and rebuilding marks it `gone`; republishing
revives it; running rebuild twice changes nothing.

**Risks / gotchas:** plugin migrations must run on both the dev and test DB
(`bin/cake migrations migrate -p TheMusicDev/Seo`, and the test DB via
`DATABASE_URL` override — see CLAUDE.md queue notes); `subject_id` is a
string even for integer keys; composite primary keys are unsupported (A5).

### F2. Sitemap

**Goal:** crawlers get a working sitemap (G1).

**In scope**
- Plugin routes (or middleware-free actions) for `/sitemap.xml` (index) and
  one file per subject, built from `live` rows only; `lastmod` from the row,
  omitted when null.
- `/sitemap-index.xml` also resolves, so the current `robots.txt` line stops
  pointing at a 404 (both URLs, or one redirecting to the other — decide at
  build; the design allows either as long as no dead pointer remains).
- Correct `Content-Type` (XML views on Cake 5 had a wrong-type gotcha).
- Absolute URLs built from the configured host (paths are stored relative,
  A7).

**Out of scope:** static pages (F3), generated `robots.txt` (F7). (Splitting a
subject into numbered files past `Seo.sitemap.pageSize` was added afterwards —
see `decisions.md`, scale pass.)

**Done when:** `curl -I /sitemap-index.xml` → 200 `application/xml`; the
listed job URLs match published postings exactly; an unpublished posting
disappears after a rebuild.

### F3. Static pages

**Goal:** the non-database pages are in the index and sitemap (A11).

**In scope**
- `StaticPageSubject` in the plugin (base) taking an array; host class
  `App\Seo\StaticPageSubject` lists home, about, contact, careers index,
  apply — each with string id, path, title, description.
- Removing an entry marks its page `gone` (A9); `lastmod` omitted (A11).
- Registered as `'static' => …` in `Seo.subjects`.

**Out of scope:** JSON-LD for static pages (F5), per-page overrides (D1).

**Done when:** rebuild shows the static pages as live rows; the sitemap lists
them alongside job postings (completes G1's "static pages plus published
postings").

### F4. Head helper

**Goal:** the layout's SEO tags come from the plugin (A17), with OG and
per-page type/image support (G3).

**In scope**
- `SeoHelper::head()`: finds the row by the current request path; prints
  `<title>`, description, canonical, robots, Open Graph and Twitter tags.
- Explicit override for pages whose URL is not their row's path
  (`$this->set('seoPage', [$subject, $id])`); a path with no row falls back
  to today's `metaTitle` / `metaDescription` view variables so nothing breaks
  while pages migrate.
- `PageData` grows `ogType` and `ogImage` (site default image from host
  config stays the fallback; `og:type` `article` for jobs).
- Absorb the interim `canonicalUrl` / `robots` view-variable overrides the host
  layout has today (paginated `/careers?page=N` is self-canonical, filtered
  lists are `noindex,follow`; query-string variants are not rows in `seo_pages`,
  design S12).
- Host: replace the hand-written head tags in `templates/layout/default.php`
  with `$this->Seo->head()`.

**Out of scope:** JSON-LD (F5); admin overrides (D1).

**Done when:** the rendered `<head>` of home, about, contact, careers index,
a job page and a 404 is **diffed before and after** and only intended
differences remain (job page `og:type`; no lost tags). Canonical is still
correct with a trailing slash.

**Risks:** the request-path lookup is one query per page view — measure; a
cache is a later option (A17).

### F5. Structured data

**Goal:** the biggest SEO payoff — `JobPosting` markup on careers pages
(G2), stored and rendered per A10, A13, A18.

**In scope**
- Helper builders: `Organization`, `WebSite`, `BreadcrumbList`, `JobPosting`
  (fields per G2: `datePosted`, `title`, `description`,
  `hiringOrganization` by `@id`, `jobLocationType` TELECOMMUTE when Remote,
  `employmentType` mapped from `job_type`, `baseSalary` **only if**
  `compensation` parses).
- `toPage()` returns a list of nodes; rebuild stores them in `seo_pages.schema`.
- Render: one `<script type="application/ld+json">` with a `@graph`, page
  nodes plus the site-wide `Organization`/`WebSite` from host config (A18),
  encoded with `JSON_HEX_TAG|AMP|APOS|QUOT`; the layout's old hand-written
  Organization block is removed.
- Host: `JobPostingSubject::toPage()` builds the `JobPosting` +
  `BreadcrumbList` nodes; static pages get breadcrumbs where sensible.

**Out of scope:** other schema types (hosts add their own, A13), overrides
(D1).

**Done when:** a test asserts a value containing `</script>` cannot break out
of the block; the job page's JSON-LD passes Google's Rich Results Test and
matches the visible page; a Remote job emits TELECOMMUTE and a
non-parsing compensation emits no `baseSalary`.

**Risks:** values that change without a save (a job's expiry, org name) rely
on rebuild — noted in design A10/section 10.

### F6. Redirects & 410

**Goal:** changed URLs 301, removed pages 410 (A8, A9, A14, A15; G5).

**In scope**
- Migration `seo_redirects` (`from_path` unique → page row).
- Rebuild detects a path change for an existing (subject, id) and records the
  old path as a redirect; a redirect back to a page's own current path is
  dropped; chains never form because redirects point at the row (A8).
- `PageData::redirectsFrom` seeds redirects (A14); a collision with another
  page's current path logs an error and the live page wins.
- Middleware in the plugin, before routing: `gone` page or redirect-to-gone →
  410; redirect entry → 301 to the row's current path (A15).
- Host wiring of the middleware in `Application::middleware()`.

**Out of scope:** old URLs with no successor page (D6).

**Done when:** change a posting's slug + rebuild → the old URL 301s to the new
one; unpublish + rebuild → the URL is 410 and off the sitemap; republish →
200 again with no leftover redirect; asset requests are not slowed
(middleware placed after the asset middleware).

**Risks:** one lookup per request (cache if profiling says so); rebuild must
run on deploy before traffic when routes change (design section 10).

### F7. robots.txt

**Goal:** a correct, generated `robots.txt` (A12; G4).

**In scope**
- Route + action rendering `robots.txt` from config: per-bot rules, sitemap
  line derived from the host, **non-production host disallows everything**.
- Delete `webroot/robots.txt` in the same change (a static file shadows the
  route).

**Out of scope:** noindex control (that is the head helper's `robots` tag,
F4).

**Done when:** production host output matches today's rules plus a correct
sitemap line; a non-production host returns `Disallow: /`; no static file
remains.

### F8. Sync command

**Goal:** near-live updates for clients that need them (A6, D3).

**In scope**
- `bin/cake seo sync <subject> <id>`: re-runs one row through its subject
  (path change → redirect, no longer public → `gone`).
- README recipe: queue it with `Queue.Execute` after publish/edit, including
  the production allow-list (`Queue.executeAllowedCommands`) gotcha.

**Out of scope:** automatic triggers (host wires per client, D3).

**Done when:** syncing one posting after an edit updates only that row; the
README recipe works end-to-end against a worker in the dev stack.

## 4. Release checklist (after F8)

- [ ] Plugin README complete (install, configure, `Seo.subjects` examples,
      the two host classes, the middleware wiring, gotchas).
- [ ] Reasoning moved from the tracker into `docs/decisions.md`, tracker
      reduced to a pointer as with the other plugins.
- [ ] `docs/conventions.md` plugin entry; CLAUDE.md Seo entry updated.
- [ ] `composer.json` version bumped from 0.1.0 to 1.0.0 (path repo, like the
      others).
- [ ] Root README perf/config sections mention the Seo keys.

## 5. After v1 (not planned yet)

From the design doc's Deferred list: per-page overrides (D1), audit command
(D2), automatic sync triggers (D3), multi-language (D4), search-engine
notification (D5), old URLs with no successor page (D6).

## 6. Open items for this plan

None. Order approved; F2 and F3 stay separate. F6–F8 may still be
reordered if priorities change.
