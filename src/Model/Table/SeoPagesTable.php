<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Model\Table;

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
}
