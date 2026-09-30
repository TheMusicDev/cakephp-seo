<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

use InvalidArgumentException;

/**
 * Base for a site's non-database pages (home, about, contact…), design doc A11.
 * The host extends it and lists the pages in `pages()` — one array, one place —
 * and registers the class in `Seo.subjects` under any free key (e.g. `static`).
 * The rebuild upserts the list into `seo_pages` like any other subject, so a
 * page removed from the array is marked gone. A row is just the page's id.
 *
 * Static pages have no modified date: leave `lastmod` out and the sitemap omits
 * it (a made-up date is worse than none). It has no constructor: the indexer's
 * `new $class($key)` simply ignores the key.
 */
abstract class StaticPageSubject implements SubjectInterface
{
    /**
     * @var array<string, \TheMusicDev\Seo\Subject\PageData>|null
     */
    private ?array $list = null;

    /**
     * Every static page, keyed by a stable string id (`home`, `about`…). The id
     * identifies the page across rebuilds, so renaming an id is a new page.
     *
     * @return array<string, \TheMusicDev\Seo\Subject\PageData>
     */
    abstract protected function pages(): array;

    /**
     * @return iterable<string>
     */
    public function rows(): iterable
    {
        foreach (array_keys($this->list()) as $id) {
            yield (string)$id;
        }
    }

    /**
     * @inheritDoc
     */
    public function idOf(mixed $row): string
    {
        return (string)$row;
    }

    /**
     * @inheritDoc
     */
    public function toPage(mixed $row): PageData
    {
        return $this->list()[(string)$row]
            ?? throw new InvalidArgumentException('Unknown static page id: ' . (string)$row);
    }

    /**
     * The page list, built once per instance.
     *
     * @return array<string, \TheMusicDev\Seo\Subject\PageData>
     */
    private function list(): array
    {
        return $this->list ??= $this->pages();
    }
}
