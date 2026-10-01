<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Lib;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use TheMusicDev\Seo\Lib\PageIndexer;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Model\Table\SeoRedirectsTable;
use TheMusicDev\Seo\Test\Subject\ArraySubject;

/**
 * Re-indexing one row (F8): same rules as a rebuild, but only that row is touched.
 */
final class PageIndexerSyncTest extends TestCase
{
    private SeoPagesTable $pages;

    private SeoRedirectsTable $redirects;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        /** @var \TheMusicDev\Seo\Model\Table\SeoRedirectsTable $redirects */
        $redirects = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects');
        $this->redirects = $redirects;
        $this->redirects->deleteAll([]);
        $this->pages->deleteAll([]);
        ArraySubject::reset();
    }

    protected function tearDown(): void
    {
        $this->redirects->deleteAll([]);
        $this->pages->deleteAll([]);
        ArraySubject::reset();
        parent::tearDown();
    }

    /**
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>, redirects: int}
     */
    private function sync(string $id): array
    {
        return (new PageIndexer())->sync('s', $id, ['s' => ArraySubject::class]);
    }

    private function rebuild(): void
    {
        (new PageIndexer())->rebuild(['s' => ArraySubject::class]);
    }

    private function path(string $id): string
    {
        return (string)$this->pages->find()->where(['subject_id' => $id])->firstOrFail()->get('path');
    }

    private function statusOf(string $id): string
    {
        return (string)$this->pages->find()->where(['subject_id' => $id])->firstOrFail()->get('status');
    }

    public function testASyncedNewRowIsCreatedAndNothingElseIs(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];

        $report = $this->sync('a');

        $this->assertSame(1, $report['subjects']['s']['created']);
        $this->assertSame(['a'], $this->pages->find()->all()->extract('subject_id')->toList());
    }

    public function testOnlyTheSyncedRowIsUpdated(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/a-new', 'b' => '/b-new']];

        $report = $this->sync('a');

        $this->assertSame(1, $report['subjects']['s']['updated']);
        $this->assertSame('/a-new', $this->path('a'));
        $this->assertSame('/b', $this->path('b'), 'the other row waits for the next rebuild');
    }

    public function testAMovedPathRecordsARedirectFromTheOldOne(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/old']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/new']];

        $report = $this->sync('a');

        $this->assertSame(1, $report['redirects']);
        $redirect = $this->redirects->find()->firstOrFail();
        $this->assertSame('/old', $redirect->get('from_path'));
        $this->assertSame(SeoRedirectsTable::SOURCE_MOVED, $redirect->get('source'));
    }

    public function testAnUnchangedRowIsReportedUnchanged(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();

        $report = $this->sync('a');

        $this->assertSame(1, $report['subjects']['s']['unchanged']);
        $this->assertSame(0, $report['subjects']['s']['updated']);
    }

    public function testARowThatIsNoLongerPublicGoesGone(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['b' => '/b']];

        $report = $this->sync('a');

        $this->assertSame(1, $report['subjects']['s']['gone']);
        $this->assertSame(SeoPagesTable::STATUS_GONE, $this->statusOf('a'));
        $this->assertSame(SeoPagesTable::STATUS_LIVE, $this->statusOf('b'));
    }

    public function testSyncingAGoneRowAgainChangesNothing(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();
        ArraySubject::$pages = [];
        $this->sync('a');

        $report = $this->sync('a');

        $this->assertSame(0, $report['subjects']['s']['gone']);
        $this->assertSame(SeoPagesTable::STATUS_GONE, $this->statusOf('a'));
    }

    public function testARepublishedRowIsRevived(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();
        ArraySubject::$pages = [];
        $this->sync('a');
        ArraySubject::$pages = ['s' => ['a' => '/a']];

        $this->sync('a');

        $this->assertSame(SeoPagesTable::STATUS_LIVE, $this->statusOf('a'));
    }

    public function testAnIdThatWasNeverIndexedAndIsNotPublicDoesNothing(): void
    {
        $report = $this->sync('ghost');

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'gone' => 0], $report['subjects']['s']);
        $this->assertSame(0, $this->pages->find()->count());
    }

    public function testAPathHeldByALivePageOfAnotherRowIsAConflictAndSavesNothing(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/b', 'b' => '/b-moved']];

        $report = $this->sync('a');

        $this->assertCount(1, $report['conflicts']);
        $this->assertStringContainsString('s#a wants /b, held by s#b', $report['conflicts'][0]);
        $this->assertSame('/a', $this->path('a'), 'nothing was saved');
        $this->assertSame('/b', $this->path('b'));
    }

    public function testAPathHeldByAGonePageIsReused(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->sync('b');
        ArraySubject::$pages = ['s' => ['a' => '/b']];

        $report = $this->sync('a');

        $this->assertSame([], $report['conflicts']);
        $this->assertSame('/b', $this->path('a'));
        $this->assertSame(1, $this->pages->find()->count(), 'the gone page was cleared out of the way');
    }

    public function testAMovedPageDropsARedirectThatStartedAtItsNewPath(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/b']];
        $this->sync('a'); // /a now redirects to /b
        ArraySubject::$pages = ['s' => ['a' => '/a']];

        $this->sync('a'); // moved back: /a is the page again

        $this->assertSame(0, $this->redirects->find()->where(['from_path' => '/a'])->count());
        $this->assertSame(1, $this->redirects->find()->where(['from_path' => '/b'])->count());
    }

    public function testDeclaredOldPathsAreSyncedAndAClashWithAnotherPageIsReported(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$redirectsFrom = ['s' => ['a' => ['/legacy', '/b']]];

        $report = $this->sync('a');

        $this->assertSame(1, $report['redirects']);
        $this->assertSame(1, $this->redirects->find()->where(['from_path' => '/legacy'])->count());
        $this->assertSame(0, $this->redirects->find()->where(['from_path' => '/b'])->count());
        $this->assertStringContainsString('declares the old URL /b', $report['conflicts'][0]);
    }

    public function testAnUnknownSubjectKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown subject 'nope'");

        (new PageIndexer())->sync('nope', '1', ['s' => ArraySubject::class]);
    }

    public function testASyncFollowedByARebuildChangesNothingMore(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/a2', 'b' => '/b']];
        $this->sync('a');

        $counts = (new PageIndexer())->rebuild(['s' => ArraySubject::class])['subjects']['s'];

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'gone' => 0], $counts);
    }
}
