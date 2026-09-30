<?php
declare(strict_types=1);

namespace TheMusicDev\Seo;

use Cake\Core\BasePlugin;

/**
 * SEO plugin. Goals, agreed decisions and open questions live in
 * docs/seo-plugin-design.md; build order in docs/delivery-plan.md.
 */
final class SeoPlugin extends BasePlugin
{
    /**
     * Ship the sitemap routes so hosts get them by just loading the plugin.
     */
    protected bool $routesEnabled = true;
}
