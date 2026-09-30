<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;

/**
 * The page index: one row per public page, kept correct by
 * `bin/cake seo rebuild`. `path` is unique — two pages cannot share a URL.
 */
class SeoPagesTable extends Table
{
    public const STATUS_LIVE = 'live';
    public const STATUS_GONE = 'gone';

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('seo_pages');
        $this->setDisplayField('path');
        $this->addBehavior('Timestamp');
    }

    /**
     * @inheritDoc
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['path'], 'That path is already used by another page.'));

        return $rules;
    }

    /**
     * Pages that are public right now (everything but `gone`).
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface> $query
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    public function findLive(SelectQuery $query): SelectQuery
    {
        return $query->where([$this->aliasField('status') => self::STATUS_LIVE]);
    }
}
