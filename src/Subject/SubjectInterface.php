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
     * Every row that is public right now. A row can be anything (an entity, an
     * id…): the indexer never looks inside one, it only hands it back to
     * `idOf()` and `toPage()`. Rows that stop appearing here are marked gone by
     * the rebuild.
     *
     * @return iterable<mixed>
     */
    public function rows(): iterable;

    /**
     * One row by id, or null when it is not public right now (missing, unpublished,
     * trashed…). The same public scope as `rows()`: it is what `bin/cake seo sync`
     * asks to re-index a single page.
     *
     * @param string $id An id as returned by `idOf()`.
     */
    public function row(string $id): mixed;

    /**
     * Stable id of a row within this subject (a primary key, or a fixed string
     * for static pages). Stored as a string.
     *
     * @param mixed $row A row from `rows()`.
     */
    public function idOf(mixed $row): string;

    /**
     * Turn one row into a page.
     *
     * @param mixed $row A row from `rows()`.
     */
    public function toPage(mixed $row): PageData;
}
