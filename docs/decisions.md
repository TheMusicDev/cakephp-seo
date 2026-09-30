# SEO plugin — build decisions & record

Why + what bit us while building. The **design of record** is
[seo-plugin-design.md](seo-plugin-design.md) (what we decided and why);
[delivery-plan.md](delivery-plan.md) is the build order. This file records
decisions made *during* the build and the gotchas the code hit.

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
- **Deliberately not built:** splitting a subject over 50,000 URLs, and HTTP
  caching headers. Add either when a site needs it.

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
