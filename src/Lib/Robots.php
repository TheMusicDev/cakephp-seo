<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Lib;

/**
 * Renders `robots.txt` from the `Seo.robots` config (design doc A12, S6).
 *
 * - **Only listed hosts may be crawled.** When `allowHosts` is set and the request
 *   host is not in it (staging, a preview, localhost), the answer is a blanket
 *   `Disallow: /` (with a comment saying why) and no sitemap line. When `allowHosts` is empty there is no
 *   restriction — set it on every real deployment.
 * - **Rules are groups** (`userAgent`, `allow`, `disallow`). A crawler obeys only
 *   its single most specific group, so a path to block must be listed in every
 *   group that crawler could match.
 * - **A `Sitemap:` line** is added when a sitemap URL is given.
 *
 * `Disallow` only stops crawling; it does not remove a page from search results
 * (that takes a `noindex`). Values are stripped of line breaks, so config can
 * never inject extra directives.
 */
final class Robots
{
    /**
     * The answer for a host that may not be crawled. The comment (crawlers ignore it) tells
     * a developer who curls this why there are no rules and no sitemap line.
     */
    private const DISALLOW_ALL = "# Crawling is disabled on this host: it is not in Seo.robots.allowHosts.\n"
        . "User-agent: *\nDisallow: /\n";

    /**
     * @param array<array-key, mixed> $config The `Seo.robots` config (`allowHosts`, `rules`).
     * @param string $host The request host, without a port.
     * @param string|null $sitemapUrl Absolute URL of the sitemap index, or null for none.
     */
    public static function render(array $config, string $host, ?string $sitemapUrl): string
    {
        $allowHosts = array_map('strtolower', array_map('strval', (array)($config['allowHosts'] ?? [])));
        if ($allowHosts !== [] && !in_array(strtolower($host), $allowHosts, true)) {
            return self::DISALLOW_ALL;
        }

        $groups = [];
        foreach ((array)($config['rules'] ?? []) as $rule) {
            $groups[] = self::group((array)$rule);
        }
        $groups = array_values(array_filter($groups));
        if ($groups === []) {
            $groups = ["User-agent: *\nAllow: /\n"];
        }

        $text = implode("\n", $groups);
        $sitemapUrl = $sitemapUrl === null ? '' : self::line($sitemapUrl);

        return $text . ($sitemapUrl === '' ? '' : "\nSitemap: " . $sitemapUrl . "\n");
    }

    /**
     * One `User-agent` group, or '' when it has no user agent.
     *
     * @param array<array-key, mixed> $rule
     */
    private static function group(array $rule): string
    {
        $agent = self::line((string)($rule['userAgent'] ?? ''));
        if ($agent === '') {
            return '';
        }

        $lines = ['User-agent: ' . $agent];
        foreach (['allow' => 'Allow', 'disallow' => 'Disallow'] as $key => $directive) {
            foreach ((array)($rule[$key] ?? []) as $path) {
                $path = self::line((string)$path);
                if ($path !== '') {
                    $lines[] = $directive . ': ' . $path;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A single-line, trimmed value (no CR/LF, so it cannot start another directive).
     */
    private static function line(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
