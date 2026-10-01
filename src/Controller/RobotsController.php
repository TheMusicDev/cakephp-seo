<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Routing\Router;
use TheMusicDev\Seo\Lib\Robots;

/**
 * Serves `/robots.txt`, generated from `Seo.robots` (see Lib\Robots). A static
 * `webroot/robots.txt` would be served by the web server before Cake sees the
 * request and shadow this, so a host deletes it.
 */
class RobotsController extends Controller
{
    /**
     * `/robots.txt`: crawl rules, plus the sitemap line built from the sitemap route.
     */
    public function index(): Response
    {
        $text = Robots::render(
            (array)Configure::read('Seo.robots', []),
            $this->getRequest()->getUri()->getHost(),
            Router::url(['_name' => 'seo.sitemap'], true),
        );

        return $this->response->withType('text')->withStringBody($text);
    }
}
