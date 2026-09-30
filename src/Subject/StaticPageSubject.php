<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

use InvalidArgumentException;

/**
 * Base for a site's non-database pages (home, about, contact…), design doc A11.
 * The host extends it and lists the pages in `pages()` — one array, one place —
 * and registers the class in `Seo.subjects` under any free key (e.g. `static`).
 * It has no constructor: the indexer's `new $class($key)` simply ignores the key.
 * The rebuild upserts the list into `seo_pages` like any other subject, so a
 * page removed from the array is marked gone.
 *
 * Static pages have no modified date: leave `lastmod` out and the sitemap omits
 * it (a made-up date is worse than none).
 */
abstract class StaticPageSubject implements SubjectInterface
{
    /**
     * Every static page, keyed by a stable string id (`home`, `about`…). The id
     * identifies the page across rebuilds, so renaming an id is a new page.
     *
     * @return array<string, \TheMusicDev\Seo\Subject\PageData>
     */
    abstract protected function pages(): array;

    /**
     * @return iterable<\TheMusicDev\Seo\Subject\StaticPageRow>
     */
    public function rows(): iterable
    {
        foreach ($this->pages() as $id => $page) {
            yield new StaticPageRow((string)$id, $page);
        }
    }

    /**
     * @inheritDoc
     */
    public function idOf(object $row): string
    {
        return $this->row($row)->id;
    }

    /**
     * @inheritDoc
     */
    public function toPage(object $row): PageData
    {
        return $this->row($row)->page;
    }

    /**
     * Narrow a row to a StaticPageRow or fail loudly.
     */
    private function row(object $row): StaticPageRow
    {
        if (!$row instanceof StaticPageRow) {
            throw new InvalidArgumentException('StaticPageSubject rows must be StaticPageRow objects.');
        }

        return $row;
    }
}
