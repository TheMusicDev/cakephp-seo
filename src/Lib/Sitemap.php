<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Lib;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Cake\Routing\Router;
use Cake\Utility\Inflector;
use Cake\Utility\Text;
use DateTimeInterface;
use XMLWriter;

/**
 * Builds the XML sitemaps from the live rows of `seo_pages` (design doc S3):
 * an index listing the files, and the files themselves. Paths are stored
 * relative (A7), so absolute URLs are built from the app's configured base URL
 * at render time.
 *
 * One subject = one file until it has more than `pageSize` pages (config
 * `Seo.sitemap.pageSize`, default 10,000 — the protocol allows 50,000), then
 * it is split into chunks ordered by path. The first chunk keeps the plain name
 * (`sitemap-job-postings.xml`), later ones are numbered
 * (`sitemap-job-postings-2.xml`). Files are built from plain rows with only
 * the columns needed, so memory does not grow with the number of pages.
 *
 * File names come from the subject key: the last segment, dashed —
 * `TheMusicDev/Recruiting.JobPostings` → `job-postings`. When two subjects
 * would share a name (or one is called `index`) the colliding ones fall back
 * to the whole key dashed, so a name is always unique and never shadows the
 * index. Known limit: a subject whose name ends in `-2` could collide with
 * another subject's second chunk; the exact match wins.
 */
final class Sitemap
{
    use LocatorAwareTrait;

    private const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private int $pageSize;

    /**
     * @param int|null $pageSize URLs per file; defaults to Configure `Seo.sitemap.pageSize`.
     */
    public function __construct(?int $pageSize = null)
    {
        $this->pageSize = max(1, $pageSize ?? (int)Configure::read('Seo.sitemap.pageSize', 10000));
    }

    /**
     * Short file-name slug for a subject key.
     */
    public static function slug(string $subject): string
    {
        $parts = preg_split('/[.\/]/', $subject) ?: [$subject];

        return Text::slug(Inflector::dasherize((string)end($parts)));
    }

    /**
     * Slug from the whole key, used when the short one would collide.
     */
    public static function fullSlug(string $subject): string
    {
        return Text::slug(Inflector::dasherize($subject));
    }

    /**
     * Subjects that have at least one live page, keyed by file-name slug.
     *
     * @return array<string, string> slug => subject key
     */
    public function subjects(): array
    {
        return $this->slugMap(array_keys($this->stats()));
    }

    /**
     * The sitemap index: one entry per file, `lastmod` = the newest page in it.
     */
    public function index(): string
    {
        $stats = $this->stats();

        $xml = $this->writer('sitemapindex');
        foreach ($this->slugMap(array_keys($stats)) as $slug => $key) {
            $chunks = (int)ceil($stats[$key]['pages'] / $this->pageSize);
            for ($chunk = 1; $chunk <= $chunks; $chunk++) {
                $latest = $chunks === 1 ? $stats[$key]['latest'] : $this->chunkLatest($key, $chunk);
                $xml->startElement('sitemap');
                $xml->writeElement('loc', Router::url($this->fileName($slug, $chunk), true));
                if ($latest !== null) {
                    $xml->writeElement('lastmod', $this->date($latest));
                }
                $xml->endElement();
            }
        }

        return $this->finish($xml);
    }

    /**
     * One file's `<urlset>` (`job-postings`, `job-postings-2`, …), or null when
     * there is no such file.
     */
    public function urlset(string $name): ?string
    {
        $stats = $this->stats();
        $map = $this->slugMap(array_keys($stats));

        $chunk = 1;
        $key = $map[$name] ?? null;
        if ($key === null && preg_match('/^(.+)-(\d+)$/', $name, $m) === 1 && isset($map[$m[1]])) {
            $key = $map[$m[1]];
            $chunk = (int)$m[2];
            if ($chunk < 2 || $chunk > (int)ceil($stats[$key]['pages'] / $this->pageSize)) {
                return null;
            }
        }
        if ($key === null) {
            return null;
        }

        $xml = $this->writer('urlset');
        $rows = $this->livePages()
            ->select(['path', 'lastmod'])
            ->where(['subject' => $key])
            ->orderByAsc('path')
            ->limit($this->pageSize)
            ->offset(($chunk - 1) * $this->pageSize)
            ->disableHydration();
        foreach ($rows as $row) {
            $xml->startElement('url');
            $xml->writeElement('loc', Router::url((string)$row['path'], true));
            if ($row['lastmod'] instanceof DateTimeInterface) {
                $xml->writeElement('lastmod', $this->date($row['lastmod']));
            }
            $xml->endElement();
        }

        return $this->finish($xml);
    }

    /**
     * Live page count and newest `lastmod` per subject, in one query.
     *
     * @return array<string, array{pages: int, latest: \DateTimeInterface|null}>
     */
    private function stats(): array
    {
        $query = $this->livePages();
        $query
            ->select([
                'subject',
                'pages' => $query->func()->count('*'),
                'latest' => $query->func()->max('lastmod'),
            ])
            ->groupBy('subject')
            ->orderByAsc('subject')
            ->disableHydration();
        $query->getSelectTypeMap()->addDefaults(['latest' => 'datetime']);

        $stats = [];
        foreach ($query as $row) {
            $stats[(string)$row['subject']] = [
                'pages' => (int)$row['pages'],
                'latest' => $row['latest'] instanceof DateTimeInterface ? $row['latest'] : null,
            ];
        }

        return $stats;
    }

    /**
     * Newest `lastmod` within one chunk of a subject (null when none is known).
     */
    private function chunkLatest(string $key, int $chunk): ?DateTimeInterface
    {
        $latest = null;
        $rows = $this->livePages()
            ->select(['lastmod'])
            ->where(['subject' => $key])
            ->orderByAsc('path')
            ->limit($this->pageSize)
            ->offset(($chunk - 1) * $this->pageSize)
            ->disableHydration();
        foreach ($rows as $row) {
            if ($row['lastmod'] instanceof DateTimeInterface && ($latest === null || $row['lastmod'] > $latest)) {
                $latest = $row['lastmod'];
            }
        }

        return $latest;
    }

    /**
     * Unique file-name slug per subject key.
     *
     * @param list<string> $keys Subject keys with live pages.
     * @return array<string, string> slug => subject key
     */
    private function slugMap(array $keys): array
    {
        $short = [];
        foreach ($keys as $key) {
            $short[$key] = self::slug($key);
        }
        $counts = array_count_values($short);

        $map = [];
        foreach ($short as $key => $slug) {
            $unique = $counts[$slug] === 1 && $slug !== 'index';
            $map[$unique ? $slug : self::fullSlug($key)] = $key;
        }

        return $map;
    }

    /**
     * Path of a subject's file: plain for the first chunk, `-N` after.
     */
    private function fileName(string $slug, int $chunk): string
    {
        return '/sitemap-' . $slug . ($chunk > 1 ? '-' . $chunk : '') . '.xml';
    }

    /**
     * A query over the live pages.
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    private function livePages(): SelectQuery
    {
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = $this->fetchTable('TheMusicDev/Seo.SeoPages');

        return $pages->find('live');
    }

    /**
     * An XML document open at the sitemap namespace root element.
     */
    private function writer(string $root): XMLWriter
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($root);
        $xml->writeAttribute('xmlns', self::NAMESPACE_URI);

        return $xml;
    }

    /**
     * Close the root element and return the XML text.
     */
    private function finish(XMLWriter $xml): string
    {
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * W3C datetime (what the sitemap protocol accepts).
     */
    private function date(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
