<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\Subject;

use DateTimeInterface;
use TheMusicDev\Seo\Subject\PageData;
use TheMusicDev\Seo\Subject\SubjectInterface;

/**
 * Test subject: pages come from a static array keyed by the subject key, so a
 * test can change what is "public" between rebuilds. Needs no table and no
 * other plugin.
 */
final class ArraySubject implements SubjectInterface
{
    /**
     * @var array<string, array<string, string>> key => [id => path]
     */
    public static array $pages = [];

    /**
     * Applied to every page as its lastmod.
     */
    public static ?DateTimeInterface $lastmod = null;

    /**
     * Applied to every page as its JSON-LD nodes.
     *
     * @var list<array<string, mixed>>|null
     */
    public static ?array $schema = null;

    /**
     * Old paths a page declares: key => [id => list of paths].
     *
     * @var array<string, array<string, list<string>>>
     */
    public static array $redirectsFrom = [];

    public function __construct(private string $key)
    {
    }

    public static function reset(): void
    {
        self::$pages = [];
        self::$lastmod = null;
        self::$schema = null;
        self::$redirectsFrom = [];
    }

    /**
     * @return iterable<object>
     */
    public function rows(): iterable
    {
        foreach (self::$pages[$this->key] ?? [] as $id => $path) {
            yield (object)['id' => (string)$id, 'path' => $path];
        }
    }

    public function row(string $id): mixed
    {
        $path = self::$pages[$this->key][$id] ?? null;

        return $path === null ? null : (object)['id' => $id, 'path' => $path];
    }

    public function idOf(mixed $row): string
    {
        return $row->id;
    }

    public function toPage(mixed $row): PageData
    {
        return new PageData(
            path: $row->path,
            title: 'Page ' . $row->id,
            lastmod: self::$lastmod,
            schema: self::$schema,
            redirectsFrom: self::$redirectsFrom[$this->key][$row->id] ?? [],
        );
    }
}
