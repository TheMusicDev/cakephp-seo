<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Lib;

use Cake\TestSuite\TestCase;
use TheMusicDev\Seo\Lib\Robots;

/**
 * robots.txt rendering: groups, the production-host rule, the sitemap line, and
 * that config cannot inject extra directives.
 */
final class RobotsTest extends TestCase
{
    private const SITEMAP = 'https://acme.test/sitemap-index.xml';

    public function testWithNoRulesEveryoneMayCrawlEverything(): void
    {
        $this->assertSame(
            "User-agent: *\nAllow: /\n\nSitemap: https://acme.test/sitemap-index.xml\n",
            Robots::render([], 'acme.test', self::SITEMAP),
        );
    }

    public function testRulesBecomeUserAgentGroupsWithAllowAndDisallowLines(): void
    {
        $text = Robots::render(['rules' => [
            ['userAgent' => 'Googlebot', 'allow' => ['/'], 'disallow' => ['/private']],
            ['userAgent' => '*', 'allow' => ['/'], 'disallow' => ['/admin', '/files']],
        ]], 'acme.test', null);

        $this->assertSame(
            "User-agent: Googlebot\nAllow: /\nDisallow: /private\n\n"
            . "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /files\n",
            $text,
        );
    }

    public function testAGroupWithNoAllowOrDisallowStillNamesItsAgent(): void
    {
        $this->assertSame("User-agent: BadBot\n", Robots::render(['rules' => [['userAgent' => 'BadBot']]], 'acme.test', null));
    }

    public function testTheSitemapLineIsLastAndOmittedWhenThereIsNoSitemap(): void
    {
        $this->assertStringEndsWith("\nSitemap: https://acme.test/sitemap-index.xml\n", Robots::render([], 'acme.test', self::SITEMAP));
        $this->assertStringNotContainsString('Sitemap', Robots::render([], 'acme.test', null));
    }

    public function testAHostThatIsNotAllowedGetsDisallowAllAndNoSitemap(): void
    {
        $text = Robots::render(['allowHosts' => ['acme.test'], 'rules' => [['userAgent' => '*', 'allow' => ['/']]]], 'staging.acme.test', self::SITEMAP);

        $this->assertSame(
            "# Crawling is disabled on this host: it is not in Seo.robots.allowHosts.\nUser-agent: *\nDisallow: /\n",
            $text,
        );
        $this->assertStringNotContainsString('Sitemap', $text);
    }

    public function testTheDisallowAllAnswerExplainsItself(): void
    {
        $text = Robots::render(['allowHosts' => ['acme.test']], 'localhost', self::SITEMAP);

        $this->assertStringStartsWith('# ', $text, 'a comment first, which crawlers ignore');
        $this->assertStringContainsString('allowHosts', $text);
    }

    public function testTheAllowedHostIsCrawlableAndTheComparisonIgnoresCase(): void
    {
        $config = ['allowHosts' => ['Acme.Test']];

        $this->assertStringContainsString('Allow: /', Robots::render($config, 'ACME.test', self::SITEMAP));
        $this->assertStringContainsString('Sitemap:', Robots::render($config, 'acme.TEST', self::SITEMAP));
    }

    public function testNoAllowListMeansNoRestriction(): void
    {
        $this->assertStringContainsString('Allow: /', Robots::render(['allowHosts' => []], 'anything.example', self::SITEMAP));
    }

    public function testLineBreaksInConfigCannotInjectDirectives(): void
    {
        $text = Robots::render(['rules' => [
            ['userAgent' => "*\nDisallow: /", 'allow' => ["/ok\r\nSitemap: https://evil.test/x"]],
        ]], 'acme.test', "https://acme.test/s.xml\nDisallow: /");

        $this->assertSame(
            "User-agent: *Disallow: /\nAllow: /okSitemap: https://evil.test/x\n\nSitemap: https://acme.test/s.xmlDisallow: /\n",
            $text,
        );
        $this->assertSame(1, substr_count($text, "\nSitemap:"), 'only the real sitemap line');
    }

    public function testEmptyValuesAndGroupsWithNoAgentAreDropped(): void
    {
        $text = Robots::render(['rules' => [
            ['userAgent' => '', 'allow' => ['/']],
            ['userAgent' => '*', 'allow' => ['', '/a'], 'disallow' => ['  ']],
        ]], 'acme.test', null);

        $this->assertSame("User-agent: *\nAllow: /a\n", $text);
    }
}
