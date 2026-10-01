<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * /robots.txt as the host serves it: plain text, rules from config, the sitemap
 * line built from the route, and a blanket disallow on a host that is not allowed.
 */
final class RobotsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private mixed $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('Seo.robots');
    }

    protected function tearDown(): void
    {
        Configure::write('Seo.robots', $this->original);
        parent::tearDown();
    }

    public function testItIsServedAsPlainTextWithTheConfiguredRules(): void
    {
        Configure::write('Seo.robots', ['allowHosts' => [], 'rules' => [['userAgent' => '*', 'disallow' => ['/private']]]]);

        $this->get('/robots.txt');

        $this->assertResponseOk();
        $this->assertContentType('text/plain');
        $this->assertResponseContains("User-agent: *\nDisallow: /private\n");
    }

    public function testTheSitemapLineIsAnAbsoluteUrlBuiltFromTheSitemapRoute(): void
    {
        Configure::write('Seo.robots', ['allowHosts' => []]);

        $this->get('/robots.txt');

        $this->assertResponseContains("\nSitemap: http://localhost/sitemap-index.xml\n");
    }

    public function testAHostThatIsNotAllowedIsToldToCrawlNothing(): void
    {
        Configure::write('Seo.robots', ['allowHosts' => ['production.example']]);

        $this->get('/robots.txt'); // the test host is localhost

        $this->assertResponseOk();
        $this->assertResponseContains("User-agent: *\nDisallow: /\n");
        $this->assertResponseContains('allowHosts'); // the comment says why
        $this->assertResponseNotContains('Sitemap');
    }

    public function testTheAllowedHostGetsTheRealRules(): void
    {
        Configure::write('Seo.robots', ['allowHosts' => ['production.example']]);
        $this->configRequest(['headers' => ['Host' => 'production.example']]);

        $this->get('/robots.txt');

        $this->assertResponseContains('Allow: /');
        $this->assertResponseContains('Sitemap:');
    }
}
