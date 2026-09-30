# SEO plugin — build decisions & record

Why + what bit us while building. The **design of record** is
[seo-plugin-design.md](seo-plugin-design.md) (what we decided and why);
[delivery-plan.md](delivery-plan.md) is the build order. This file records
decisions made *during* the build and the gotchas the code hit.

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

- **URLs:** `/sitemap-index.xml` (what `robots.txt` advertises) and
  `/sitemap.xml` both serve the index; each subject with live pages gets
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
  adds `latest => datetime` to the select type map. Verified on both MariaDB
  and CI's sqlite.
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
- **`DATETIME` keeps whole seconds; PHP dates do not.** A subject's `lastmod`
  of `now()` (e.g. an entity's `modified` with microseconds) never equals the
  stored value, so every rebuild reported an "update". `PageIndexer` truncates
  to whole seconds; `testSecondRunChangesNothing` fails without it (checked by
  breaking the truncation on purpose).
- **Plugin tables: dev database by hand, test database automatic.** Migrate the
  dev database with `bin/cake migrations migrate -p TheMusicDev/Seo`. The test
  database (local MariaDB and CI sqlite) is migrated by `tests/bootstrap.php`,
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
