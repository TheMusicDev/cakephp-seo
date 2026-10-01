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
        // Site-wide head defaults used by the SeoHelper. `name` is appended to every
        // page title ("About — Name") except a page titled exactly the name.
        // `image` is the default Open Graph / Twitter image (a path or URL); its
        // width/height/type are emitted only for that default image.
        'site' => [
            'name' => '',
            'titleSeparator' => ' — ',
            'image' => null,
            'imageWidth' => null,
            'imageHeight' => null,
            'imageType' => null,
            'twitterCard' => 'summary_large_image',
            // Site-wide JSON-LD nodes (Organization, WebSite…) added to every page's
            // @graph; build them with TheMusicDev\Seo\Schema\Schema.
            'schema' => [],
        ],
        // Paths the redirects middleware never looks up (it queries the database).
        // List anything that is not a Seo page: the admin, a health probe, uploads.
        'redirects' => ['skip' => []],
        // URLs per sitemap file (the protocol allows 50,000); a subject with
        // more pages is split into numbered files.
        'sitemap' => ['pageSize' => 10000],
    ],
];
