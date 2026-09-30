<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

/**
 * One row of a StaticPageSubject: a string id and the page it stands for.
 */
final readonly class StaticPageRow
{
    /**
     * @param string $id Stable string id of the page within its subject.
     * @param \TheMusicDev\Seo\Subject\PageData $page The page.
     */
    public function __construct(public string $id, public PageData $page)
    {
    }
}
