<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Lib;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Routing\Router;
use Cake\Utility\Inflector;
use Cake\Utility\Text;
use DateTimeInterface;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use XMLWriter;

/**
 * Builds the XML sitemaps from the live rows of `seo_pages` (design doc S3):
 * an index listing one child file per subject that has live pages, and the
 * child files themselves. Paths are stored relative (A7), so absolute URLs
 * are built from the app's configured base URL at render time.
 *
 * Child file names come from the subject key: the last segment, dashed —
 * `TheMusicDev/Recruiting.JobPostings` → `job-postings`. When two subjects
 * would share a name (or one is called `index`) the colliding ones fall back
 * to the whole key dashed, so a name is always unique and never shadows the
 * index.
 */
final class Sitemap
{
    use LocatorAwareTrait;

    private const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

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
        /** @var list<string> $keys */
        $keys = $this->pages()->find('live')
            ->select(['subject'])
            ->distinct(['subject'])
            ->orderByAsc('subject')
            ->all()
            ->extract('subject')
            ->toList();

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
     * The sitemap index: one entry per subject file, `lastmod` = its newest page.
     */
    public function index(): string
    {
        $latest = $this->latestBySubject();

        $xml = $this->writer('sitemapindex');
        foreach ($this->subjects() as $slug => $key) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', Router::url('/sitemap-' . $slug . '.xml', true));
            if (isset($latest[$key])) {
                $xml->writeElement('lastmod', $this->date($latest[$key]));
            }
            $xml->endElement();
        }

        return $this->finish($xml);
    }

    /**
     * One subject's `<urlset>`, or null when no subject has that slug.
     */
    public function urlset(string $slug): ?string
    {
        $key = $this->subjects()[$slug] ?? null;
        if ($key === null) {
            return null;
        }

        $xml = $this->writer('urlset');
        $rows = $this->pages()->find('live')->where(['subject' => $key])->orderByAsc('path')->all();
        /** @var \TheMusicDev\Seo\Model\Entity\SeoPage $row */
        foreach ($rows as $row) {
            $xml->startElement('url');
            $xml->writeElement('loc', Router::url((string)$row->path, true));
            if ($row->lastmod !== null) {
                $xml->writeElement('lastmod', $this->date($row->lastmod));
            }
            $xml->endElement();
        }

        return $this->finish($xml);
    }

    /**
     * Newest known `lastmod` per subject (missing when a subject has none).
     *
     * @return array<string, \DateTimeInterface>
     */
    private function latestBySubject(): array
    {
        $query = $this->pages()->find('live');
        $query
            ->select(['subject', 'latest' => $query->func()->max('lastmod')])
            ->groupBy('subject');
        $query->getSelectTypeMap()->addDefaults(['latest' => 'datetime']);

        $latest = [];
        foreach ($query->all() as $row) {
            if ($row->get('latest') instanceof DateTimeInterface) {
                $latest[(string)$row->get('subject')] = $row->get('latest');
            }
        }

        return $latest;
    }

    /**
     * The page index table.
     */
    private function pages(): SeoPagesTable
    {
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable */
        return $this->fetchTable('TheMusicDev/Seo.SeoPages');
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
