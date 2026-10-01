<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use TheMusicDev\Seo\Lib\PageIndexer;
use TheMusicDev\Seo\Test\Subject\ArraySubject;

/**
 * The sitemap routes end to end: what is served, its type, and that a page
 * that stops being public leaves the sitemap after a rebuild.
 */
final class SitemapControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages')->deleteAll([]);
        ArraySubject::reset();
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages')->deleteAll([]);
        ArraySubject::reset();
        parent::tearDown();
    }

    private function rebuild(): void
    {
        (new PageIndexer())->rebuild(['s' => ArraySubject::class]);
    }

    public function testIndexIsServedAsXml(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->rebuild();

        $this->get('/sitemap-index.xml');
        $this->assertResponseOk();
        $this->assertContentType('application/xml');
        $this->assertResponseContains('/sitemap-s.xml');
    }

    public function testASubjectFileListsItsPages(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];
        $this->rebuild();

        $this->get('/sitemap-s.xml');

        $this->assertResponseOk();
        $this->assertContentType('application/xml');
        $this->assertResponseContains('/a/</loc>');
        $this->assertResponseContains('/b/</loc>');
    }

    public function testNumberedChunkFilesAreServedWhenASubjectOutgrowsThePageSize(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/', 'c' => '/c/']];
        $this->rebuild();
        $original = Configure::read('Seo.sitemap.pageSize');
        Configure::write('Seo.sitemap.pageSize', 2);
        try {
            $this->get('/sitemap-index.xml');
            $this->assertResponseContains('/sitemap-s.xml</loc>');
            $this->assertResponseContains('/sitemap-s-2.xml</loc>');

            $this->get('/sitemap-s-2.xml');
            $this->assertResponseOk();
            $this->assertResponseContains('/c/</loc>');
            $this->assertResponseNotContains('/a/</loc>');

            $this->get('/sitemap-s-3.xml');
            $this->assertResponseCode(404);
        } finally {
            Configure::write('Seo.sitemap.pageSize', $original);
        }
    }

    public function testUnknownSubjectFileIs404(): void
    {
        $this->get('/sitemap-nope.xml');

        $this->assertResponseCode(404);
    }

    public function testAPageThatStopsBeingPublicLeavesTheSitemapAfterARebuild(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];
        $this->rebuild();

        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->rebuild();
        $this->get('/sitemap-s.xml');
        $this->assertResponseNotContains('/b/</loc>');
        $this->assertResponseContains('/a/</loc>');

        ArraySubject::$pages = [];
        $this->rebuild();
        $this->get('/sitemap-s.xml');
        $this->assertResponseCode(404); // no live pages: the subject has no file
        $this->get('/sitemap-index.xml');
        $this->assertResponseNotContains('sitemap-s.xml');
    }
}
