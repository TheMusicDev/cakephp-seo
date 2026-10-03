<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * A fingerprint of a page's content columns as last written. Lets the rebuild
 * tell "unchanged" from "changed" without loading every stored row (and its
 * JSON-LD) into memory. Existing rows have none, so the first rebuild after
 * this migration rewrites each of them once.
 */
final class AddChecksumToSeoPages extends BaseMigration
{
    /**
     * Adds the checksum column the rebuild compares to skip unchanged pages.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('seo_pages')
            ->addColumn('checksum', 'string', ['limit' => 40, 'null' => true, 'after' => 'schema'])
            ->update();
    }
}
