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

## Near-live updates: `seo sync`

`bin/cake seo sync <subject> <id>` re-indexes **one row** with the same rules as a
rebuild — a changed path records a redirect, a row that stopped being public goes
`gone` (410), a republished one revives — and touches nothing else. The subject is the
`Seo.subjects` key (`TheMusicDev/Recruiting.JobPostings`, `static`), the id its primary
key. It exits non-zero on an unknown subject or when the row's new path is held by a
*live* page of another row (a swap or a clash: only `seo rebuild` can settle that; a path
held by a `gone` page is reused). The nightly rebuild stays the source of truth.

Nothing triggers it for you. To make a publish or edit show up in the index at once,
queue it from wherever the host saves (an admin action, a model event):

```php
$this->fetchTable('Queue.QueuedJobs')->createJob('Queue.Execute', [
    'command' => 'bin/cake',
    'params' => ['seo', 'sync', 'TheMusicDev/Recruiting.JobPostings', (string)$job->id],
]);
```

(or `bin/cake queue add Queue.Execute "bin/cake seo sync <subject> <id>"` by hand), with a
worker running (`bin/cake queue run`). **In production** (debug off) `Queue.Execute`
refuses any command that is not in the allow-list, and the job fails with "is not in
Queue.executeAllowedCommands": add the command verbatim to the host's queue config —
`'Queue' => ['executeAllowedCommands' => ['bin/cake']]`. That entry allows every
`bin/cake` command from a queued job, so only code you wrote should be creating
`Queue.Execute` jobs. `SubjectInterface::row($id)` is what a subject supplies for this
(`TableSubject` and `StaticPageSubject` already do).

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

## robots.txt

`/robots.txt` is generated from config; **delete any static `webroot/robots.txt`** — the web
server would serve it first and shadow the route.

```php
'Seo' => ['robots' => [
    'allowHosts' => ['example.com'],   // ONLY these hosts may be crawled; any other host gets Disallow: /
    'rules' => [
        ['userAgent' => '*', 'allow' => ['/'], 'disallow' => ['/admin', '/files']],
    ],
]]
```

**Set `allowHosts` on every real deployment.** A request for any other host (staging, a preview,
`localhost`) is told `Disallow: /` and gets no sitemap line; an empty list means no restriction.
A crawler obeys only its single most specific group, so list a blocked path in every group it
could match. The `Sitemap:` line is built from the sitemap route and the app's base URL
(`APP_FULL_BASE_URL`). `Disallow` stops crawling but does not remove a page from search results
(that takes `noindex`).

## Redirects and 410

When a page's path changes (a slug renamed, a route moved) the rebuild records the old path
as a **301** to the page; a subject can also declare old paths (`PageData::$redirectsFrom`).
A page that stops being public (unpublished, trashed) answers **410 Gone**, and so does every
old URL that pointed at it. A redirect points at the page itself, so nothing ever chains, and
an old path that becomes a live page again simply stops redirecting.

Install the middleware in the host, **after the trailing-slash redirect and before routing**:

```php
->add(SeoRedirectsMiddleware::fromConfig())
```

```php
'Seo' => ['redirects' => ['skip' => ['/admin', '/files', '/health']]],   // never looked up
```

It queries the database for every other path, so list what is never a page — especially a
health probe. If the lookup fails (database down) the request goes through unchanged and the
failure is logged. Only GET and HEAD are handled. It needs `bin/cake seo rebuild` to have run:
redirects and 410s exist only for what the index knows.

## Structured data (JSON-LD)

`$this->Seo->head()` also prints one `<script type="application/ld+json">` with a single
`@graph`: the **site-wide nodes** from `Seo.site.schema` (Organization, WebSite…), then the
page row's own nodes. A subject puts them in `PageData::$schema`; the rebuild stores them.

```php
// host config/app.php — on every page
'Seo' => ['site' => ['schema' => [
    Schema::organization(name: 'Acme', url: 'https://acme.test', id: 'https://acme.test/#organization'),
    Schema::website(name: 'Acme', url: 'https://acme.test', id: 'https://acme.test/#website',
        publisherId: 'https://acme.test/#organization'),
]]]

// a subject's pageFor() — this page only
return new PageData(path: $path, title: $title, schema: [
    Schema::jobPosting(title: $t, description: $html, datePosted: $date, url: $path,
        employmentType: 'FULL_TIME', salaryMin: 45, salaryUnit: 'HOUR', remote: true, applicantCountry: 'US'),
    Schema::breadcrumbs([['name' => 'Home', 'path' => '/'], ['name' => $t]]),
]);
```

`TheMusicDev\Seo\Schema\Schema` builds `organization`, `website`, `breadcrumbs` and
`jobPosting`; any other schema.org type is just a plain array. Builders leave out every empty
part, never guess (pay needs a valid unit and a positive amount; an unknown `employmentType` is
dropped). `url` / `item` values given as paths are made absolute at render. Output is encoded
with the `JSON_HEX_*` flags so no value can close the script block.

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
run: `/sitemap-index.xml` lists one file per subject with
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
