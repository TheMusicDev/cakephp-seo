<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

use DateTimeInterface;

/**
 * What a subject says about one public page. `path` is the canonical path
 * (no scheme or host, trailing slash exactly as the canonical tag emits it).
 * Later features add fields (og type/image, redirectsFrom) with defaults, so
 * existing subjects keep working.
 */
final readonly class PageData
{
    /**
     * @param string $path Canonical path, e.g. `/careers/some-role/`.
     * @param string $title Page title.
     * @param string|null $description Meta description.
     * @param \DateTimeInterface|null $lastmod Last change, for the sitemap; null when unknown.
     * @param string|null $robots Robots meta value, e.g. `noindex`.
     * @param list<array<string, mixed>>|null $schema JSON-LD nodes (used by the structured-data feature).
     * @param string|null $ogType Open Graph type (`article`…); the head helper defaults to `website`.
     * @param string|null $ogImage Open Graph image, a path or URL; the site default image when null.
     * @param list<string> $redirectsFrom Old paths of this page that should 301 to it (a legacy
     *   column, a slug history…). The class can only list URLs it can know; it cannot invent them.
     */
    public function __construct(
        public string $path,
        public string $title,
        public ?string $description = null,
        public ?DateTimeInterface $lastmod = null,
        public ?string $robots = null,
        public ?array $schema = null,
        public ?string $ogType = null,
        public ?string $ogImage = null,
        public array $redirectsFrom = [],
    ) {
    }
}
