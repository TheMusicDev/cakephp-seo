<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $builder): void {
    // Sitemap: /sitemap-index.xml is what robots.txt advertises, /sitemap.xml
    // is the same index under the conventional name, and each subject with
    // live pages gets /sitemap-{subject-slug}.xml. The index route comes first
    // so `index` is never read as a subject slug.
    $builder->plugin('TheMusicDev/Seo', ['path' => '/'], static function (RouteBuilder $routes): void {
        $routes->get('/sitemap-index.xml', ['controller' => 'Sitemap', 'action' => 'index']);
        $routes->get('/sitemap.xml', ['controller' => 'Sitemap', 'action' => 'index']);
        $routes->get('/sitemap-{name}.xml', ['controller' => 'Sitemap', 'action' => 'view'])
            ->setPatterns(['name' => '[a-z0-9-]+'])
            ->setPass(['name']);
    });
};
