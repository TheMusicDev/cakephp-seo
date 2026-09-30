<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * One row per public page (docs/seo-plugin-design.md, A4/A5/A7). Created with
 * every column the design needs so later features add no migration for it:
 * `schema` holds the stored JSON-LD (F5), `status` is live|gone (A9).
 * A page is identified by (subject, subject_id); `path` is the canonical path
 * and the request lookup key, so it is unique.
 */
final class CreateSeoPages extends BaseMigration
{
    public function change(): void
    {
        $this->table('seo_pages')
            ->addColumn('subject', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('subject_id', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('robots', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('lastmod', 'datetime', ['null' => true])
            ->addColumn('schema', 'json', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'live'])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['subject', 'subject_id'], ['unique' => true])
            ->addIndex(['path'], ['unique' => true])
            ->addIndex(['status'])
            ->create();
    }
}
