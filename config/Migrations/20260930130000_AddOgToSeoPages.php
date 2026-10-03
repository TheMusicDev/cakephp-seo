<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Per-page Open Graph overrides: `og_type` (e.g. `article` for a job posting;
 * the head helper defaults to `website`) and `og_image` (a path or URL; the
 * site default image is used when null). Adding them changes every page's
 * checksum, so the first rebuild afterwards rewrites each row once.
 */
final class AddOgToSeoPages extends BaseMigration
{
    /**
     * Adds the og_type and og_image columns used by the head helper.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('seo_pages')
            ->addColumn('og_type', 'string', ['limit' => 40, 'null' => true, 'after' => 'robots'])
            ->addColumn('og_image', 'string', ['limit' => 255, 'null' => true, 'after' => 'og_type'])
            ->update();
    }
}
