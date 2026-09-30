<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Subject;

/**
 * A source of public pages (design doc A4). Registered in the host's
 * `Seo.subjects` as `key => class`; the indexer builds it with `new $class($key)`,
 * so a subject's constructor, if it has one, must accept the config key.
 */
interface SubjectInterface
{
    /**
     * Every row that is public right now. Rows that stop appearing here are
     * marked gone by the rebuild.
     *
     * @return iterable<object>
     */
    public function rows(): iterable;

    /**
     * Stable id of a row within this subject (a primary key, or a fixed string
     * for static pages). Stored as a string.
     */
    public function idOf(object $row): string;

    /**
     * Turn one row into a page.
     */
    public function toPage(object $row): PageData;
}
