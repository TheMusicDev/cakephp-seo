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

## Use

`bin/cake seo rebuild` rebuilds the `seo_pages` index from the subjects: creates
missing pages, updates changed ones, marks pages that are no longer public
`gone`. Run it on deploy and nightly. Running it twice changes nothing. It exits
non-zero when two pages claim the same path (the first one wins).

## Tests

`vendor/bin/phpunit --testsuite seo` (plugin tests live in `tests/`; the host's
test bootstrap migrates the plugin's tables, so add the plugin to its
`Migrator::runMany()` list).

## Gotchas

Build-time gotchas are in [`docs/decisions.md`](docs/decisions.md). The one that
bites first: plugin tables must be migrated on the dev **and** test databases.
