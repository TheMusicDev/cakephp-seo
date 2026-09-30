<?php
declare(strict_types=1);

/**
 * TheMusicDev/Seo ship-with defaults. Merged UNDER host values by the
 * plugin's config/bootstrap.php — hosts win (IdentityBridge convention,
 * docs/conventions.md).
 *
 * `subjects` is intentionally empty: the host decides which tables are
 * public pages and where they are routed (docs/seo-plugin-design.md, A3).
 */
return [
    'Seo' => [
        'subjects' => [],
        // URLs per sitemap file (the protocol allows 50,000); a subject with
        // more pages is split into numbered files.
        'sitemap' => ['pageSize' => 10000],
    ],
];
