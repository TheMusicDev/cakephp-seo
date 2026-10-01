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
     * Rows fetched per query by rows().
     */
    protected int $chunkSize = 500;

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
     * The public rows, read in primary-key order a chunk at a time (keyset
     * paging) so memory stays flat however many rows there are.
     *
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    public function rows(): iterable
    {
        $primary = $this->primaryKey();
        $field = $this->table->aliasField($primary);
        $last = null;
        do {
            $query = $this->query($this->table)->orderBy([$field => 'ASC'], true)->limit($this->chunkSize);
            if ($last !== null) {
                $query->where([$field . ' >' => $last]);
            }
            $count = 0;
            foreach ($query->all() as $row) {
                $last = $row->get($primary);
                $count++;
                yield $row;
            }
        } while ($count === $this->chunkSize);
    }

    /**
     * @inheritDoc
     */
    public function row(string $id): mixed
    {
        return $this->query($this->table)
            ->where([$this->table->aliasField($this->primaryKey()) => $id])
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function idOf(mixed $row): string
    {
        return (string)$this->entity($row)->get($this->primaryKey());
    }

    /**
     * @inheritDoc
     */
    public function toPage(mixed $row): PageData
    {
        return $this->pageFor($this->entity($row));
    }

    /**
     * The single primary-key column.
     */
    private function primaryKey(): string
    {
        $primary = $this->table->getPrimaryKey();
        if (!is_string($primary)) {
            throw new LogicException('Composite primary keys are not supported by TableSubject.');
        }

        return $primary;
    }

    /**
     * Narrow a row to an entity or fail loudly.
     */
    private function entity(mixed $row): EntityInterface
    {
        if (!$row instanceof EntityInterface) {
            throw new InvalidArgumentException('TableSubject rows must be entities.');
        }

        return $row;
    }
}
