<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\View\Helper;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Routing\Asset;
use Cake\Routing\Router;
use Cake\View\Helper;
use InvalidArgumentException;
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
 * It also prints the page's structured data: one `<script type="application/ld+json">`
 * holding a single `@graph` of the site-wide nodes (`Seo.site.schema`: Organization,
 * WebSite…) followed by the page row's own nodes (`seo_pages.schema`). `url` and
 * `item` values given as paths are made absolute here, like the canonical.
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

    /** The attribute that names a meta tag; an entry of `Seo.site.meta` has exactly one of them, plus `content`. */
    private const META_KEYS = ['name', 'property', 'http-equiv', 'itemprop'];

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

        array_push($tags, ...$this->siteMeta((array)($site['meta'] ?? [])));

        $nodes = $this->nodes((array)($site['schema'] ?? []), $page);
        if ($nodes !== []) {
            $tags[] = $this->jsonLd($nodes);
        }

        return implode("\n    ", $tags);
    }

    /**
     * The `Seo.site.meta` tags: one `<meta>` per entry, in config order.
     *
     * An entry is code in the host's config, so a malformed one throws (a typo is caught in development). A null or
     * blank `content` is not a mistake: it is how an unset environment variable shows up, and that tag is skipped.
     *
     * @param array<array-key, mixed> $entries Each `['name'|'property'|'http-equiv'|'itemprop' => …, 'content' => …]`.
     * @return list<string>
     * @throws \InvalidArgumentException On a malformed entry.
     */
    private function siteMeta(array $entries): array
    {
        $tags = [];
        foreach (array_values($entries) as $index => $entry) {
            $where = "Seo.site.meta[{$index}]";
            if (!is_array($entry)) {
                throw new InvalidArgumentException("{$where} must be an array.");
            }
            $content = $entry['content'] ?? null;
            unset($entry['content']);
            $key = array_key_first($entry);
            if (count($entry) !== 1 || !in_array($key, self::META_KEYS, true)) {
                throw new InvalidArgumentException(
                    "{$where} needs exactly one of " . implode(', ', self::META_KEYS) . ', plus content.',
                );
            }
            $name = $entry[$key];
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/', $name) !== 1) {
                throw new InvalidArgumentException(
                    "{$where}.{$key} must be a meta tag name such as 'google-site-verification' "
                    . '(a letter, then letters, digits and . _ : -).',
                );
            }
            if ($content !== null && !is_string($content) && !is_int($content) && !is_float($content)) {
                throw new InvalidArgumentException("{$where}.content must be a string, number or null.");
            }
            $value = trim((string)$content);
            if ($value !== '') {
                $tags[] = (string)$this->Html->meta([$key => $name, 'content' => $value]);
            }
        }

        return $tags;
    }

    /**
     * The graph's nodes: site-wide first, then the page's own, URLs made absolute.
     *
     * @param array<array-key, mixed> $siteNodes The `Seo.site.schema` config.
     * @return list<array<string, mixed>>
     */
    private function nodes(array $siteNodes, ?SeoPage $page): array
    {
        $pageNodes = $page?->schema;
        $nodes = [];
        foreach (array_merge($siteNodes, is_array($pageNodes) ? $pageNodes : []) as $node) {
            if (is_array($node) && $node !== []) {
                /** @var array<string, mixed> $node */
                $nodes[] = $this->absolutizeUrls($node);
            }
        }

        return $nodes;
    }

    /**
     * Turn path values under `url` / `item` into absolute URLs, at any depth.
     *
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private function absolutizeUrls(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->absolutizeUrls($value);
            } elseif (in_array($key, ['url', 'item'], true) && is_string($value) && str_starts_with($value, '/')) {
                $node[$key] = Router::url($value, true);
            }
        }

        return $node;
    }

    /**
     * The `<script>` block. The HEX flags turn `<`, `>`, `&`, `'` and `"` inside
     * values into \uXXXX escapes, so no value can contain `</script>` and break out
     * of the block (the bug dereuromark/cakephp-meta patched in 1.2.0).
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function jsonLd(array $nodes): string
    {
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        return '<script type="application/ld+json">' . $json . '</script>';
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

        // The redirects middleware already found this request's live row.
        $attached = $this->_View->getRequest()->getAttribute('seo.page');
        if ($attached instanceof SeoPage && !is_array($this->_View->get('seoPage'))) {
            return $this->page = $attached;
        }

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
