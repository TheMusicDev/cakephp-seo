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
use TheMusicDev\Seo\Model\Table\SeoRedirectsTable;
use TheMusicDev\Seo\Test\Subject\ArraySubject;

/**
 * Page index rebuild: rows keyed by (subject, id), gone/revive lifecycle,
 * idempotence, path conflicts. Uses ArraySubject only — nothing here depends
 * on the host or another plugin.
 */
final class PageIndexerTest extends TestCase
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
        // Children before parents (the usual rule; redirects also cascade from pages).
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

        $this->assertSame(['subjects' => [], 'conflicts' => [], 'redirects' => 0], $report);
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

    public function testAChangedTitleOrSchemaIsDetectedButAnUnchangedPageIsNot(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        ArraySubject::$schema = [['@type' => 'Thing', 'name' => 'One']];
        $this->rebuild();

        $this->assertSame(1, $this->rebuild()['subjects']['s']['unchanged']);

        ArraySubject::$schema = [['@type' => 'Thing', 'name' => 'Two']];
        $report = $this->rebuild();

        $this->assertSame(1, $report['subjects']['s']['updated']);
        $this->assertSame(1, $this->rebuild()['subjects']['s']['unchanged']);
    }

    public function testRowsStoredBeforeTheChecksumExistedAreRewrittenOnceThenLeftAlone(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/']];
        $this->rebuild();
        $this->pages->updateAll(['checksum' => null], []); // as after the upgrade migration

        $this->assertSame(1, $this->rebuild()['subjects']['s']['updated']);
        $this->assertSame(1, $this->rebuild()['subjects']['s']['unchanged']);
    }

    public function testAPageMayMoveOntoAPathAnotherPageIsLeavingInTheSameRun(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];
        $this->rebuild();

        // b is read first and wants /a/, which a still holds until it moves to /c/.
        ArraySubject::$pages = ['s' => ['b' => '/a/', 'a' => '/c/']];
        $report = $this->rebuild();

        $this->assertSame([], $report['conflicts']);
        $this->assertSame('/c/', $this->pages->find()->where(['subject_id' => 'a'])->firstOrFail()->path);
        $this->assertSame('/a/', $this->pages->find()->where(['subject_id' => 'b'])->firstOrFail()->path);
    }

    public function testTwoPagesSwappingPathsAreReportedAndNothingChanges(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];
        $this->rebuild();

        ArraySubject::$pages = ['s' => ['a' => '/b/', 'b' => '/a/']];
        $report = $this->rebuild();

        $this->assertCount(2, $report['conflicts']);
        $this->assertSame('/a/', $this->pages->find()->where(['subject_id' => 'a'])->firstOrFail()->path);
        $this->assertSame('/b/', $this->pages->find()->where(['subject_id' => 'b'])->firstOrFail()->path);
    }

    /**
     * The redirects as `from_path => [target page id, source]`.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private function redirectMap(): array
    {
        $map = [];
        foreach ($this->redirects->find()->all() as $redirect) {
            $map[$redirect->from_path] = [(int)$redirect->seo_page_id, $redirect->source];
        }

        return $map;
    }

    private function pageId(string $id): int
    {
        return (int)$this->pages->find()->where(['subject_id' => $id])->firstOrFail()->id;
    }

    public function testAPathChangeRecordsTheOldPathAsARedirectToThePage(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/old']];
        $this->rebuild();

        ArraySubject::$pages = ['s' => ['a' => '/new']];
        $report = $this->rebuild();

        $this->assertSame(1, $report['redirects']);
        $this->assertSame(['/old' => [$this->pageId('a'), 'moved']], $this->redirectMap());
    }

    public function testRebuildingAgainRecordsNothingNew(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/old']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/new']];
        $this->rebuild();

        $report = $this->rebuild();

        $this->assertSame(0, $report['redirects']);
        $this->assertCount(1, $this->redirectMap());
    }

    public function testAPageThatMovesTwiceHasBothOldPathsPointingAtTheSameRowSoNothingChains(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/b']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/c']];
        $this->rebuild();

        $id = $this->pageId('a');
        $this->assertSame(['/a' => [$id, 'moved'], '/b' => [$id, 'moved']], $this->redirectMap());
    }

    public function testAnOldPathThatComesBackIntoUseLosesItsRedirect(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/b']];
        $this->rebuild();
        $this->assertArrayHasKey('/a', $this->redirectMap());

        ArraySubject::$pages = ['s' => ['a' => '/a']]; // back home
        $this->rebuild();

        $this->assertArrayNotHasKey('/a', $this->redirectMap(), 'a live path is never also a redirect');
        $this->assertArrayHasKey('/b', $this->redirectMap(), 'the path it left in between still redirects');
    }

    public function testAnOldPathTakenByAnotherPageLosesItsRedirect(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        $this->rebuild();

        // a leaves /a for /c; b moves onto the vacated /a in the same run.
        ArraySubject::$pages = ['s' => ['b' => '/a', 'a' => '/c']];
        $report = $this->rebuild();

        $this->assertSame([], $report['conflicts']);
        $map = $this->redirectMap();
        $this->assertArrayNotHasKey('/a', $map, '/a is b\'s live path now');
        $this->assertSame([$this->pageId('b'), 'moved'], $map['/b']);
    }

    public function testDeclaredOldPathsBecomeRedirectsNormalizedAndDeduplicated(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a']];
        ArraySubject::$redirectsFrom = ['s' => ['a' => ['/legacy/a', '/legacy/a/', 'legacy/a', '/', '/a']]];

        $report = $this->rebuild();

        $this->assertSame(1, $report['redirects']);
        $this->assertSame(['/legacy/a' => [$this->pageId('a'), 'declared']], $this->redirectMap());
    }

    public function testChangingTheDeclaredListRemovesDroppedPathsButKeepsMovedOnes(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/old']];
        ArraySubject::$redirectsFrom = ['s' => ['a' => ['/legacy']]];
        $this->rebuild();

        ArraySubject::$pages = ['s' => ['a' => '/new']];
        ArraySubject::$redirectsFrom = ['s' => ['a' => []]];
        $this->rebuild();

        $this->assertSame(['/old' => [$this->pageId('a'), 'moved']], $this->redirectMap());
    }

    public function testADeclaredPathThatIsAnotherPagesPathIsReportedAndSkipped(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/a', 'b' => '/b']];
        ArraySubject::$redirectsFrom = ['s' => ['b' => ['/a']]];

        $report = $this->rebuild();

        $this->assertCount(1, $report['conflicts']);
        $this->assertStringContainsString('/a', $report['conflicts'][0]);
        $this->assertSame([], $this->redirectMap());
    }

    public function testAPageThatIsDeletedTakesItsRedirectsWithIt(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/w']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/x']];
        $this->rebuild();
        $this->assertArrayHasKey('/w', $this->redirectMap());

        // a disappears and b takes its path: a's row is deleted, so are its redirects.
        ArraySubject::$pages = ['s' => ['b' => '/x']];
        $this->rebuild();

        $this->assertSame([], $this->redirectMap());
    }

    public function testAGonePageKeepsItsRedirectsSoTheyCanAnswerGone(): void
    {
        ArraySubject::$pages = ['s' => ['a' => '/w']];
        $this->rebuild();
        ArraySubject::$pages = ['s' => ['a' => '/x']];
        $this->rebuild();

        ArraySubject::$pages = [];
        $this->rebuild();

        $this->assertArrayHasKey('/w', $this->redirectMap());
        $this->assertSame(SeoPagesTable::STATUS_GONE, $this->pages->get($this->pageId('a'))->status);
    }
}
