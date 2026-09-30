<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\Subject;

use TheMusicDev\Seo\Subject\StaticPageSubject;

/**
 * Test static subject: the page list is a static array a test can change
 * between rebuilds.
 */
final class FixedPagesSubject extends StaticPageSubject
{
    /**
     * @var array<string, \TheMusicDev\Seo\Subject\PageData>
     */
    public static array $list = [];

    /**
     * @inheritDoc
     */
    protected function pages(): array
    {
        return self::$list;
    }
}
