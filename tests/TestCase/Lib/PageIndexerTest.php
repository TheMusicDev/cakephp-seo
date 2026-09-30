<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Lib;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use stdClass;
use TheMusicDev\Seo\Lib\PageIndexer;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Test\Subject\ArraySubject;

/**
 * Page index rebuild: rows keyed by (subject, id), gone/revive lifecycle,
 * idempotence, path conflicts. Uses ArraySubject only — nothing here depends
 * on the host or another plugin.
 */
final class PageIndexerTest extends TestCase
{
    private SeoPagesTable $pages;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        $this->pages->deleteAll([]);
        ArraySubject::reset();
    }

    protected function tearDown(): void
    {
        $this->pages->deleteAll([]);
        ArraySubject::reset();
        parent::tearDown();
    }

    /**
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>}
     */
    private function rebuild(): array
    {
        return (new PageIndexer())->rebuild(['s' => ArraySubject::class]);
    }

    public function testCreatesALiveRowPerPage(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];

        $report = $this->rebuild();

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'gone' => 0], $report['subjects']['s']);
        $this->assertSame([], $report['conflicts']);
        $row = $this->pages->find()->where(['subject_id' => 'a'])->firstOrFail();
        $this->assertSame('s', $row->subject);
        $this->assertSame('/a/', $row->path);
        $this->assertSame('Page a', $row->title);
        $this->assertSame(SeoPagesTable::STATUS_LIVE, $row->status);
    }

    public function testEmptyConfigDoesNothing(): void
    {
        $report = (new PageIndexer())->rebuild([]);

        $this->assertSame(['subjects' => [], 'conflicts' => []], $report);
    }

    public function testSecondRunChangesNothingEvenWithAMicrosecondLastmod(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];
        ArraySubject::$lastmod = DateTime::now(); // has microseconds; DATETIME does not
        $this->rebuild();
        $before = $this->pages->find()->orderByAsc('id')->all()->extract('modified')->toList();
        $this->assertNotNull($this->pages->find()->firstOrFail()->lastmod);

        $report = $this->rebuild();

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'gone' => 0], $report['subjects']['s']);
        $after = $this->pages->find()->orderByAsc('id')->all()->extract('modified')->toList();
        $this->assertEquals($before, $after, 'unchanged rows must not be re-saved');
    }

    public function testPageThatDisappearsGoesGoneAndRevivesAsTheSameRow(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->rebuild();
        $id = $this->pages->find()->firstOrFail()->id;

        ArraySubject::$pages = [];
        $this->assertSame(1, $this->rebuild()['subjects']['s']['gone']);
        $this->assertSame(SeoPagesTable::STATUS_GONE, $this->pages->get($id)->status);
        $this->assertSame(0, $this->rebuild()['subjects']['s']['gone'], 'idempotent while gone');

        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->assertSame(1, $this->rebuild()['subjects']['s']['updated']);
        $this->assertSame(SeoPagesTable::STATUS_LIVE, $this->pages->get($id)->status);
        $this->assertSame(1, $this->pages->find()->count(), 'revived, not duplicated');
    }

    public function testPathChangeUpdatesTheRowInPlace(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/old/']];
        $this->rebuild();
        $id = $this->pages->find()->firstOrFail()->id;

        ArraySubject::$pages = ['s' => ['a' => '/new/']];
        $report = $this->rebuild();

        $this->assertSame(1, $report['subjects']['s']['updated']);
        $this->assertSame(1, $this->pages->find()->count());
        $this->assertSame('/new/', $this->pages->get($id)->path);
    }

    public function testTwoSubjectsClaimingOnePathFirstWinsAndTheOtherIsReported(): void
    {
        ArraySubject::$pages = ['one' => ['a' => '/x/'], 'two' => ['b' => '/x/']];

        $report = (new PageIndexer())->rebuild(['one' => ArraySubject::class, 'two' => ArraySubject::class]);

        $this->assertCount(1, $report['conflicts']);
        $this->assertStringContainsString('/x/', $report['conflicts'][0]);
        $this->assertStringContainsString('two#b', $report['conflicts'][0]);
        $this->assertSame(1, $this->pages->find()->count());
        $this->assertSame('one', $this->pages->find()->firstOrFail()->subject);
    }

    public function testRowsOfASubjectThatIsNoLongerConfiguredGoGone(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->rebuild();

        $report = (new PageIndexer())->rebuild([]);

        $this->assertSame(1, $report['subjects']['s']['gone']);
        $this->assertSame(SeoPagesTable::STATUS_GONE, $this->pages->find()->firstOrFail()->status);
    }

    public function testAGonePathReusedByALivePageDeletesTheOldRow(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/x/']];
        $this->rebuild();

        ArraySubject::$pages = ['s' => ['b' => '/x/']]; // a disappears, b takes its path
        $report = $this->rebuild();

        $this->assertSame([], $report['conflicts']);
        $row = $this->pages->find()->firstOrFail();
        $this->assertSame(1, $this->pages->find()->count());
        $this->assertSame('b', $row->subject_id);
        $this->assertSame(SeoPagesTable::STATUS_LIVE, $row->status);
    }

    public function testAConfiguredClassThatIsNotASubjectIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PageIndexer())->rebuild(['x' => stdClass::class]);
    }
}
