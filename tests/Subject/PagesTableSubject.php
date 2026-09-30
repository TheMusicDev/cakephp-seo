<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\Subject;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use TheMusicDev\Seo\Subject\PageData;
use TheMusicDev\Seo\Subject\TableSubject;

/**
 * Table-backed test subject over the plugin's own `seo_pages` table — a real
 * table the plugin owns, so TableSubject can be tested without another plugin.
 */
final class PagesTableSubject extends TableSubject
{
    /**
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    protected function query(Table $table): SelectQuery
    {
        return $table->find()->where(['status' => 'live']);
    }

    protected function pageFor(EntityInterface $row): PageData
    {
        return new PageData(path: (string)$row->get('path'), title: 'From ' . $row->get('title'));
    }
}
