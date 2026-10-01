<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Middleware;

use Cake\Core\Configure;
use Cake\Http\Exception\GoneException;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TheMusicDev\Seo\Model\Entity\SeoPage;
use TheMusicDev\Seo\Model\Entity\SeoRedirect;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use Throwable;

/**
 * Answers old and removed URLs before routing (design doc A15, G5):
 *
 * - a path that is a **gone** page → 410 Gone (instead of the controller's 404);
 * - a path that is an old URL (`seo_redirects`) → **301** to its page's current
 *   path, query string kept, or 410 when that page is gone;
 * - a path that is a **live** page passes through, with the row attached to the
 *   request as `seo.page` so the head helper does not look it up again;
 * - anything else passes through untouched.
 *
 * GET and HEAD only. Paths under a `skip` prefix (admin, health probe…) are never
 * looked up. If the lookup itself fails (database down) the request passes through
 * unchanged and the failure is logged — a missing redirect must never take a page
 * down, and the health probe stays database-free.
 */
final class SeoRedirectsMiddleware implements MiddlewareInterface
{
    use LocatorAwareTrait;

    /**
     * @param list<string> $skip Path prefixes to leave alone (`/admin` also covers `/admin/jobs`).
     */
    public function __construct(private array $skip = [])
    {
    }

    /**
     * Build from the `Seo.redirects.skip` Configure value.
     */
    public static function fromConfig(): self
    {
        /** @var list<string> $skip */
        $skip = array_values((array)Configure::read('Seo.redirects.skip', []));

        return new self($skip);
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($this->skipped($path)) {
            return $handler->handle($request);
        }

        try {
            $outcome = $this->lookup($path);
        } catch (Throwable $e) {
            Log::warning('Seo redirect lookup failed, passing the request through: ' . $e->getMessage());

            return $handler->handle($request);
        }

        if ($outcome instanceof SeoPage) {
            return $handler->handle($request->withAttribute('seo.page', $outcome));
        }
        if ($outcome === 'gone') {
            throw new GoneException();
        }
        if (is_string($outcome)) {
            $query = $request->getUri()->getQuery();

            return (new Response())
                ->withStatus(301)
                ->withHeader('Location', $outcome . ($query === '' ? '' : '?' . $query));
        }

        return $handler->handle($request);
    }

    /**
     * What this path is: a live page, `'gone'`, the path to redirect to, or null.
     */
    private function lookup(string $path): SeoPage|string|null
    {
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = $this->fetchTable('TheMusicDev/Seo.SeoPages');
        /** @var \TheMusicDev\Seo\Model\Entity\SeoPage|null $page */
        $page = $pages->find()->where(['path' => $path])->first();
        if ($page !== null) {
            return $page->status === SeoPagesTable::STATUS_GONE ? 'gone' : $page;
        }

        /** @var \TheMusicDev\Seo\Model\Table\SeoRedirectsTable $redirects */
        $redirects = $this->fetchTable('TheMusicDev/Seo.SeoRedirects');
        /** @var \TheMusicDev\Seo\Model\Entity\SeoRedirect|null $redirect */
        $redirect = $redirects->find()->contain(['SeoPages'])->where(['from_path' => $path])->first();
        if ($redirect === null || $redirect->seo_page === null) {
            return null;
        }

        return $this->target($redirect);
    }

    /**
     * The redirect's destination, or 'gone' when its page is.
     */
    private function target(SeoRedirect $redirect): string
    {
        $page = $redirect->seo_page;

        return $page === null || $page->status === SeoPagesTable::STATUS_GONE ? 'gone' : $page->path;
    }

    /**
     * Whether a path is under one of the skip prefixes.
     */
    private function skipped(string $path): bool
    {
        foreach ($this->skip as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
