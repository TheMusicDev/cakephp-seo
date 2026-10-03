<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

// One app route (the uptime probe a host would have); the plugin's own routes (sitemap, robots.txt) are loaded by
// the plugin collection.
return static function (RouteBuilder $routes): void {
    $routes->connect('/health', ['controller' => 'Health', 'action' => 'index']);
};
