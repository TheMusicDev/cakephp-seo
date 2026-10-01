<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\View\Helper;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Routing\Asset;
use Cake\Routing\Router;
use Cake\View\Helper;
use TheMusicDev\Seo\Model\Entity\SeoPage;

/**
 * Prints a page's SEO head tags: `<title>`, description, canonical, robots,
 * Open Graph and Twitter cards (design doc A17, S1/S2). Use it once in the
 * layout: `<?= $this->Seo->head() ?>`.
 *
 * The page row is found by the request path in `seo_pages` (stored paths are the
 * canonical paths), or by an explicit `$this->set('seoPage', [$subject, $id])`.
 * Each value is resolved in this order, first one wins:
 *
 * 1. the `seoOverride` view variable — for pages that are not rows, e.g. page 2
 *    of a list or a filtered list: `title`, `description`, `canonical`, `robots`,
 *    `ogType`, `ogImage`;
 * 2. the page row;
 * 3. the `metaTitle` / `metaDescription` view variables, for pages with no row
 *    (error pages, apply pages);
 * 4. the site defaults in `Seo.site` (name, default image, twitter card…).
 *
 * @extends \Cake\View\Helper<\Cake\View\View>
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class SeoHelper extends Helper
{
    use LocatorAwareTrait;

    /**
     * @var array<int|string, string|array<string, mixed>>
     */
    protected array $helpers = ['Html'];

    private ?SeoPage $page = null;

    private bool $looked = false;

    /**
     * All the head tags for the current page, one per line.
     */
    public function head(): string
    {
        $override = (array)$this->_View->get('seoOverride', []);
        $page = $this->page();
        $site = (array)Configure::read('Seo.site', []);

        $title = (string)(
            $override['title'] ?? $page->title ?? $this->_View->get('metaTitle') ?? ($site['name'] ?? '')
        );
        $fullTitle = $this->fullTitle($title, $site);
        $description = (string)(
            $override['description'] ?? $page->description ?? $this->_View->get('metaDescription') ?? ''
        );
        $canonical = $this->absolute((string)($override['canonical'] ?? $page->path ?? $this->requestPath()));
        $robots = $override['robots'] ?? $page?->robots;
        $ogType = (string)($override['ogType'] ?? $page->og_type ?? 'website');

        $ownImage = $override['ogImage'] ?? $page?->og_image;
        $image = $ownImage ?? ($site['image'] ?? null);
        $imageUrl = $image === null || $image === '' ? null : Asset::url((string)$image, ['fullBase' => true]);

        $html = $this->Html;
        $tags = [
            $html->tag('title', h($fullTitle)),
            $html->meta(['name' => 'description', 'content' => $description]),
            $html->meta(['link' => $canonical, 'rel' => 'canonical']),
        ];
        if ($robots !== null && $robots !== '') {
            $tags[] = $html->meta(['name' => 'robots', 'content' => (string)$robots]);
        }
        $tags[] = $html->meta(['property' => 'og:type', 'content' => $ogType]);
        $tags[] = $html->meta(['property' => 'og:title', 'content' => $fullTitle]);
        $tags[] = $html->meta(['property' => 'og:description', 'content' => $description]);
        $tags[] = $html->meta(['property' => 'og:url', 'content' => $canonical]);
        if ($imageUrl !== null) {
            $tags[] = $html->meta(['property' => 'og:image', 'content' => $imageUrl]);
            // Dimensions describe the site default image only, not a per-page one.
            if ($ownImage === null) {
                $dimensions = [
                    'imageWidth' => 'og:image:width',
                    'imageHeight' => 'og:image:height',
                    'imageType' => 'og:image:type',
                ];
                foreach ($dimensions as $key => $property) {
                    if (!empty($site[$key])) {
                        $tags[] = $html->meta(['property' => $property, 'content' => (string)$site[$key]]);
                    }
                }
            }
        }
        $card = (string)($site['twitterCard'] ?? 'summary_large_image');
        $tags[] = $html->meta(['name' => 'twitter:card', 'content' => $card]);
        $tags[] = $html->meta(['name' => 'twitter:title', 'content' => $fullTitle]);
        $tags[] = $html->meta(['name' => 'twitter:description', 'content' => $description]);
        if ($imageUrl !== null) {
            $tags[] = $html->meta(['name' => 'twitter:image', 'content' => $imageUrl]);
        }

        return implode("\n    ", $tags);
    }

    /**
     * The live page row for this request, or null (looked up once).
     */
    private function page(): ?SeoPage
    {
        if ($this->looked) {
            return $this->page;
        }
        $this->looked = true;

        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable $pages */
        $pages = $this->fetchTable('TheMusicDev/Seo.SeoPages');
        $query = $pages->find('live');
        $explicit = $this->_View->get('seoPage');
        if (is_array($explicit) && count($explicit) === 2) {
            $query->where(['subject' => (string)$explicit[0], 'subject_id' => (string)$explicit[1]]);
        } else {
            $query->where(['path' => $this->requestPath()]);
        }
        /** @var \TheMusicDev\Seo\Model\Entity\SeoPage|null $page */
        $page = $query->first();

        return $this->page = $page;
    }

    /**
     * The request path without a trailing slash (`/` stays `/`).
     */
    private function requestPath(): string
    {
        $path = $this->_View->getRequest()->getPath();

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * An absolute URL for a stored path, or the URL unchanged when it already is one.
     */
    private function absolute(string $pathOrUrl): string
    {
        return preg_match('#^https?://#i', $pathOrUrl) === 1 ? $pathOrUrl : Router::url($pathOrUrl, true);
    }

    /**
     * The title with the site name appended, unless it is the site name itself.
     *
     * @param array<array-key, mixed> $site The `Seo.site` config.
     */
    private function fullTitle(string $title, array $site): string
    {
        $name = (string)($site['name'] ?? '');
        if ($name === '' || $title === $name) {
            return $title;
        }

        return $title . (string)($site['titleSeparator'] ?? ' — ') . $name;
    }
}
