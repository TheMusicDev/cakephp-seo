# TheMusicDev/Seo

> **Status: in build.** F1 (page index) is done; sitemap, head tags, structured
> data and redirects follow. What is agreed, what is open and the build order are
> tracked in
> [`docs/seo-plugin-design.md`](docs/seo-plugin-design.md) — read that first.
> Build order and feature slices: [`docs/delivery-plan.md`](docs/delivery-plan.md).

All-in-one SEO for TheMusicDev CakePHP sites: page meta tags, structured data
(JSON-LD), XML sitemap, robots.txt and redirects. Domain plugins (Recruiting,
a future blog…) get SEO without knowing this plugin exists; the host app wires
them together through config.

## Install

1. composer path repo + `require themusicdev/cakephp-seo ^0.1`
2. `config/plugins.php`: `'TheMusicDev/Seo' => []`
3. `bin/cake migrations migrate -p TheMusicDev/Seo` (the test database is
   migrated by the host's `tests/bootstrap.php`)

## Configure (host `config/app.php`)

```php
'Seo' => ['subjects' => [
    // subject key (a table alias for table-backed subjects) => host subject class
    'TheMusicDev/Recruiting.JobPostings' => \App\Seo\JobPostingSubject::class,
]],
```

Host values win over `config/app_default.php`. A **subject** is a host class
that says which rows are public pages and how one row becomes a page (path,
title, description, lastmod). Table-backed subjects extend `TableSubject` and
implement two methods:

```php
final class JobPostingSubject extends TableSubject
{
    protected function query(Table $table): SelectQuery { return $table->find('published'); }

    protected function pageFor(EntityInterface $row): PageData
    {
        return new PageData(path: '/careers/' . $row->get('slug') . '/', title: $row->get('title'));
    }
}
```

The class lives in the host because URLs are host knowledge (design doc A3).

The site's non-database pages (home, about…) are a subject too: extend
`StaticPageSubject`, list the pages in `pages()` (`id => PageData`, no `lastmod`),
and register it under any free key:

```php
'Seo' => ['subjects' => ['static' => \App\Seo\StaticPagesSubject::class]],
```

## Use

`bin/cake seo rebuild` rebuilds the `seo_pages` index from the subjects: creates
missing pages, updates changed ones, marks pages that are no longer public
`gone`. Run it on deploy and nightly. Running it twice changes nothing. It exits
non-zero when two pages claim the same path (the first one wins). It streams
subject rows and skips unchanged pages by checksum: about 54 MB and 0.2 s for
50,000 unchanged pages. After a migration that adds columns, run
`bin/cake schema_cache clear` (Cake silently ignores columns its cached schema
does not know).

## Tests

`vendor/bin/phpunit --testsuite seo` (plugin tests live in `tests/`; the host's
test bootstrap migrates the plugin's tables, so add the plugin to its
`Migrator::runMany()` list).

## Head tags

One call in the layout prints the title, description, canonical, robots, Open Graph and
Twitter tags for the page:

```php
<?= $this->Seo->head() ?>
```

Load the helper once in `AppView::initialize()`: `$this->addHelper('TheMusicDev/Seo.Seo');`.
The page's row in `seo_pages` is found by the request path. Each value comes from the
first of: the `seoOverride` view variable → the page row → the `metaTitle` /
`metaDescription` view variables (pages that are not rows) → the site defaults.

```php
// A page that is not a row (page 2 of a list, a filtered list) says what differs:
$this->set('seoOverride', [
    'title' => 'Careers — page 2',
    'canonical' => Router::url(['_name' => 'careers', '?' => ['page' => 2]], true),
    'robots' => 'noindex,follow',          // also: description, ogType, ogImage
]);
```

Site-wide defaults (host `config/app.php`):

```php
'Seo' => ['site' => [
    'name' => 'Acme',                      // appended to titles: "About — Acme"
    'image' => '/images/og-default.webp',  // default share image (path or URL)
    'imageWidth' => 1200, 'imageHeight' => 630, 'imageType' => 'image/webp',
]],
```

Subjects set `ogType` / `ogImage` per page in `PageData`. The canonical is a real
`<link rel="canonical">`. **Titles and descriptions come from the page index, so run
`bin/cake seo rebuild` on deploy, before traffic** — before the first rebuild a page
renders the bare site name and an empty description.

## URL form

The plugin stores and lists paths **exactly as your subjects return them** and never
normalizes them. So: pick one URL form (we use no trailing slash), **build every
path with the router** (`Router::url(['_name' => 'careers.view', 'slug' => $slug])`,
never typed by hand), and make the host redirect the other form — see
`TheMusicDev/TrailingSlash`. Redirecting is host policy, not this plugin's job. The one
rule that matters here: a page's stored path, its sitemap URL and its canonical tag
must all be the same string. (The host's `AstroUrlMigrationTest` pins that.)

## Sitemap

Served automatically once the plugin is loaded and `bin/cake seo rebuild` has
run: `/sitemap-index.xml` (also `/sitemap.xml`) lists one file per subject with
live pages, e.g. `/sitemap-job-postings.xml`. A page that stops being public
leaves the sitemap on the next rebuild. Point `robots.txt` at
`/sitemap-index.xml`.

A subject with more than `Seo.sitemap.pageSize` pages (default 10,000; the
protocol allows 50,000) is split: the first file keeps the plain name, the rest
are `/sitemap-job-postings-2.xml`, `-3.xml`…, all listed in the index. Sitemaps
are built from plain rows, so memory stays flat at any size.

```php
'Seo' => ['sitemap' => ['pageSize' => 10000]],   // host config/app.php, optional
```

## Gotchas

Build-time gotchas are in [`docs/decisions.md`](docs/decisions.md). The one that
bites first: plugin tables must be migrated on the dev **and** test databases.
