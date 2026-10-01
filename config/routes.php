<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $builder): void {
    // Sitemap: /sitemap-index.xml is the index (what robots.txt advertises; the name Astro
    // published, so existing submissions keep working) and each subject with live pages
    // gets /sitemap-{subject-slug}.xml. The index route comes first so `index` is never
    // read as a subject slug. There is deliberately no /sitemap.xml alias here; a host
    // that wants one adds a redirect to `seo.sitemap` in its own routes.
    $builder->plugin('TheMusicDev/Seo', ['path' => '/'], static function (RouteBuilder $routes): void {
        // Named so the robots.txt sitemap line is built from the route, not typed.
        $routes->get('/sitemap-index.xml', ['controller' => 'Sitemap', 'action' => 'index'], 'seo.sitemap');
        $routes->get('/sitemap-{name}.xml', ['controller' => 'Sitemap', 'action' => 'view'])
            ->setPatterns(['name' => '[a-z0-9-]+'])
            ->setPass(['name']);

        // robots.txt, generated from Seo.robots (a static webroot/robots.txt would shadow it).
        $routes->get('/robots.txt', ['controller' => 'Robots', 'action' => 'index'], 'seo.robots');
    });
};
