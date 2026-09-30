<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Lib;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use SimpleXMLElement;
use TheMusicDev\Seo\Lib\Sitemap;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;

/**
 * Sitemap XML built from live page rows: file-name slugs, the index, one
 * subject's urlset. Rows are seeded straight into seo_pages.
 */
final class SitemapTest extends TestCase
{
    private SeoPagesTable $pages;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        $this->pages->deleteAll([]);
    }

    protected function tearDown(): void
    {
        $this->pages->deleteAll([]);
        parent::tearDown();
    }

    private function seed(string $subject, string $id, string $path, ?DateTime $lastmod = null, string $status = 'live'): void
    {
        $this->pages->saveOrFail($this->pages->newEntity([
            'subject' => $subject,
            'subject_id' => $id,
            'path' => $path,
            'title' => 'T ' . $id,
            'lastmod' => $lastmod,
            'status' => $status,
        ]));
    }

    private function xml(string $body): SimpleXMLElement
    {
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'sitemap must be well-formed XML');

        return $xml;
    }

    public function testSlugIsTheLastSegmentDashed(): void
    {
        $this->assertSame('job-postings', Sitemap::slug('TheMusicDev/Recruiting.JobPostings'));
        $this->assertSame('static', Sitemap::slug('static'));
        $this->assertSame('posts', Sitemap::slug('Blog.Posts'));
        $this->assertSame('the-music-dev-recruiting-job-postings', Sitemap::fullSlug('TheMusicDev/Recruiting.JobPostings'));
    }

    public function testOnlySubjectsWithLivePagesAreListed(): void
    {
        $this->seed('Blog.Posts', '1', '/blog/a/');
        $this->seed('Old.Things', '1', '/old/a/', null, 'gone');

        $this->assertSame(['posts' => 'Blog.Posts'], (new Sitemap())->subjects());
    }

    public function testCollidingOrReservedSlugsFallBackToTheWholeKey(): void
    {
        $this->seed('A.Posts', '1', '/a/1/');
        $this->seed('B.Posts', '1', '/b/1/');
        $this->seed('Blog.Index', '1', '/i/1/'); // would shadow /sitemap-index.xml

        $this->assertSame(
            ['a-posts' => 'A.Posts', 'b-posts' => 'B.Posts', 'blog-index' => 'Blog.Index'],
            (new Sitemap())->subjects(),
        );
    }

    public function testIndexListsEachSubjectFileWithItsNewestLastmod(): void
    {
        $this->seed('Blog.Posts', '1', '/blog/a/', new DateTime('2026-01-01 10:00:00'));
        $this->seed('Blog.Posts', '2', '/blog/b/', new DateTime('2026-03-05 08:30:00'));
        $this->seed('Static.Pages', 'home', '/'); // no lastmod anywhere

        $xml = $this->xml((new Sitemap())->index());

        $this->assertSame('sitemapindex', $xml->getName());
        $this->assertCount(2, $xml->sitemap);
        $entries = [];
        foreach ($xml->sitemap as $entry) {
            $entries[basename((string)$entry->loc)] = $entry;
        }
        $this->assertArrayHasKey('sitemap-posts.xml', $entries);
        $this->assertSame(
            (new DateTime('2026-03-05 08:30:00'))->getTimestamp(),
            (new DateTime((string)$entries['sitemap-posts.xml']->lastmod))->getTimestamp(),
        );
        $this->assertArrayHasKey('sitemap-pages.xml', $entries);
        $this->assertCount(0, $entries['sitemap-pages.xml']->lastmod, 'no lastmod when none is known');
    }

    public function testUrlsetHasLivePagesSortedWithAbsoluteLocsAndOptionalLastmod(): void
    {
        $this->seed('Blog.Posts', '1', '/blog/b/', new DateTime('2026-02-02 12:00:00'));
        $this->seed('Blog.Posts', '2', '/blog/a/');
        $this->seed('Blog.Posts', '3', '/blog/gone/', null, 'gone');

        $body = (new Sitemap())->urlset('posts');
        $this->assertNotNull($body);
        $xml = $this->xml($body);

        $this->assertSame('urlset', $xml->getName());
        $this->assertCount(2, $xml->url, 'gone pages are not listed');
        $this->assertMatchesRegularExpression('#^https?://[^/]+/blog/a/$#', (string)$xml->url[0]->loc);
        $this->assertCount(0, $xml->url[0]->lastmod);
        $this->assertMatchesRegularExpression('#/blog/b/$#', (string)$xml->url[1]->loc);
        $this->assertNotEmpty((string)$xml->url[1]->lastmod);
    }

    public function testValuesAreXmlEscaped(): void
    {
        $this->seed('Blog.Posts', '1', '/search/?a=1&b=2');

        $body = (new Sitemap())->urlset('posts');

        $this->assertNotNull($body);
        $this->assertStringContainsString('/search/?a=1&amp;b=2', $body);
        $this->assertStringEndsWith('/search/?a=1&b=2', (string)$this->xml($body)->url[0]->loc);
    }

    public function testUrlsetOfAnUnknownSlugIsNull(): void
    {
        $this->seed('Blog.Posts', '1', '/blog/a/');

        $this->assertNull((new Sitemap())->urlset('nope'));
    }

    public function testAnEmptyIndexIsStillWellFormed(): void
    {
        $xml = $this->xml((new Sitemap())->index());

        $this->assertSame('sitemapindex', $xml->getName());
        $this->assertCount(0, $xml->sitemap);
    }
}
