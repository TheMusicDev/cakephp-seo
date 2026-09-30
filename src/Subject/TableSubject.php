<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use InvalidArgumentException;
use LogicException;

/**
 * Base for the common case: pages backed by a table. The `Seo.subjects` key
 * is the table alias. Subclasses say which rows are public (`query()`) and
 * how one row becomes a page (`pageFor()`) — any PHP is fine, so a table needs
 * no `slug` or `title` column.
 */
abstract class TableSubject implements SubjectInterface
{
    use LocatorAwareTrait;

    protected Table $table;

    /**
     * @param string $key The `Seo.subjects` key: the table alias.
     */
    public function __construct(string $key)
    {
        $this->table = $this->fetchTable($key);
    }

    /**
     * The public rows (e.g. `$table->find('published')`).
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    abstract protected function query(Table $table): SelectQuery;

    /**
     * One entity → one page.
     */
    abstract protected function pageFor(EntityInterface $row): PageData;

    /**
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    public function rows(): iterable
    {
        /** @var iterable<\Cake\Datasource\EntityInterface> */
        return $this->query($this->table)->all();
    }

    /**
     * @inheritDoc
     */
    public function idOf(object $row): string
    {
        $primary = $this->table->getPrimaryKey();
        if (!is_string($primary)) {
            throw new LogicException('Composite primary keys are not supported by TableSubject.');
        }

        return (string)$this->entity($row)->get($primary);
    }

    /**
     * @inheritDoc
     */
    public function toPage(object $row): PageData
    {
        return $this->pageFor($this->entity($row));
    }

    /**
     * Narrow a row to an entity or fail loudly.
     */
    private function entity(object $row): EntityInterface
    {
        if (!$row instanceof EntityInterface) {
            throw new InvalidArgumentException('TableSubject rows must be entities.');
        }

        return $row;
    }
}
