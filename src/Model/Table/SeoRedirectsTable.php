<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Model\Table;

use Cake\ORM\Table;

/**
 * Old paths that redirect (301) to a page's current path. `from_path` is unique:
 * one old URL has one destination.
 */
class SeoRedirectsTable extends Table
{
    public const SOURCE_MOVED = 'moved';
    public const SOURCE_DECLARED = 'declared';

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('seo_redirects');
        $this->setDisplayField('from_path');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('SeoPages', [
            'className' => 'TheMusicDev/Seo.SeoPages',
            'foreignKey' => 'seo_page_id',
            'joinType' => 'INNER',
        ]);
    }
}
