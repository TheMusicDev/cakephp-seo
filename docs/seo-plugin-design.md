# SEO plugin — design tracker

Living document. Its job is to record **what we agreed**, **what is only
proposed**, and **what is still open**, so nothing gets re-litigated or
forgotten. Update the status of an item when a decision is made; move the
reasoning into `decisions.md` once the plugin is being built.

**Status key**

| Status | Meaning |
|---|---|
| **Agreed** | Decided in conversation. Build to this. |
| **Proposed** | Suggested, not yet confirmed. Needs a yes/no. |
| **Open** | Real question, no decision yet. Needs discussion. |
| **Deferred** | Agreed to do later, not in v1. |
| **Rejected** | Decided against, with the reason. |

Last updated: 2026-09-30. Build order: [`delivery-plan.md`](delivery-plan.md).

---

## 1. Goal

One plugin that handles SEO for every TheMusicDev CakePHP site, so a new
content module (job postings today, a blog tomorrow) gets meta tags,
structured data and a sitemap entry **without writing any SEO code** and
**without depending on this plugin**.

## 2. Vocabulary (plain words)

- **Subject** — a source of public web pages. Usually a database table (job
  postings; a blog's posts would be another), but the site's static pages
  (home, about…) are a subject too (A11).
- **Subject class** (named `…Subject`, e.g. `JobPostingSubject`) — a small PHP class, written in the host, that knows how to
  turn one subject's rows into pages: which rows are public, what each page's
  path, title and description are, and what its JSON-LD says.
- **Page row** — one row in the plugin's own table (`seo_pages`) for one public
  page: its path, title, description, last-changed date, JSON-LD, and so on.
  The sitemap is built from these rows. A page row is identified by the
  subject's table alias plus the entity's primary key (A5).
- **JSON-LD** — a hidden `<script type="application/ld+json">` block in a
  page's HTML that describes the page to search engines in a fixed vocabulary
  (schema.org): "this is a job posting, posted on X, in Remote, paying Y".
  Google uses it for rich results such as Google for Jobs. It is separate from
  the visible page, from Open Graph (link-preview cards for social/chat apps)
  and from the ordinary `<meta>` tags.
- **Host** — the application using the plugin (this repo). Plugins are the
  reusable parts; the host owns site-specific things.

## 3. Where things stand today (host app, before the plugin does anything)

- `templates/layout/default.php` already outputs canonical, Open Graph,
  Twitter card tags and an Organization JSON-LD block, ported 1:1 from the
  Astro site.
- `robots.txt` advertises `/sitemap-index.xml`, which returns **404** — Astro
  generated it, the port never replaced it.
- Job postings have `meta_title` / `meta_description` columns but no
  `JobPosting` JSON-LD on `/careers/{slug}/`.
- `og:type` is always `website`; the OG image is one hardcoded default.

### 3a. The five original gaps

Found while comparing "build vs use an existing plugin" (2026-09-30). Each
was first answered "no plugin needed, fix it in the app". That verdict is
**superseded by A1** — we are building the plugin — but the gaps and the
fix details stand and become requirements. The plugin must make every row
below true.

| # | Gap | Fix (as first specified) | Original verdict | Now tracked as |
|---|---|---|---|---|
| G1 | **`/sitemap-index.xml` is 404, but `robots.txt` advertises it.** Astro's `@astrojs/sitemap` generated it and the port never replaced it, so crawlers get a broken pointer. | A sitemap action listing static pages plus published postings (`lastmod` from `modified`), with the `robots.txt` line and the route aliased to match (`/sitemap.xml` and `/sitemap-index.xml` must both resolve, or the robots line must point at the real one). Extract to the Recruiting plugin only if another site wants job entries. About 25 lines. | Not a plugin | S3, S8 |
| G2 | **No `JobPosting` JSON-LD on `/careers/{slug}`.** Biggest SEO win: Google for Jobs needs it. | Read the `JobPosting` entity and emit: `datePosted` ← `published_at`; `title`; `description` (HTML); `hiringOrganization` ← `BusinessInfo`; `jobLocationType` = `TELECOMMUTE` when location is Remote; `employmentType` mapped from `job_type`; `baseSalary` **only if** `compensation` parses (never emit a guessed number). | Not a plugin (element in the app, or the plugin's entity for reuse) | S4, A10, Q2 |
| G3 | **`og:type` is always `website`; `og:image` is hardcoded.** | Views can set `ogType` (`article` on jobs) and an optional `ogImage`; the site default stays the fallback. | Not a plugin | S2 |
| G4 | **`robots.txt` is static; Astro's was dynamic** (per-bot rules for Googlebot/Bingbot/Twitterbot/facebookexternalhit, and the sitemap URL derived from the site). | A static `webroot/robots.txt` is fine for now as long as the sitemap line is correct. | Not a plugin | S6 |
| G5 | **Dead job slugs after unpublish or trash return 404.** | Optionally return **410 Gone** for trashed postings. Skip until it matters. | Not a plugin | S7, A8, A9 |

## 4. Agreed decisions

- **A1. We build our own all-in-one plugin** (`themusicdev/cakephp-seo`). We do
  **not** extend or depend on `dereuromark/cakephp-meta`. Reason: it covers
  roughly 80% (head tags, and since 1.2.0 a few JSON-LD types) but the
  remaining 20% — per-content-type schema, sitemap, redirects — is the part
  that grows with every new module, and it would have to be rebuilt per
  site. Two owners of `<head>` output is worse than one.
- **A2. Decoupling.** Domain plugins (Recruiting, a future blog) contain no SEO
  code and no knowledge of this plugin. Only the host connects them.
- **A3. The host owns `Seo.subjects`** in its `config/app.php`: a map of
  table alias → subject class (A4). Reason: the public URL is not the
  plugin's to know — in this repo the `/careers/{slug}/` routes and
  `JobsController` are host-side, and another site could route postings
  elsewhere. (Note: a plugin's own `config/app_default.php` *does* load from
  `vendor/`; the reason is URL ownership, not file-editing.) Domain plugins
  ship no Seo config; their README may carry a copy-paste snippet.
- **A4. One subject class per subject, no behaviors.** The class lives in the
  host and has two jobs: `query()` returns the public rows (it knows about
  `deleted_at`, `published`, or whatever that table uses), and
  `toPage($row)` (for table-backed subjects, `pageFor(EntityInterface $row)`
  on the `TableSubject` base) turns one row into a page (path, title, description,
  lastmod, robots, JSON-LD). Underneath, the interface is not tied to a
  table — a subject supplies `rows()`, an id per row, and `toPage($row)` — so
  static pages fit (A11). A `TableSubject` base class covers the common case:
  `rows()` comes from the `query()` finder and the id is the primary key. Any PHP is allowed, so a title can be
  "tag + title", a path can be date-based, and no table has to have a `slug`
  or a `title` column. The page table is kept up to date by the rebuild
  command (A6); a per-row `sync($row)` on the same class is available for
  later. Replaces the earlier idea of a behavior attached to each table —
  see R1.

  Illustrative sketch (names not final):

  ```php
  // host: src/Seo/JobPostingSubject.php
  final class JobPostingSubject extends TableSubject
  {
      public function query(Table $table): SelectQuery
      {
          return $table->find('published');
      }

      protected function pageFor(EntityInterface $row): PageData
      {
          return new PageData(
              path: '/careers/' . $row->slug . '/',
              title: $row->meta_title ?: $row->title,
              description: $row->meta_description,
              lastmod: $row->modified,
              schema: [ /* JobPosting JSON-LD, see G2 */ ],
          );
      }
  }

  // host: config/app.php
  'Seo' => ['subjects' => [
      'TheMusicDev/Recruiting.JobPostings' => \App\Seo\JobPostingSubject::class,
  ]],
  ```
- **A5. A page row is keyed by subject + primary key.** Columns
  `subject` (the key used in `Seo.subjects` — a table alias for table-backed
  subjects, a free string such as `static` otherwise) and `subject_id` (the
  entity's primary key, or a fixed string such as `home` for a static page,
  stored as a string), unique together. That is how a given entity finds its
  row. Composite primary keys are not supported in v1. Rows are matched by
  this key — not by path — so a changed path is detected (A8).
- **A6. `bin/cake seo rebuild`** rebuilds every page row from the subject
  classes and is the source of truth: it also does the first fill on an
  existing database. Run on deploy and nightly via QueueScheduler (already
  installed). Because nothing depends on save events, fixture factories,
  `deleteAll()`, raw SQL and imports cannot cause drift the rebuild won't fix.
  For a client who needs near-live updates: queue a one-row sync with
  `Queue.Execute` (`bin/cake seo sync <subject> <id>`) after a publish/edit.
  See section 10 for the allow-list gotcha.
- **A7. Paths are strings, built by the subject class.** e.g.
  `/careers/some-role/` — no scheme or host, not a Cake route array, and not
  a `{slug}` pattern in config. Reasons: (1) a route array cannot reveal a
  URL change — `/jobs/{slug}` becoming `/careers/YYMMDD/{slug}` is the same
  array but a different URL, and search engines know the URL, not the array;
  (2) a pattern would assume every table has a slug. The path is the lookup
  key for a request and is stored exactly as the canonical tag emits it
  (trailing slash included). The host name comes from config at render time,
  so a domain change needs no rebuild. **The page's URL and its canonical
  URL are the same value here.** (Canonical means "the one preferred public
  URL" — the pretty one. If an ugly duplicate such as `/recruitment/view/5`
  were also reachable, *its* canonical tag would point at the pretty path.)
- **A8. Stale paths become 301 redirects** (maintainer's idea). When rebuild
  or sync computes a different path for a row that already exists (matched
  by A5), the old path is recorded as a redirect. The redirect points at the
  **page row**, not at a target string, so the target is resolved at request
  time and chains (`/a`→`/b`→`/c`) never form. Stored in a separate
  `seo_redirects` table (`from_path` unique → page row).
- **A9. Removed pages return 410.** Rebuild/sync re-checks `query()` for every
  row: a page that is no longer public (unpublished, trashed) is **kept** and
  marked `gone` — dropped from the sitemap, its path and any redirects to it
  answer **410 Gone**. If the entity is republished the row is revived with
  the same path and no redirect is needed. Covers G5.
- **A10. JSON-LD is built once by the subject class and stored on the page row
  in a JSON column.** The shape differs per schema type — `JobPosting`,
  `Article`, `Product` share almost no fields — so it cannot be columns on
  the row; JSON is the only storage that fits. Rendering is one lookup by
  path and no entity is needed, so static pages work too. Rules:
  - **Store JSON, re-encode at output** with
    `JSON_HEX_TAG|AMP|APOS|QUOT` so a `</script>` inside any value cannot
    break out of the block (the XSS bug `cakephp-meta` had to patch in
    1.2.0).
  - Values that change without a save (a job's `validThrough` passing, the
    organization name from `BusinessInfo`) are refreshed by the deploy and
    nightly rebuild. Google wants structured data to match the visible page,
    so this is the known cost of storing.
  - Computing it live at render time was considered and dropped (R4).
- **A11. Static pages are a `StaticPageSubject`** — a host class holding an
  array of the site's non-database pages (home, about, contact, careers index,
  apply…), each with a string id, path, title, description and optional
  JSON-LD. It is registered in `Seo.subjects` like any other subject
  (`'static' => \App\Seo\StaticPageSubject::class`) and `rebuild` upserts it
  into `seo_pages`, so everything lives in one table and one mechanism.
  Removing an entry from the array marks that page `gone` (A9). Static pages
  have no modified date, so their sitemap `lastmod` is left out unless the
  array supplies one — a fake date is worse than none. Closes Q4.
- **A12. `robots.txt` is generated from config** (covers G4). Kept in the plugin
  because it is easy to get wrong; the sitemap URL is derived from the host,
  and per-bot rules are config, not a static file. Requirements:
  - **Non-production hosts disallow everything;** only the production host
    allows crawling. Staging must never be indexable.
  - **Delete `webroot/robots.txt` when this ships.** A static file is served
    by the web server before Cake sees the request and would shadow the
    generated one.
  - **`Disallow` does not remove a page from search results** — it only stops
    crawling. Keeping a page out of the index is a `noindex` robots meta tag
    (S1), not a `robots.txt` rule.
- **A13. Schema types are not registered — subjects return the JSON-LD
  themselves.** `toPage()` returns the JSON-LD nodes directly, so a host can
  emit any schema.org type without waiting for a plugin update. The plugin
  ships **helper builders** that make writing the array less tedious:
  Organization and JobPosting in v1; more (Article, …) arrive with the first
  module that needs them. A page's schema is a **list of nodes**, rendered as
  one `@graph`: one subject may emit several nodes per page (a job page:
  `JobPosting` + `BreadcrumbList`), one type may come from several subjects,
  and one subject may vary by row (a `type` column choosing `Article` vs
  `NewsArticle`). Closes Q2.
- **A14. A page can declare its old URLs.** The page returned by `toPage()`
  may include `redirectsFrom` — a list of old paths (from a legacy column, a
  slug-history table, an import). Rebuild upserts each into `seo_redirects`
  pointing at that page's row, alongside the auto-detected ones (A8). The
  class can only list URLs it can know; it cannot invent them. An old path
  that collides with another page's current path is logged as an error and
  the live page wins. Old URLs with no successor page are v2 (D6).
- **A15. Redirects and 410s are served by a middleware in the plugin.** It
  looks up the request path: a `gone` page (or a redirect to one) answers
  **410**, a redirect entry answers **301** to the page's current path. It
  runs before routing and the controller, so an unpublished posting whose
  route still exists never reaches its controller. Placement in the queue and
  caching of the lookup are build-time details (see section 10).
- **A16. Names.** Code: `SubjectInterface`, `TableSubject`,
  `StaticPageSubject`, and host classes named `…Subject`
  (`JobPostingSubject`). Tables: `seo_pages`, `seo_redirects`. Plugin:
  `themusicdev/cakephp-seo`. Retires the "subject converter" wording.
  Closes Q7.
- **A17. The layout helper finds the page row by request path.**
  `$this->Seo->head()` matches the current request path against `seo_pages`
  (stored paths are the canonical paths, A7), so controllers do nothing and
  static pages work the same way. An explicit override stays available for a
  page whose URL is not its row's path, e.g.
  `$this->set('seoPage', [$subject, $id])`. A path with no row falls back to
  today's `metaTitle` / `metaDescription` view variables. Costs one indexed
  query per page view; cacheable later. Closes Q6.
- **A18. Site-wide JSON-LD nodes** (`Organization`, `WebSite`) come from host
  config and are appended by the helper to **every public page's** `@graph`
  (not only static pages), replacing the hand-written Organization block in
  today's layout, which already prints on every page. Page nodes reference
  them by `@id` (e.g. a `JobPosting`'s `hiringOrganization`) instead of
  repeating the company data. Google is fine with Organization on every page
  though it mainly cares about the home page; a config option can restrict
  it to the home page if ever wanted.

## 5. Outcomes of the earlier proposals (P1–P6)

| # | Proposal | Outcome |
|---|---|---|
| P1 | Attach a behavior to subject tables automatically | **Rejected** → replaced by subject classes (A4, R1) |
| P2 | `bin/cake seo rebuild` | **Agreed** (A6) |
| P3 | Store string paths, not absolute URLs | **Agreed, refined** (A7) |
| P4 | Re-check the public scope on every save | **Agreed** (A9), now done by rebuild/sync via `query()` |
| P5 | Safe JSON-LD encoding | **Agreed** (A10) — question raised whether to store JSON; answered A10 |
| P6 | Named presets | **Dropped** — not needed until a second site wants the same mapping; a plugin shipping a subject class would also re-couple it to Seo (R5) |

### Still proposed (needs a yes or no)

None.

## 6. Rejected

- **R1. Behaviors on subject tables.** A behavior cannot know a table's
  soft-delete column, and cannot build a title like "tag + title" or a path
  without a slug. Subject classes can, and rebuild needs no events. A
  per-row `sync` on the subject class gives live updates later if wanted.
- **R2. Cake route arrays as the stored URL.** Cannot detect that a URL
  changed (A7).
- **R3. Depending on or extending `dereuromark/cakephp-meta`.** See A1.
- **R4. Computing JSON-LD at render time.** Would need an entity on every
  page and cannot serve static pages; storing a built JSON is the same work
  done once (A10).
- **R5. Domain plugins shipping Seo config or subject classes.** Would couple
  them to Seo and cannot know the host's URLs (A2, A3).
- **R6. Analytics / tracking tags (GA-style) in this plugin.** Not SEO. This
  site's tracking is umami and belongs in the layout.

## 7. Deferred

- **D1. Per-page overrides** — hand-authored JSON-LD (e.g. an `FAQPage`),
  and possibly title/description/noindex overrides. Wanted later "if need
  be", not v1. Why it is harder than it looks: it needs a stable row identity
  that survives rebuild (A5 gives us that), rules for what wins when an
  override and a regenerated value disagree, validation of hand-written JSON
  (an XSS surface), and an admin UI. When it comes, keep generated and manual
  values in separate columns so a rebuild never overwrites a hand edit.
- **D2. Audit command** (missing / duplicate descriptions, structured data
  that disagrees with the page) — natural sibling of Setup's
  `bin/cake healthcheck`.
- **D3. Per-row sync triggers** — an admin publish action, a listener or a
  queued job calling the subject class's `sync($row)`; exists in the API from
  day one, wired per client when needed. Near-live clients use
  `Queue.Execute` (A6).
- **D4. Multi-language (hreflang)** — v2. Deliberately not thought about yet;
  the single-language assumption is fine until then.
- **D5. Notifying search engines when pages change** — v2, hung off the
  per-row `sync` (D3). Candidates: **IndexNow** (Bing/Yandex push protocol)
  and **Google's Indexing API**, which officially supports only pages with
  `JobPosting` markup — a fit for the careers pages (publish or remove a
  posting → notify). Google's old sitemap "ping" endpoint was retired in 2023
  (recalled, verify before relying on it), so today discovery is the
  `robots.txt` sitemap line plus accurate `lastmod`. Search Console
  reporting (submit sitemap, read indexing status) is not a plugin feature;
  an MCP covers it.
- **D6. Old URLs with no successor page** — v2. Options when it comes: attach
  them to a hub page's `redirectsFrom` (the old `/jobs/` on the careers index
  entry), a static entry marked `gone` for a permanent 410, or bulk regex
  rules in the web server config.

## 8. SEO solutions to address — status of each

| # | Solution | Status | Notes |
|---|---|---|---|
| S1 | **Head tags**: title, description, canonical, robots | Agreed (goal) | Moves out of the layout into the plugin, rendered from the page row. The layout helper finds the page row by request path (A17). |
| S2 | **Open Graph + Twitter cards** | Agreed (goal) | Needs `og:type` per page and a per-page or default image (today hardcoded). Covers G3. |
| S3 | **XML sitemap** (index + one file per subject) | Agreed (goal) | Built from `live` page rows. Fixes today's 404 on `/sitemap-index.xml` (G1). Both `/sitemap.xml` and `/sitemap-index.xml` must resolve, or `robots.txt` must name the real one. Static pages omit `lastmod` (A11). |
| S4 | **Structured data (JSON-LD)** | Agreed (goal) | Built by subject classes, stored as JSON (A10). `JobPosting` field mapping is specified in G2 and is the first concrete requirement. Types are not registered: helper builders for Organization + JobPosting ship in v1, hosts add their own (A13). |
| S5 | **Per-page overrides** (custom title, description, noindex, JSON-LD) | Deferred (D1) | Explicitly skipped for v1 for JSON-LD; title/description/noindex overrides not discussed separately. |
| S6 | **robots.txt generated from config** | Agreed (A12) | Covers G4. Astro had per-bot rules and derived the sitemap URL. Non-production disallows all; the static file must be deleted; `Disallow` is not `noindex`. |
| S7 | **Redirects and 410 Gone** | Agreed (goal) | 301 on path change (A8) and 410 for removed pages (A9). Covers G5. Served by plugin middleware (A15); declared old URLs via `redirectsFrom` (A14); old URLs with no successor page are v2 (D6). |
| S8 | **Static pages** (home, about, contact) | Agreed (A11) | A `StaticPageSubject` holding an array of pages; same table, same mechanism. |
| S9 | **Audit command** | Deferred (D2) | |
| S10 | **Multi-language (hreflang)** | Deferred (D4) | v2. |
| S11a | **Search-engine notification** (IndexNow, Google Indexing API for job postings; Search Console reporting is not a plugin feature) | Deferred (D5) | v2. |
| S11b | **Analytics / tracking tags** (GA-style) | Rejected (R6) | Not SEO; umami lives in the layout. |
| S12 | **Paginated and filtered listing pages** | Proposed (interim: host layout) | `/careers` is paginated with server-side filters (2026-09-30). Rules in use: each page self-canonical **including its query string**, filtered pages `noindex,follow`, out-of-range page 404. Page rows are keyed by path without the query string (A7), so `?page=2` and `?location=…` variants are not rows; the head helper (F4) must accept a canonical/robots override for them instead of resolving to the `/careers/` row (which would canonicalize page 2 to page 1). Today the layout takes `canonicalUrl` / `robots` view variables. |

## 9. Open questions (need a decision)

None. The design is fully decided; build order is in
[`delivery-plan.md`](delivery-plan.md).

*Closed:* Q1 (store JSON-LD) → A10. Q2 (v1 schema types) → A13. Q3 (redirects
not tied to a page) → A14, D6. Q4 (static pages) → A11. Q5 (admin override
UI) → moot until D1. Q6 (how templates use it; where redirects/410 run) →
A17, A15. Q7 (naming) → A16. PR2 (site-wide nodes) → A18. Q8 (backfill on an existing database) → A6:
rebuild does the first fill; migrations only create the tables.

## 10. Gotchas and limits (design-level)

- **Sitemap staleness between rebuilds.** Nothing updates the page rows in
  real time by default. An unpublished posting stays listed, and a changed
  slug 404s its old URL, until the next rebuild or sync. Mitigations: rebuild
  on deploy + nightly (A6), and per-row `sync` after publish/edit where a
  client needs it (D3).
- **`Queue.Execute` needs an allow-list in production.** Outside debug mode
  the command must appear verbatim in `Queue.executeAllowedCommands` (e.g.
  `'bin/cake'`); if it is empty every Execute job is rejected. Arguments are
  shell-escaped per token by default; only ever pass a subject key and a
  numeric id, never free text.
- **Composite primary keys are unsupported in v1** (A5).
- **`robots.txt`:** a leftover `webroot/robots.txt` silently shadows the
  generated route, and `Disallow` never de-indexes (A12).
- **The redirect/410 middleware adds a lookup to every request** (A15). It
  must run after the asset and `security.txt` middleware and before routing;
  cache the lookup if it shows up in profiling. Because it runs before the
  controller, an unpublished posting answers 410 from the middleware, not a
  404 from `JobsController`.
- **First rebuild after a route change.** A changed path is only turned into
  a 301 when rebuild runs; run it on deploy before serving traffic.

## 11. Conversation record

- 2026-09-30 — Compared building on `dereuromark/cakephp-meta` with building
  our own. Chose our own (A1). Discussed `Seo.subjects` at length; the
  maintainer pointed out plugins are published and cannot be edited in
  `vendor/`, so the host must own the config (A3, refined to URL ownership).
  The maintainer proposed a behavior + `seo_pages` table — better than the
  original "read every table live" idea for sitemap scale and auditing, at
  the price of drift.
- 2026-09-30 — The maintainer asked that the five gaps from the first
  comparison be tracked in the docs; added section 3a. Their original "no
  plugin needed" verdicts are kept in the table but marked superseded by A1.
- 2026-09-30 — The maintainer answered P1–P6. Dropped behaviors in favour of
  a configurable class that turns a row into a subject (their objections: a
  behavior cannot know `deleted_at`, or build "tag + title") and accepted
  rebuild-only sync with `Queue.Execute` for near-live clients. Rejected
  route arrays as the stored URL (cannot reveal a URL change), rejected
  `{slug}` patterns (assumes every table has a slug). Asked whether URL and
  canonical URL differ — no, same value here (A7). Proposed the 301 on
  path change (A8). Asked why JSON-LD would be stored rather than built from
  columns; the shape differs per type so it cannot be columns, and decided
  to store the JSON built once by the subject class (A10), overriding the
  suggestion to compute it live. Skipped `schema_override` for v1 (D1).
- 2026-09-30 — The maintainer reviewed section 8. S6: yes, generate
  `robots.txt` from config because it is tricky (A12). S8: static pages get a
  file-based array in one place, upserted into the same table — a
  `StaticPageSubject` so nothing is a special case (A11); this made the
  subject interface table-independent and closed Q4. S10: multi-language is
  v2 (D4). S11 was split: search-engine notification deferred (D5), GA-style
  tracking rejected as out of scope (R6).
- 2026-09-30 — The maintainer reviewed section 9. Q2: no registry needed —
  nothing stops hosts emitting any schema type today, the plugin supplies
  helper builders (A13); a page emits a list of nodes. Q3: a subject's page
  may declare `redirectsFrom` (A14); old URLs with no successor page wait
  for v2 (D6). Q6: redirects and 410s live in a middleware inside the plugin
  (A15); the layout-helper lookup by request path (PR1) is proposed and
  awaiting a yes. Q7: names approved (A16).
- 2026-09-30 — The maintainer approved PR1 (A17). Asked whether PR2 meant all
  pages or only static ones: all public pages — the layout already prints
  Organization everywhere; PR2 moves it into the plugin. Awaiting a yes.
- 2026-09-30 — The maintainer approved PR2 (A18) — the design has no open
  questions. Next: split the plugin into deliverable features
  (`delivery-plan.md`).
