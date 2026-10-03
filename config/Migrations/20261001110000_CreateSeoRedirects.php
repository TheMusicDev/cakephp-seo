<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Old URLs that now answer with a 301 to a page (design doc A8, A14). A redirect
 * points at the page ROW, not at a target path, so the target is resolved at
 * request time and a page that moves twice never forms a chain. `source` says how
 * it arose: `moved` (the rebuild saw the page's path change) or `declared` (the
 * subject listed it in `redirectsFrom`). Deleting a page deletes its redirects.
 */
final class CreateSeoRedirects extends BaseMigration
{
    /**
     * Create the seo_redirects table.
     */
    /**
     * Creates seo_redirects: old paths that 301 to a page row.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('seo_redirects')
            ->addColumn('from_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('seo_page_id', 'integer', ['null' => false])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false, 'default' => 'moved'])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['from_path'], ['unique' => true])
            ->addIndex(['seo_page_id'])
            ->addForeignKey('seo_page_id', 'seo_pages', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
