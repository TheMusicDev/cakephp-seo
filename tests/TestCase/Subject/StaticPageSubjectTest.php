<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Subject;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use TheMusicDev\Seo\Lib\PageIndexer;
use TheMusicDev\Seo\Lib\Sitemap;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Subject\PageData;
use TheMusicDev\Seo\Test\Subject\FixedPagesSubject;

/**
 * StaticPageSubject: a host-written list of non-database pages becomes rows in
 * the page index and entries in the sitemap like any other subject.
 */
final class StaticPageSubjectTest extends TestCase
{
    private SeoPagesTable $pages;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        $this->pages->deleteAll([]);
        FixedPagesSubject::$list = [
            'home' => new PageData(path: '/', title: 'Home', description: 'The home page.'),
            'about' => new PageData(path: '/about/', title: 'About'),
        ];
    }

    protected function tearDown(): void
    {
        $this->pages->deleteAll([]);
        FixedPagesSubject::$list = [];
        parent::tearDown();
    }

    /**
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>}
     */
    private function rebuild(): array
    {
        return (new PageIndexer())->rebuild(['static' => FixedPagesSubject::class]);
    }

    public function testRowsAreThePagesKeyedByTheirStringIds(): void
    {
        $subject = new FixedPagesSubject('static');

        $ids = [];
        foreach ($subject->rows() as $row) {
            $ids[] = $subject->idOf($row);
            $this->assertSame(FixedPagesSubject::$list[$subject->idOf($row)], $subject->toPage($row));
        }

        $this->assertSame(['home', 'about'], $ids);
    }

    public function testARowThatIsNotAStaticPageRowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FixedPagesSubject('static'))->idOf((object)['id' => 'x']);
    }

    public function testThePagesBecomeLiveRowsUnderTheSubjectKey(): void
    {
        $report = $this->rebuild();

        $this->assertSame(2, $report['subjects']['static']['created']);
        $home = $this->pages->find()->where(['subject' => 'static', 'subject_id' => 'home'])->firstOrFail();
        $this->assertSame('/', $home->path);
        $this->assertSame('The home page.', $home->description);
        $this->assertNull($home->lastmod);
        $this->assertSame(SeoPagesTable::STATUS_LIVE, $home->status);
        $this->assertSame(
            ['created' => 0, 'updated' => 0, 'unchanged' => 2, 'gone' => 0],
            $this->rebuild()['subjects']['static'],
            'a second run changes nothing',
        );
    }

    public function testRemovingAnEntryMarksThatPageGone(): void
    {
        $this->rebuild();

        unset(FixedPagesSubject::$list['about']);
        $report = $this->rebuild();

        $this->assertSame(1, $report['subjects']['static']['gone']);
        $this->assertSame(
            SeoPagesTable::STATUS_GONE,
            $this->pages->find()->where(['subject_id' => 'about'])->firstOrFail()->status,
        );
    }

    public function testEditingAnEntryUpdatesItsRow(): void
    {
        $this->rebuild();

        FixedPagesSubject::$list['about'] = new PageData(path: '/about/', title: 'About us');
        $report = $this->rebuild();

        $this->assertSame(1, $report['subjects']['static']['updated']);
        $this->assertSame('About us', $this->pages->find()->where(['subject_id' => 'about'])->firstOrFail()->title);
    }

    public function testStaticPagesAppearInTheSitemapWithoutALastmod(): void
    {
        $this->rebuild();

        $body = (new Sitemap())->urlset('static');

        $this->assertNotNull($body);
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        $this->assertCount(2, $xml->url);
        $this->assertCount(0, $xml->url[0]->lastmod);
        $this->assertCount(0, $xml->url[1]->lastmod);
    }
}
