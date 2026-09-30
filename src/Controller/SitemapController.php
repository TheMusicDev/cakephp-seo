<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Controller;

use Cake\Controller\Controller;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use TheMusicDev\Seo\Lib\Sitemap;

/**
 * Serves the XML sitemaps (routes in the plugin's config/routes.php).
 */
class SitemapController extends Controller
{
    /**
     * `/sitemap-index.xml` and `/sitemap.xml`: the index of per-subject files.
     */
    public function index(): Response
    {
        return $this->xml((new Sitemap())->index());
    }

    /**
     * `/sitemap-{name}.xml`: one subject's URLs; unknown names are a 404.
     */
    public function view(string $name = ''): Response
    {
        $xml = (new Sitemap())->urlset($name);
        if ($xml === null) {
            throw new NotFoundException('No such sitemap.');
        }

        return $this->xml($xml);
    }

    /**
     * An XML response with the right content type.
     */
    private function xml(string $body): Response
    {
        return $this->response->withType('xml')->withStringBody($body);
    }
}
