<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Middleware;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Http\Exception\GoneException;
use Cake\Http\Response;
use Cake\Http\ServerRequestFactory;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use TheMusicDev\Seo\Middleware\SeoRedirectsMiddleware;
use TheMusicDev\Seo\Model\Entity\SeoPage;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Model\Table\SeoRedirectsTable;

/**
 * The redirect/410 rules on the middleware alone: what a live, gone, moved and
 * unknown path each get, and that a lookup failure never takes a page down.
 */
final class SeoRedirectsMiddlewareTest extends TestCase
{
    private SeoPagesTable $pages;

    private SeoRedirectsTable $redirects;

    /**
     * The request the handler last received (a one-element holder).
     *
     * @var \ArrayObject<int, \Psr\Http\Message\ServerRequestInterface>
     */
    private ArrayObject $reached;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');
        $this->pages = $pages;
        /** @var \TheMusicDev\Seo\Model\Table\SeoRedirectsTable $redirects */
        $redirects = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects');
        $this->redirects = $redirects;
        $this->redirects->deleteAll([]);
        $this->pages->deleteAll([]);
        $this->reached = new ArrayObject();
    }

    protected function tearDown(): void
    {
        $this->redirects->deleteAll([]);
        $this->pages->deleteAll([]);
        parent::tearDown();
    }

    private function page(string $path, string $status = 'live'): SeoPage
    {
        /** @var \TheMusicDev\Seo\Model\Entity\SeoPage */
        return $this->pages->saveOrFail($this->pages->newEntity([
            'subject' => 's', 'subject_id' => $path, 'path' => $path, 'title' => 'T', 'status' => $status,
        ]));
    }

    private function redirect(string $from, SeoPage $page): void
    {
        $this->redirects->saveOrFail($this->redirects->newEntity([
            'from_path' => $from, 'seo_page_id' => $page->id, 'source' => 'moved',
        ]));
    }

    private function through(
        string $uri,
        string $method = 'GET',
        ?SeoRedirectsMiddleware $middleware = null,
    ): ResponseInterface {
        $request = ServerRequestFactory::fromGlobals([
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => (string)parse_url($uri, PHP_URL_QUERY),
            'REQUEST_METHOD' => $method,
            'HTTP_HOST' => 'example.test',
        ]);
        $reached = $this->reached;
        $handler = new class ($reached) implements RequestHandlerInterface {
            /**
             * @param \ArrayObject<int, \Psr\Http\Message\ServerRequestInterface> $reached
             */
            public function __construct(private ArrayObject $reached)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached[0] = $request;

                return (new Response())->withStringBody('reached');
            }
        };

        return ($middleware ?? new SeoRedirectsMiddleware())->process($request, $handler);
    }

    public function testALivePagePassesThroughWithItsRowAttachedToTheRequest(): void
    {
        $page = $this->page('/about');

        $response = $this->through('/about');

        $this->assertSame('reached', (string)$response->getBody());
        $attached = $this->reached[0]->getAttribute('seo.page');
        $this->assertInstanceOf(SeoPage::class, $attached);
        $this->assertSame($page->id, $attached->id);
    }

    public function testTheRootIsFoundToo(): void
    {
        $this->page('/');

        $this->through('/');

        $this->assertInstanceOf(SeoPage::class, $this->reached[0]->getAttribute('seo.page'));
    }

    public function testAGonePageAnswers410(): void
    {
        $this->page('/removed', 'gone');

        $this->expectException(GoneException::class);
        $this->through('/removed');
    }

    public function testAnOldPathRedirects301ToThePagesCurrentPathKeepingTheQuery(): void
    {
        $this->redirect('/old', $this->page('/new'));

        $response = $this->through('/old?ref=mail&x=1');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new?ref=mail&x=1', $response->getHeaderLine('Location'));
    }

    public function testAnOldPathOfAGonePageAnswers410(): void
    {
        $this->redirect('/old', $this->page('/new', 'gone'));

        $this->expectException(GoneException::class);
        $this->through('/old');
    }

    public function testAnUnknownPathPassesThroughUntouched(): void
    {
        $response = $this->through('/nothing-here');

        $this->assertSame('reached', (string)$response->getBody());
        $this->assertNull($this->reached[0]->getAttribute('seo.page'));
    }

    public function testATrailingSlashIsIgnoredWhenLookingUp(): void
    {
        $this->redirect('/old', $this->page('/new'));

        $this->assertSame('/new', $this->through('/old/')->getHeaderLine('Location'));
    }

    public function testHeadIsHandledButOtherMethodsAreNot(): void
    {
        $this->redirect('/old', $this->page('/new'));

        $this->assertSame(301, $this->through('/old', 'HEAD')->getStatusCode());
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertSame('reached', (string)$this->through('/old', $method)->getBody(), $method);
        }
    }

    public function testSkippedPrefixesAreNeverLookedUp(): void
    {
        $this->page('/admin/odd', 'gone');
        $middleware = new SeoRedirectsMiddleware(['/admin', '/health']);

        $this->assertSame('reached', (string)$this->through('/admin/odd', 'GET', $middleware)->getBody());
        $this->assertSame('reached', (string)$this->through('/admin', 'GET', $middleware)->getBody());
        $this->assertSame('reached', (string)$this->through('/health', 'GET', $middleware)->getBody());

        // The prefix is a path segment, not a string prefix.
        $this->page('/administer', 'gone');
        $this->expectException(GoneException::class);
        $this->through('/administer', 'GET', $middleware);
    }

    /**
     * A failing lookup (database down) must never take a page down: the request
     * goes through unchanged, so the health probe and every normal page keep working.
     */
    public function testALookupThatThrowsFailsOpen(): void
    {
        $middleware = new SeoRedirectsMiddleware();
        $middleware->setTableLocator(new class extends TableLocator {
            /**
             * @inheritDoc
             */
            public function get(string $alias, array $options = []): Table
            {
                throw new RuntimeException('database is down');
            }
        });

        $response = $this->through('/anything', 'GET', $middleware);

        $this->assertSame('reached', (string)$response->getBody());
    }

    public function testFromConfigReadsTheSkipList(): void
    {
        $original = Configure::read('Seo.redirects');
        Configure::write('Seo.redirects', ['skip' => ['/skipme']]);
        try {
            $this->page('/skipme', 'gone');

            $response = $this->through('/skipme', 'GET', SeoRedirectsMiddleware::fromConfig());

            $this->assertSame('reached', (string)$response->getBody());
        } finally {
            Configure::write('Seo.redirects', $original);
        }
    }
}
