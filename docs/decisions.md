# SEO plugin — build decisions & record

Why + what bit us while building. The **design of record** is
[seo-plugin-design.md](seo-plugin-design.md) (what we decided and why);
[delivery-plan.md](delivery-plan.md) is the build order. This file records
decisions made *during* the build and the gotchas the code hit.

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
- **Plugin tables must be migrated on both databases:**
  `bin/cake migrations migrate -p TheMusicDev/Seo` and the same with
  `--connection test`. The test bootstrap's `Migrator` runs only the app's
  migrations, so a fresh test/CI database has no plugin tables (pre-existing
  issue that already breaks CI for Recruiting/Contact/Files; F1 adds one more
  table to it).
- **A path swap between two pages in one rebuild is reported as a conflict**
  and re-running does not resolve it (A moves onto B's old path while B moves
  onto A's). Rare; fix by hand.
- **Composite primary keys** throw a clear `LogicException` in
  `TableSubject::idOf()` (design A5).
