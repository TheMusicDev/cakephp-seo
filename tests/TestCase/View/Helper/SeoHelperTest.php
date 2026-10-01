<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\View\Helper;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\View\Helper\SeoHelper;

/**
 * The head helper: what each tag says, where each value comes from (override →
 * page row → metaTitle/metaDescription → site defaults), and escaping.
 */
final class SeoHelperTest extends TestCase
{
    private SeoPagesTable $pages;

    private mixed $originalSite;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        $this->pages->deleteAll([]);
        $this->originalSite = Configure::read('Seo.site');
        Configure::write('Seo.site', [
            'name' => 'Acme',
            'titleSeparator' => ' — ',
            'image' => '/images/share.png',
            'imageWidth' => 1200,
            'imageHeight' => 630,
            'imageType' => 'image/png',
            'twitterCard' => 'summary_large_image',
        ]);
    }

    protected function tearDown(): void
    {
        $this->pages->deleteAll([]);
        Configure::write('Seo.site', $this->originalSite);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $vars View variables.
     */
    private function head(string $url, array $vars = []): string
    {
        $view = new View(new ServerRequest(['url' => $url]));
        $view->set($vars);

        /** @var \TheMusicDev\Seo\View\Helper\SeoHelper $seo */
        $seo = $view->loadHelper('Seo', ['className' => SeoHelper::class]);

        return $seo->head();
    }

    /**
     * @param array<string, mixed> $fields Overrides for the row.
     */
    private function seed(string $path, array $fields = []): void
    {
        $this->pages->saveOrFail($this->pages->newEntity($fields + [
            'subject' => 'x',
            'subject_id' => $path,
            'path' => $path,
            'title' => 'About',
            'description' => 'About us.',
            'status' => 'live',
        ]));
    }

    public function testARowDrivesTheTitleDescriptionCanonicalAndOgType(): void
    {
        $this->seed('/about', ['og_type' => 'article']);

        $head = $this->head('/about');

        $this->assertStringContainsString('<title>About — Acme</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="About us.">', $head);
        $this->assertStringContainsString('<link href="http://localhost/about" rel="canonical">', $head);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $head);
        $this->assertStringContainsString('<meta property="og:title" content="About — Acme">', $head);
        $this->assertStringContainsString('<meta property="og:description" content="About us.">', $head);
        $this->assertStringContainsString('<meta property="og:url" content="http://localhost/about">', $head);
    }

    public function testTheCanonicalIsALinkTagNeverAMeta(): void
    {
        $this->seed('/about');

        $head = $this->head('/about');

        $this->assertSame(1, substr_count($head, 'rel="canonical"'));
        $this->assertStringNotContainsString('<meta rel="canonical"', $head);
    }

    public function testOgTypeDefaultsToWebsite(): void
    {
        $this->seed('/about');

        $this->assertStringContainsString('<meta property="og:type" content="website">', $this->head('/about'));
    }

    public function testASlashOnTheRequestStillFindsTheRow(): void
    {
        $this->seed('/about');

        $this->assertStringContainsString('<title>About — Acme</title>', $this->head('/about/'));
    }

    public function testThePageTitledWithTheSiteNameIsNotSuffixed(): void
    {
        $this->seed('/', ['title' => 'Acme']);

        $this->assertStringContainsString('<title>Acme</title>', $this->head('/'));
    }

    public function testNoSuffixWhenThereIsNoSiteName(): void
    {
        Configure::write('Seo.site.name', '');
        $this->seed('/about');

        $this->assertStringContainsString('<title>About</title>', $this->head('/about'));
    }

    public function testAPageWithNoRowFallsBackToMetaTitleAndMetaDescription(): void
    {
        $head = $this->head('/apply', ['metaTitle' => 'Apply — Dev', 'metaDescription' => 'Apply here.']);

        $this->assertStringContainsString('<title>Apply — Dev — Acme</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="Apply here.">', $head);
    }

    public function testAPageWithNothingAtAllUsesTheSiteNameAndAnEmptyDescription(): void
    {
        $head = $this->head('/missing');

        $this->assertStringContainsString('<title>Acme</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="">', $head);
    }

    public function testTheCanonicalOfAPageWithNoRowIsItsPathWithoutATrailingSlash(): void
    {
        $this->assertStringContainsString('<link href="http://localhost/missing" rel="canonical">', $this->head('/missing/'));
    }

    public function testAnOverrideBeatsTheRowAndTheFallbacks(): void
    {
        $this->seed('/careers', ['title' => 'Careers', 'description' => 'Row description.']);

        $head = $this->head('/careers', [
            'metaTitle' => 'Ignored',
            'seoOverride' => [
                'title' => 'Careers — page 2',
                'description' => 'Override description.',
                'canonical' => 'http://localhost/careers?page=2',
                'robots' => 'noindex,follow',
                'ogType' => 'article',
                'ogImage' => '/images/page.png',
            ],
        ]);

        $this->assertStringContainsString('<title>Careers — page 2 — Acme</title>', $head);
        $this->assertStringContainsString('content="Override description."', $head);
        $this->assertStringContainsString('<link href="http://localhost/careers?page=2" rel="canonical">', $head);
        $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $head);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $head);
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/images/page.png">', $head);
    }

    public function testAnOverrideCanonicalMayBeAPath(): void
    {
        $head = $this->head('/x', ['seoOverride' => ['canonical' => '/elsewhere']]);

        $this->assertStringContainsString('<link href="http://localhost/elsewhere" rel="canonical">', $head);
    }

    public function testRobotsComesFromTheRowAndIsOtherwiseOmitted(): void
    {
        $this->seed('/private', ['robots' => 'noindex']);
        $this->seed('/public');

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->head('/private'));
        $this->assertStringNotContainsString('name="robots"', $this->head('/public'));
    }

    public function testTheDefaultImageCarriesItsDimensionsAPageImageDoesNot(): void
    {
        $this->seed('/with-default');
        $this->seed('/with-own', ['og_image' => '/images/own.png']);

        $default = $this->head('/with-default');
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/images/share.png">', $default);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $default);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $default);
        $this->assertStringContainsString('<meta property="og:image:type" content="image/png">', $default);

        $own = $this->head('/with-own');
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/images/own.png">', $own);
        $this->assertStringNotContainsString('og:image:width', $own);
    }

    public function testAnAbsoluteImageUrlIsLeftAlone(): void
    {
        $this->seed('/cdn', ['og_image' => 'https://cdn.example/img.png']);

        $this->assertStringContainsString('content="https://cdn.example/img.png"', $this->head('/cdn'));
    }

    public function testNoImageTagsWhenThereIsNoImageAnywhere(): void
    {
        Configure::write('Seo.site.image', null);
        $this->seed('/about');

        $head = $this->head('/about');

        $this->assertStringNotContainsString('og:image', $head);
        $this->assertStringNotContainsString('twitter:image', $head);
    }

    public function testTwitterTagsMirrorOpenGraph(): void
    {
        $this->seed('/about');

        $head = $this->head('/about');

        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $head);
        $this->assertStringContainsString('<meta name="twitter:title" content="About — Acme">', $head);
        $this->assertStringContainsString('<meta name="twitter:description" content="About us.">', $head);
        $this->assertStringContainsString('<meta name="twitter:image" content="http://localhost/images/share.png">', $head);
    }

    public function testValuesAreEscaped(): void
    {
        $this->seed('/evil', ['title' => 'A "quoted" <script>x</script> & more', 'description' => '"><script>y</script>']);

        $head = $this->head('/evil');

        $this->assertStringNotContainsString('<script>', $head);
        $this->assertStringContainsString('<title>A &quot;quoted&quot; &lt;script&gt;x&lt;/script&gt; &amp; more — Acme</title>', $head);
    }

    public function testAGoneRowIsIgnored(): void
    {
        $this->seed('/old', ['status' => 'gone', 'title' => 'Old page']);

        $this->assertStringNotContainsString('Old page', $this->head('/old'));
    }

    public function testAnExplicitSeoPageVariableSelectsTheRow(): void
    {
        $this->seed('/real-path', ['subject' => 'Blog.Posts', 'subject_id' => '7', 'title' => 'Chosen']);

        $head = $this->head('/some/other/url', ['seoPage' => ['Blog.Posts', 7]]);

        $this->assertStringContainsString('<title>Chosen — Acme</title>', $head);
        $this->assertStringContainsString('<link href="http://localhost/real-path" rel="canonical">', $head);
    }

    /**
     * The decoded JSON-LD document of a head, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    private function graph(string $head): ?array
    {
        if (preg_match('#<script type="application/ld\+json">(.*)</script>#s', $head, $m) !== 1) {
            return null;
        }

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSiteNodesThenThePageNodesFormOneGraph(): void
    {
        Configure::write('Seo.site.schema', [['@type' => 'Organization', 'name' => 'Acme']]);
        $this->seed('/careers/x', ['schema' => [['@type' => 'JobPosting', 'title' => 'X']]]);

        $document = $this->graph($this->head('/careers/x'));

        $this->assertSame('https://schema.org', $document['@context']);
        $this->assertSame(['Organization', 'JobPosting'], array_column($document['@graph'], '@type'));
    }

    public function testPagesWithNoRowStillGetTheSiteWideNodes(): void
    {
        Configure::write('Seo.site.schema', [['@type' => 'Organization', 'name' => 'Acme']]);

        $document = $this->graph($this->head('/no-row'));

        $this->assertSame(['Organization'], array_column($document['@graph'], '@type'));
    }

    public function testAGoneRowContributesNoNodes(): void
    {
        Configure::write('Seo.site.schema', [['@type' => 'Organization', 'name' => 'Acme']]);
        $this->seed('/old', ['status' => 'gone', 'schema' => [['@type' => 'JobPosting', 'title' => 'Old']]]);

        $document = $this->graph($this->head('/old'));

        $this->assertSame(['Organization'], array_column($document['@graph'], '@type'));
    }

    public function testNoScriptBlockWhenThereAreNoNodesAtAll(): void
    {
        Configure::write('Seo.site.schema', []);
        $this->seed('/plain');

        $this->assertStringNotContainsString('ld+json', $this->head('/plain'));
    }

    public function testPathsUnderUrlAndItemBecomeAbsoluteAtAnyDepth(): void
    {
        $this->seed('/careers/x', ['schema' => [
            ['@type' => 'JobPosting', 'url' => '/careers/x', 'sameAs' => '/not-touched'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'X'],
            ]],
            ['@type' => 'Organization', 'url' => 'https://already.example/'],
        ]]);

        $nodes = $this->graph($this->head('/careers/x'))['@graph'];

        $this->assertSame('http://localhost/careers/x', $nodes[0]['url']);
        $this->assertSame('/not-touched', $nodes[0]['sameAs'], 'only url / item are rewritten');
        $this->assertSame('http://localhost/', $nodes[1]['itemListElement'][0]['item']);
        $this->assertSame('https://already.example/', $nodes[2]['url']);
    }

    /**
     * A value holding `</script>` must not be able to end the block and inject
     * markup: the only `</script>` in the output is the one that closes it.
     */
    public function testAValueCannotBreakOutOfTheScriptBlock(): void
    {
        $evil = '</script><script>alert(1)</script>';
        $this->seed('/evil', ['schema' => [['@type' => 'JobPosting', 'description' => $evil]]]);

        $head = $this->head('/evil');

        $this->assertSame(1, substr_count($head, '</script>'));
        $this->assertSame($evil, $this->graph($head)['@graph'][0]['description'], 'the value itself is intact once decoded');
    }

    public function testQuotesAmpersandsAndAnglesAreHexEscaped(): void
    {
        $this->seed('/chars', ['schema' => [['@type' => 'Thing', 'name' => 'A & B "quoted" it\'s <b>']]]);

        $head = $this->head('/chars');

        foreach (['\u0026', '\u0022', '\u0027', '\u003C', '\u003E'] as $escape) {
            $this->assertStringContainsString($escape, $head);
        }
        $this->assertSame('A & B "quoted" it\'s <b>', $this->graph($head)['@graph'][0]['name']);
    }

    public function testUnicodeAndSlashesAreNotNeedlesslyEscaped(): void
    {
        $this->seed('/uni', ['schema' => [['@type' => 'Thing', 'name' => 'Café — 10–15 hours', 'url' => 'https://a.test/b']]]);

        $head = $this->head('/uni');

        $this->assertStringContainsString('Café — 10–15 hours', $head);
        $this->assertStringContainsString('https://a.test/b', $head);
    }

    public function testTheRowSchemaSurvivesTheJsonColumnAsAnArray(): void
    {
        $nodes = [['@type' => 'JobPosting', 'title' => 'X', 'baseSalary' => ['value' => ['value' => 45]]]];
        $this->seed('/round', ['schema' => $nodes]);

        $row = $this->pages->find()->where(['path' => '/round'])->firstOrFail();

        $this->assertSame($nodes, $row->schema);
    }
}
