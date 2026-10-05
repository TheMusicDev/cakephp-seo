# SEO plugin — build decisions & record

Why + what bit us while building. The **design of record** is
[seo-plugin-design.md](seo-plugin-design.md) (what we decided and why);
[delivery-plan.md](delivery-plan.md) is the build order. This file records
decisions made *during* the build and the gotchas the code hit.

## F8 — sync command (built 2026-10-01)

**Decisions**

- **`SubjectInterface::row(string $id): mixed`** is the one new method a subject needs: the
  row if it is public *now*, else null — the same scope as `rows()`. `TableSubject` runs
  `query()` plus a primary-key `where`, so a draft or trashed row is "not public" without
  host code; `StaticPageSubject` checks its list. (A host that implements the interface
  directly, not through the two bases, must add it.)
- **`PageIndexer::sync($key, $id)` reuses `store()`** — the code path that writes, records
  moved/declared redirects and counts is the rebuild's, fed one row. It loads only that
  row's stored copy, plus the pages holding its new path and its declared old paths.
- **A path held by another *live* page is a conflict and nothing is saved.** A rebuild can
  look at the whole run and tell a swap (`a`↔`b`) from a clash; one row cannot, so it
  refuses and says to run `seo rebuild`. A path held by a `gone` page is reused (that page
  is deleted, its redirects cascade), as in a rebuild.
- **Only this row is touched**: no gone-marking of other rows, no global sweep. The one
  redirect cleanup is scoped to this page's path (a redirect that started where the page now
  is is dropped — the page wins), instead of `dropRedirectsThatAreNowPages()`'s whole-table join.
- **Unknown subject → `InvalidArgumentException` (exit 1)**; an id that is not public and was
  never indexed is a quiet no-op (a queued job for a row deleted meanwhile must not fail and
  retry).
- **No automatic trigger** (D3): the host decides where to queue it; the README has the recipe.

**Verified end to end on the dev stack:** edit the dev job's title in the database →
`bin/cake queue add Queue.Execute "bin/cake seo sync …"` → `bin/cake queue run` → the job
completed and `seo_pages.title` changed, nothing else. (Restored afterwards.)

**Gotchas**

- **The task key is `Queue.Execute`**, not `Execute`: `queue add Execute …` answers "Not a
  supported task."
- **With debug off the job fails** with `Command \`bin/cake\` is not in
  Queue.executeAllowedCommands allow-list` until the host lists `bin/cake` — reproduced with
  `DEBUG=false bin/cake queue run`. That allows every `bin/cake` command from a queued job.
- **Test classes cannot define a method named `status()` or `run()`** (final in PHPUnit).

## F7 — robots.txt (built 2026-10-01)

**Decisions**

- **`/robots.txt` is generated** by the plugin (`RobotsController` + `Lib\Robots`, route named
  `seo.robots`) from `Seo.robots`, so the rules are config, not a file (A12, G4). The
  `Sitemap:` line is built from the named `seo.sitemap` route, never typed — it is the same
  `/sitemap-index.xml` the plugin serves, absolute from the app's base URL.
- **Only listed hosts may be crawled.** `Seo.robots.allowHosts` is a list of hostnames (no
  port, compared case-insensitively). A request for any other host — staging, a preview,
  `localhost`, a look-alike such as `themusicdev.llc.evil.example` — gets
  `User-agent: *` / `Disallow: /` and **no** sitemap line (advertising a map of pages it just
  forbade crawling would contradict itself, and on staging would leak it). A leading comment
  line says why, since a developer curling it otherwise only sees `Disallow: /`. An **empty** list means no
  restriction, so a site that forgets to configure it stays crawlable instead of being blocked
  from Google by surprise; the cost is that every real deployment must set it (the host does).
- **Rules are groups** (`userAgent`, `allow`, `disallow`). Crawlers obey only their single most
  specific group, so the host uses **one `*` group** carrying `Disallow: /admin` and `/files`;
  the four per-bot groups the Astro file listed (Googlebot, Bingbot, Twitterbot,
  facebookexternalhit, each `Allow: /`) were no-ops, and keeping them would have required
  repeating the disallows in each.
- **Config cannot inject directives:** CR/LF are stripped from every value, empty values and
  agent-less groups are dropped (`testLineBreaksInConfigCannotInjectDirectives`).
- **The renderer is a pure function** (`Robots::render($config, $host, $sitemapUrl)`), tested
  without the framework; the controller only supplies the host and the sitemap URL.
- **The static `webroot/robots.txt` is deleted**: the web server serves a real file before PHP
  sees the request, so it would silently shadow the route (`RobotsSitemapLinkTest` fails if one
  comes back). `/robots.txt` is in `Seo.redirects.skip` (it is never a page, and a crawler hits
  it constantly).

**Gotchas**

- **`Disallow` stops crawling; it does not remove anything from search results** — that takes
  `noindex`. A non-production host that was ever indexed needs `noindex` / removal, not just
  this file. (Not built; the apply and filtered pages are `noindex` in their meta tags and are
  deliberately NOT disallowed, since a crawler that cannot fetch a page cannot see its `noindex`.)
- **The sitemap line's scheme/host come from `App.fullBaseUrl`,** not the request host: set
  `APP_FULL_BASE_URL` in production (the dev server shows `http://` and the request host).
- **A crawler's single-group rule is easy to get wrong:** a path blocked only in the `*` group
  is *not* blocked for a crawler that has its own group.

## F6 — redirects and 410 (built 2026-10-01)

**Decisions**

- **`seo_redirects`** (`from_path` unique, `seo_page_id`, `source`, `created`): an old path
  that 301s to a page. It points at the page **row**, not a target path, so the target is
  resolved at request time and a page that moves twice leaves `/a` and `/b` both pointing at
  the same row — nothing ever chains (A8). `source` is `moved` (the rebuild saw the stored
  path change) or `declared` (the subject listed it in `PageData::$redirectsFrom`, A14).
- **The rebuild records moves and syncs declarations while it streams.** A changed path
  adds a `moved` redirect from the old one; `redirectsFrom` is part of the checksum, so
  unchanged pages cost nothing and a changed list re-syncs (dropped declared paths go,
  `moved` ones stay). Paths are normalized (one leading slash, none trailing, no root).
- **A live path is never also a redirect.** After the run, any redirect whose old path is
  now some page's own path (it came back into use, or another page took it) is deleted —
  the page wins. A dropped `declared` one is reported as a conflict (exit code non-zero);
  a `moved` one is silent. A declared old path that is already another page's path is
  skipped and reported straight away.
- **Deleting a page deletes its redirects** — FK `ON DELETE CASCADE` on
  `seo_redirects.seo_page_id`.
- **The middleware (A15)** runs after TrailingSlash and before routing, GET/HEAD only:
  a path that is a **gone** page → **410**; an old path → **301** to the page's current
  path (query kept) or 410 when that page is gone; a live page passes through with its row
  attached as the request attribute `seo.page` (the head helper reuses it instead of querying
  again); anything else passes untouched. An unpublished or trashed job therefore answers
  410 *before* the controller can 404 (G5).
- **It fails open.** If the lookup throws (database down) the request goes through and the
  failure is logged: a missing redirect must never take a page down. `Seo.redirects.skip`
  lists path prefixes that are never looked up (the host skips `/admin`, `/files`, `/setup`
  and `/health` — the health probe must stay database-free). Prefixes match whole path
  segments (`/admin` skips `/admin/jobs`, not `/administer`).
- **A 410 is a `GoneException`**, so the normal error handling renders it; Cake maps every
  4xx to one `error400` template, so that template is code-aware ("This page is gone" for 410).
- **Only Seo pages are covered.** The apply page of a removed job is not a page row: it is the
  controller's own 404. Old URLs with no successor stay a 404 (D6, v2).

**Gotchas**

- **Tests clear `seo_redirects` before `seo_pages`** (children first, the usual rule); the
  FK also cascades, so clearing the pages alone works on MariaDB too.
- **A renamed job's old URL *with* a trailing slash takes two hops** (the slash is stripped
  first, then the 301) — each hop is a single permanent redirect, no chain of redirect rows.
- **Cost:** one indexed query per non-skipped request (two for an old path that misses the
  page table); the head helper adds none because of `seo.page`.
- **`dropRedirectsThatAreNowPages` uses a raw join** (`p.path = SeoRedirects.from_path`); the
  ORM has no association between the two on a non-key column.
- **Cake's `GoneException`/`error400` copy:** debug mode shows the dev error page, so the
  410 wording is tested with `debug` off.

## F5 — structured data (built 2026-10-01)

**Decisions**

- **One JSON-LD block per page, one `@graph`.** `SeoHelper::head()` ends with a
  single `<script type="application/ld+json">` holding the **site-wide nodes**
  (`Seo.site.schema`: Organization, WebSite — built in the host from `BusinessInfo`)
  followed by the **page row's own nodes** (`seo_pages.schema`). Pages with no row
  (apply pages, errors) still get the site-wide nodes; a `gone` row contributes
  nothing. The layout's hand-written Organization block is gone (A18).
- **The subject builds the nodes, the row stores them** (A10). `seo_pages.schema` is
  typed `json` in `SeoPagesTable`, so the entity holds a PHP array; it is part of the
  checksum, so a changed node is detected by the rebuild.
- **`Schema` builders, not a registry** (A13): `organization`, `website`,
  `breadcrumbs`, `jobPosting` return plain arrays and leave out every null/empty
  part. Hosts return any other type as a plain array. Pay is emitted only with a
  valid unit and a **positive** amount (0 is "no pay"); an `employmentType` outside
  schema.org's list is dropped, never guessed; a range needs two different amounts.
- **Paths in `url` / `item` become absolute at render**, like the canonical. Nodes are
  stored with paths, so a rebuild run in the CLI with the wrong base URL cannot bake
  `http://localhost` into the stored JSON.
- **`hiringOrganization` is embedded (`@type`, `@id`, `name`), not a bare `{"@id"}`.**
  The `@id` joins it to the graph's Organization node; the inline `name` keeps parsers
  that do not resolve node references (Google's `JobPosting` wants a name) satisfied.
  Worth re-checking with Google's Rich Results Test once deployed.
- **Policy lives in the host's `JobPostingSubject`, not the plugin:** country (`US`) for
  remote applicants and on-site jobs, currency (`USD`), the job-type → `employmentType`
  mapping (Full-time, Part-time, Contract, Temporary, Internship, Volunteer, Per diem;
  anything else is omitted), and "location is `Remote`" ⇒ `TELECOMMUTE` (Google
  requires `applicantLocationRequirements` for remote jobs, so the country is always sent).
- **The description is the rendered sections** (`<h2>` + HTML), exactly what the visitor
  reads — Google requires the markup to match the page.
- **Structured pay and an expiry are Recruiting columns** (`salary_min`, `salary_max`,
  `salary_unit`, `valid_through`), optional, edited under "For search engines" in the
  admin job form. `compensation` stays the free-text display value. "Apply by" is an
  inclusive date, stored as the last second of that day (`validThrough`).
- **Job pages carry a `BreadcrumbList`** (Home › Careers › the job).

**Gotchas**

- **Cake only validates a field that is present in the data**, so "an amount needs a
  unit" is also a conditional `requirePresence`; the admin form always sends every key,
  which hid the gap until a table-level test posted a payload without `salary_unit`.
- **`(string)` on a `DateTime` is locale-formatted**, not `Y-m-d`; the date input gets
  its value from `->format('Y-m-d')`. Blank new fields are `null`, not `''`, so a
  template must not assume `$posting` is set on the add form.
- **A migration that adds columns needs `bin/cake schema_cache clear`**, again.
- **The HEX flags** (`JSON_HEX_TAG|AMP|APOS|QUOT`) make a `</script>` inside any value
  impossible; `testAValueCannotBreakOutOfTheScriptBlock` pins it.

## F4 — head helper (built 2026-09-30)

**Decisions**

- **`$this->Seo->head()` is one helper call in the layout** and prints the title,
  description, canonical, robots, Open Graph and Twitter tags, one per line. The
  page row is found by the **request path** (a trailing slash is ignored), or by an
  explicit `$this->set('seoPage', [$subject, $id])` (A17).
- **Resolution order, first wins:** the `seoOverride` view variable → the page
  row → `metaTitle` / `metaDescription` view variables (pages with no row) → the
  `Seo.site` defaults. `seoOverride` may carry `title`, `description`, `canonical`
  (a URL or a path), `robots`, `ogType`, `ogImage`; it replaces the two loose
  `canonicalUrl` / `robots` variables the layout had as a stopgap (design S12).
- **The title gets the site name appended** (`About — Name`) unless the page is
  titled exactly the site name (the home page) or there is no site name. Format
  and name are `Seo.site.titleSeparator` / `Seo.site.name`.
- **`og_type` and `og_image` are columns on `seo_pages`**, set per page by the
  subject through `PageData::$ogType` / `$ogImage` (job postings are `article`).
  The default image, its width, height and type live in `Seo.site`; the dimensions
  are emitted only for the default image, not for a page's own.
- **Image URLs are made absolute at render time** from the app's base URL
  (`Cake\Routing\Asset::url(..., fullBase)`), so a staging site points at staging.
  (They were hardcoded to `https://themusicdev.llc/…`; identical in production.)
- **The controllers no longer set titles or descriptions for pages that are rows**
  — the subject is the one source (home, about, contact, careers, jobs). Only
  pages that are not rows say anything: apply pages (`metaTitle` / `metaDescription`
  + `seoOverride['robots']`), later list pages and filtered lists (`seoOverride`).
- **Verified with a before/after diff of every page's `<head>`:** only the intended
  differences remain (below).

**Two real bugs this fixed**

- **The canonical was a `<meta>`, not a `<link>`.** `Html->meta(['rel' =>
  'canonical', …])` printed `<meta rel="canonical" href="…">`, which search engines
  ignore; the tag must be `<link rel="canonical" href="…">`. The same mistake made
  the web manifest `<meta rel="manifest">`. Both are `<link>` now.
- **The canonical used to echo the requested URL**, so `/about` and `/about/` each
  named themselves. It is now the stored (slash-free) path; with the TrailingSlash
  redirect only that form is ever served.

**Gotchas**

- **Titles and descriptions come from the page index, so run `bin/cake seo rebuild`
  on deploy, before traffic.** Before the first rebuild a page renders the bare site
  name and an empty description (`HeadTagsTest::testBeforeAnyRebuild…`). Pages
  whose subject changed (new copy) update on the next rebuild.
- **`Html->tag('title', …)` does not escape its content**; the helper escapes the
  title itself (`testValuesAreEscaped`). Attributes are escaped by `Html->meta`.
- **In a `??` chain, `$page->x` is safe in the middle but the last operand needs
  `$page?->x`** — `??` shields a null left side, not the right-hand fallback.
- **`View::addHelper()` is protected**; tests load the helper with
  `$view->loadHelper('Seo', ['className' => SeoHelper::class])`.
- **Stored paths exclude the app's base directory** (`$request->getPath()`), while
  `Router::url()` includes it; a subdirectory install would need the two reconciled.

## F3 — static pages (built 2026-09-30)

**Decisions**

- **`StaticPageSubject` is an abstract base in the plugin; the host extends it**
  (`App\Seo\StaticPagesSubject`) and lists every page in one `pages()` array,
  `id => PageData`. It is registered in `Seo.subjects` under the free key
  `static` (so its sitemap is `/sitemap-static.xml`). Same table, same rebuild,
  same sitemap as any subject — nothing special-cased (A11).
- **A row is just the page's id.** `SubjectInterface` treats rows as opaque
  (`mixed`): the indexer never looks inside one, it only hands it back to
  `idOf()` / `toPage()`. So the static subject yields its ids and looks the page
  up (`toPage('nope')` throws), while `TableSubject` still insists on an entity.
  An earlier cut wrapped each page in a `StaticPageRow` value object; it carried
  nothing the id does not, so it was removed.
- **No constructor.** The indexer builds subjects with `new $class($key)`; PHP
  ignores the extra argument for a class that has no constructor, and PHPStan
  rejected an unused `$key` parameter.
- **No `lastmod`** for static pages (they have no modified date); the sitemap
  omits it rather than inventing one.
- **Apply pages are not listed:** they are per job, `noindex`, and not
  worth a sitemap entry. The careers *index* (`/careers/`) is.
- **Drift guard:** titles and descriptions currently exist in both the
  controllers and `StaticPagesSubject`. `StaticPagesSubjectTest` renders each
  page and fails if the `<title>` or meta description differ from the subject's.
  F4 removes the duplication by making the subject the source.

**Found while building (pre-existing, not fixed here):** the layout's canonical
tag echoes the requested URL. `/about` and `/about/` both return 200 and each
names itself canonical, so every page has two indexable URLs, while the
sitemap and stored paths used the trailing-slash form (Astro parity). Resolved
the same day (design Q9): the host's canonical form is slash-free and its
`TheMusicDev/TrailingSlash` plugin 301s the other; the subjects now build paths
from named routes instead of typing them.

## Scale pass — chunked sitemaps, streaming rebuild (built 2026-09-30)

Trigger: "what happens with 500 / 10k jobs?" Measured before changing
anything (throwaway probe, MariaDB test DB): at 50,000 pages an unchanged
rebuild used **191 MB** and one sitemap file **155 MB**, so PHP's default
128 MB would fail around 30,000 pages, well before the sitemap protocol's
50,000-URL limit.

**Decisions**

- **Sitemaps are split into numbered files past `Seo.sitemap.pageSize`**
  (default 10,000; config in the host's `Seo` block). The first chunk keeps the
  plain name (`/sitemap-job-postings.xml`) so a site that never outgrows one
  file sees no change; later chunks are `-2`, `-3`… . Chunks are ordered by
  path; every chunk is listed in the index with its own newest `lastmod`.
  Chunk 1 is never `-1`, chunk 0 and chunks past the end are 404. The route
  pattern already allowed the suffix. Known limit: a subject whose slug ends
  in `-2` could collide with another subject's second chunk (exact match wins).
- **Sitemap queries are lean:** plain rows with only `path`/`lastmod`
  (`disableHydration`), the index from one `COUNT`/`MAX` `GROUP BY`. Memory is
  flat: ~9–10 MB at 10k and at 50k pages (was 31 / 155 MB).
- **`TableSubject::rows()` streams** in primary-key order, `chunkSize` (500) rows
  per query, keyset paging (`pk > last`) — no `OFFSET` scan, nothing skipped or
  repeated. It overrides the subject's own ordering (e.g. a finder's
  `ORDER BY published_at`) so the order is deterministic; that also makes "first
  claim wins" on a path conflict deterministic.
- **The rebuild compares checksums, not columns.** New `seo_pages.checksum`
  (sha1 of path, title, description, robots, whole-second lastmod, JSON-LD). The
  run starts from a small index of what is stored (id, subject, path, status,
  checksum) instead of every row, and each subject row is written or skipped
  before the next is read. Rows stored before the column existed have no
  checksum, so the first rebuild after the migration rewrites each once.
- **Path uniqueness is tracked in memory during the rebuild** (path → owner),
  so saves use `checkRules => false` (one query fewer per row); the unique
  index remains the backstop. A page that wants a path still held by a stored
  page that has not been processed yet is **deferred** to the end of the run,
  when that page has moved, left or stayed. So a page may now move onto a path
  another page vacates in the same run, whatever order they are read in. A
  true swap (a→b's path while b→a's) is still reported as two conflicts.

**Measured after** (same probe): unchanged rebuild at 50k pages 54 MB / 0.2 s
(was 191 MB / 0.9 s); at 10k 12 MB (was 40). First fill is unchanged at about
0.7 ms per page (37 s for 50k) — a one-off.

**Honest limit:** the rebuild is not flat. It still keeps the small index
above (about 1 KB per page), so memory grows linearly: 12 MB at 10k, 54 MB at
50k, roughly 110 MB near 100k — that is where PHP's default 128 MB would bite
again. Fixing that needs the comparison done in SQL, not worth it before a
site has that many pages.

**Gotchas**

- **Cake caches table schemas, and silently drops columns it does not know
  when saving.** After the `checksum` migration the test/dev schema cache still
  described the old table, so `checksum` was never written and every rebuild
  reported "updated". `bin/cake schema_cache clear` (or `bin/cake cache
  clear_all`) after running a migration that adds columns; a fresh CI database
  is unaffected. Deploys: run it after `migrations migrate`.

## F2 — sitemap (built 2026-09-30)

**Decisions**

- **URLs:** `/sitemap-index.xml` (what `robots.txt` advertises, and what Astro
  published) serves the index. **The plugin has no `/sitemap.xml` alias** (removed 2026-10-01: it
  was a speculative second URL for the same content; the protocol mandates no
  name, and crawlers learn the sitemap from robots.txt / Search Console). A host that
  wants the guessable names adds `$routes->redirect('/sitemap.xml', ['_name' =>
  'seo.sitemap'], ['status' => 301])` itself — ours does, for `/sitemap.xml` and
  `/sitemap_index.xml`. Each subject with live pages gets
  `/sitemap-{slug}.xml`. Routes ship with the plugin (`config/routes.php` +
  `$routesEnabled`, same as Files), so a host gets them by loading the plugin.
- **Child file names come from the subject key** — the last segment, dashed:
  `TheMusicDev/Recruiting.JobPostings` → `job-postings` (maintainer's
  choice over Astro's numbered `sitemap-0.xml`, which shifts when subjects are
  reordered). Two subjects with the same short name, or one called `index`
  (which would shadow `/sitemap-index.xml`), fall back to the **whole key**
  dashed (`a-posts`, `blog-index`). The index route is connected first.
- **Subjects come from the database, not config:** the index lists distinct
  subjects that have at least one `live` row, so a subject with only `gone`
  rows has no file (its URL is a 404) and a removed subject disappears after a
  rebuild.
- **Absolute URLs** are built with `Router::url($path, true)` from the app's
  base URL — paths are stored relative (A7), so a domain change needs no
  rebuild. `lastmod` is W3C/ATOM and omitted when unknown (the index uses each
  subject's newest page).
- **XML is written with `XMLWriter`** (values are escaped for free; the
  plugin's `composer.json` requires `ext-xmlwriter`), not a Cake XML view —
  those returned the wrong content type on Cake 5. Response type is
  `application/xml`.
- **Deliberately not built:** HTTP caching headers. (Splitting large subjects
  into numbered files was added in the scale pass above.)

**Gotchas**

- **Slugs are `Text::slug(Inflector::dasherize($key))`.** `Text::slug()` alone
  neither splits camel case nor lowercases (`JobPostings` stays
  `JobPostings`); `dasherize()` alone leaves `/` and `.` in place
  (`the-music-dev/recruiting.job-postings`). Together they give
  `job-postings` and `the-music-dev-recruiting-job-postings`. An earlier
  hand-written helper is gone.
- **`MAX(lastmod)` is a plain string to the ORM unless typed:** the index query
  adds `latest => datetime` to the select type map. Verified on MariaDB.
- **`robots.txt` still advertises `/sitemap-index.xml`** (F7 will generate that
  file). The host test `tests/TestCase/Seo/RobotsSitemapLinkTest.php` reads the real
  `webroot/robots.txt` and requests the URL it names, so the original 404 (G1)
  cannot come back unnoticed.

## F1 — page index (built 2026-09-30)

**Decisions**

- **Subjects are built with `new $class($key)`.** The `Seo.subjects` key is
  handed to the constructor: a table alias for `TableSubject` (it loads the
  table), a free string for others. The interface cannot enforce a
  constructor, so it is documented on `SubjectInterface` and the indexer
  rejects classes that do not implement it.
- **`TableSubject` has two hooks: `query(Table)` and `pageFor(EntityInterface)`.**
  Host classes implement those (typed entities) instead of the interface's
  `toPage(object)` — `TableSubject::toPage()` checks the row is an entity and
  delegates. Keeps host code free of casts. (The design doc's sketch showed
  `toPage(EntityInterface)`; `pageFor` is the built name.)
- **`path` is unique** (DB unique index + an `isUnique` rule). Two pages cannot
  share a URL, so on a clash **the first claim wins** and the other is reported
  as a conflict — nothing is saved for it and `bin/cake seo rebuild` exits
  non-zero, so a deploy notices. Order = order of `Seo.subjects`, then the
  subject's own row order.
- **A `gone` row whose path a live page now wants is deleted** (the path is
  being reused, e.g. a trashed posting's slug taken by a new one). Anything
  that points at the deleted row must cope — the redirects feature (F6) has
  to account for this.
- **Rows of a subject that is no longer configured are marked `gone`** on the
  next rebuild, so removing a subject from config cleans the sitemap.
- **`seo_pages.schema` exists but is not populated** (F5). It is a JSON column
  created now so F5 needs no migration; the ORM type mapping (Cake sees it as
  text/JSON depending on the driver) is set when F5 starts using it.
- **The command is named by overriding `defaultName()`** to `'seo rebuild'`.
  Without it Cake would derive `seo_rebuild` from the class name.

- **Plugin behavior is tested inside the plugin**, host classes in the host.
  `plugins/TheMusicDev/Seo/tests/` (namespace `TheMusicDev\Seo\Test\`) has
  the indexer, command and `TableSubject` tests, driven by two test subjects
  (`ArraySubject`; `PagesTableSubject` over the plugin's own `seo_pages`) so
  nothing depends on Recruiting or the host. The host's
  `tests/TestCase/Seo/JobPostingSubjectTest.php` covers what is genuinely
  host-side: which postings are public, how one becomes a page, and that the
  host `Seo.subjects` config wires into the index. Wiring: root
  `composer.json` `autoload-dev`, a `seo` testsuite in `phpunit.xml.dist`, and
  the plugin's `composer.json` `autoload-dev` so it works once extracted.
  (An earlier cut put these in the host's `tests/` by copying Recruiting; that
  was wrong for a plugin meant to be extracted.)

**Gotchas**

- **phpcs and phpstan only lint the paths listed in `phpcs.xml` /
  `phpstan.neon`.** Seo was not covered until its `src/` and `tests/` were
  added (Files' `src/` is; Recruiting and Contact are **not**). Adding it
  surfaced missing docblocks and an entity type that a "green" run had never
  checked. A new plugin must be added to both files.
- **Site-wide extra meta tags: `Seo.site.meta`** (2026-10-05, maintainer's decision: the head belongs to Seo, so this
  is not a separate plugin and not the Analytics plugin). A list of attribute entries (`name` | `property` | `http-equiv`
  | `itemprop`, plus `content`) rather than `name => value`, because Open Graph and `http-equiv` tags do not use `name`.
  Printed on every host (unlike the Analytics tags): a verification tag does no harm on staging. Blank content skips the
  tag (unset env var); anything malformed throws, like the Analytics config. Not done on purpose: refusing names the
  helper prints itself (`description`, `robots`, `og:*`, `twitter:*`); the README says not to repeat them.
- **`DATETIME` keeps whole seconds; PHP dates do not.** A subject's `lastmod`
  of `now()` (e.g. an entity's `modified` with microseconds) never equals the
  stored value, so every rebuild reported an "update". `PageIndexer` truncates
  to whole seconds; `testSecondRunChangesNothing` fails without it (checked by
  breaking the truncation on purpose).
- **Plugin tables: dev database by hand, test database automatic.** Migrate the
  dev database with `bin/cake migrations migrate -p TheMusicDev/Seo`. The test
  database (local and CI MariaDB) is migrated by `tests/bootstrap.php`,
  which lists every plugin that owns tables in a `Migrator::runMany()` call —
  a new plugin with migrations must be added there. (Until 2026-09-30 the
  bootstrap ran `Migrator::run()`, which migrates only the app: a fresh
  database had no plugin tables and CI failed with "Cannot describe inquiries.
  It has 0 columns".)
- **A path swap between two pages in one rebuild is reported as a conflict**
  and re-running does not resolve it (A moves onto B's old path while B moves
  onto A's). Rare; fix by hand.
- **Composite primary keys** throw a clear `LogicException` in
  `TableSubject::idOf()` (design A5).
