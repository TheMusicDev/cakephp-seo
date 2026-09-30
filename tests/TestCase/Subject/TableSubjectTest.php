<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Subject;

use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use LogicException;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Test\Subject\PagesTableSubject;

/**
 * TableSubject base: rows come from query(), ids from the primary key, and
 * toPage() hands a typed entity to pageFor(). Tested over the plugin's own
 * seo_pages table.
 */
final class TableSubjectTest extends TestCase
{
    private const ALIAS = 'TheMusicDev/Seo.SeoPages';

    private SeoPagesTable $pages;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get(self::ALIAS);
        $this->pages = $pages;
        $this->pages->deleteAll([]);
    }

    protected function tearDown(): void
    {
        $this->pages->deleteAll([]);
        parent::tearDown();
    }

    private function seed(string $id, string $status): int
    {
        $row = $this->pages->saveOrFail($this->pages->newEntity([
            'subject' => 'x',
            'subject_id' => $id,
            'path' => '/' . $id . '/',
            'title' => 'T' . $id,
            'status' => $status,
        ]));

        return (int)$row->id;
    }

    public function testRowsComeFromTheQueryOnly(): void
    {
        $live = $this->seed('a', 'live');
        $this->seed('b', 'gone');

        $rows = iterator_to_array((new PagesTableSubject(self::ALIAS))->rows(), false);

        $this->assertCount(1, $rows);
        $this->assertSame((string)$live, (new PagesTableSubject(self::ALIAS))->idOf($rows[0]));
    }

    public function testRowsAreStreamedInChunksInPrimaryKeyOrderWithNoneSkippedOrRepeated(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $ids[] = $this->seed($name, 'live'); // chunk size is 2: three queries
        }
        $this->seed('x', 'gone');

        $subject = new PagesTableSubject(self::ALIAS);
        $seen = [];
        foreach ($subject->rows() as $row) {
            $seen[] = (int)$subject->idOf($row);
        }

        $this->assertSame($ids, $seen);
    }

    public function testIdIsThePrimaryKeyAsAString(): void
    {
        $id = $this->seed('a', 'live');
        $subject = new PagesTableSubject(self::ALIAS);

        $this->assertSame((string)$id, $subject->idOf($this->pages->get($id)));
    }

    public function testToPageHandsAnEntityToPageFor(): void
    {
        $id = $this->seed('a', 'live');

        $page = (new PagesTableSubject(self::ALIAS))->toPage($this->pages->get($id));

        $this->assertSame('/a/', $page->path);
        $this->assertSame('From Ta', $page->title);
    }

    public function testANonEntityRowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PagesTableSubject(self::ALIAS))->toPage((object)['path' => '/x/']);
    }

    public function testCompositePrimaryKeysAreRefused(): void
    {
        $composite = new Table(['table' => 'seo_pages', 'alias' => 'Composite']);
        $composite->setPrimaryKey(['subject', 'subject_id']);
        TableRegistry::getTableLocator()->set('Composite', $composite);
        $id = $this->seed('a', 'live');

        $this->expectException(LogicException::class);
        try {
            (new PagesTableSubject('Composite'))->idOf($this->pages->get($id));
        } finally {
            TableRegistry::getTableLocator()->remove('Composite');
        }
    }
}
