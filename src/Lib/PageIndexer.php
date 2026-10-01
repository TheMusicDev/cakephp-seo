<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Lib;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;
use InvalidArgumentException;
use TheMusicDev\Seo\Model\Entity\SeoRedirect;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
use TheMusicDev\Seo\Model\Table\SeoRedirectsTable;
use TheMusicDev\Seo\Subject\PageData;
use TheMusicDev\Seo\Subject\SubjectInterface;

/**
 * Rebuilds the `seo_pages` index from the configured subjects (design doc
 * A6). The rebuild is the source of truth: it creates missing pages, updates
 * changed ones, revives republished ones and marks pages that are no longer
 * public as `gone`. Running it twice in a row changes nothing.
 *
 * It streams: each subject row is turned into a page and written (or skipped)
 * before the next is read, and "changed?" is a checksum comparison against a
 * small in-memory index of what is stored (id, path, status, checksum), so
 * memory does not grow with the number of pages or the size of their JSON-LD.
 *
 * Two pages can never share a path. When two pages want the same path the
 * first claim wins and the other is reported as a conflict (nothing is saved
 * for it). A stored page that is leaving is deleted when a live page takes its
 * path. A page that wants a path still held by a stored page that has not been
 * processed yet is deferred to the end of the run, when it is known whether
 * that page moved, left or stayed.
 *
 * When a stored page's path changes, the old path is recorded as a 301 redirect to
 * the page (design doc A8); a subject may also declare old paths (`redirectsFrom`,
 * A14). Redirects point at the page row, so they never chain, and an old path that
 * becomes a live page's own path is dropped — the page wins.
 */
final class PageIndexer
{
    use LocatorAwareTrait;

    private const SEPARATOR = "\x1f";

    /**
     * What is stored, keyed by composite (subject, id).
     *
     * @var array<string, array{id: int, subject: string, path: string, status: string, checksum: string|null}>
     */
    private array $existing = [];

    /**
     * Path => composite of the stored (or just written) page holding it.
     *
     * @var array<string, string>
     */
    private array $pathOwner = [];

    /**
     * @var array<string, array{created: int, updated: int, unchanged: int, gone: int}>
     */
    private array $counts = [];

    /**
     * Problems found during the run (path clashes, dropped declared redirects).
     *
     * @var list<string>
     */
    private array $conflicts = [];

    /**
     * Redirects recorded in this run.
     */
    private int $redirects = 0;

    /**
     * @param array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>>|null $subjects Defaults to Configure `Seo.subjects`.
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>, redirects: int}
     */
    public function rebuild(?array $subjects = null): array
    {
        /** @var array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>> $subjects */
        $subjects ??= (array)Configure::read('Seo.subjects', []);

        $pages = $this->pages();
        $this->counts = [];
        $this->existing = [];
        $this->pathOwner = [];
        $this->conflicts = [];
        $this->redirects = 0;
        $stored = $pages->find()
            ->select(['id', 'subject', 'subject_id', 'path', 'status', 'checksum'])
            ->disableHydration();
        foreach ($stored as $row) {
            $composite = $this->composite((string)$row['subject'], (string)$row['subject_id']);
            $this->existing[$composite] = [
                'id' => (int)$row['id'],
                'subject' => (string)$row['subject'],
                'path' => (string)$row['path'],
                'status' => (string)$row['status'],
                'checksum' => $row['checksum'] === null ? null : (string)$row['checksum'],
            ];
            $this->pathOwner[(string)$row['path']] = $composite;
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, string> $claimed path => composite that claimed it in this run */
        $claimed = [];
        /** @var array<string, array{0: string, 1: string, 2: \TheMusicDev\Seo\Subject\PageData}> $deferred */
        $deferred = [];

        // Stream every subject: one row in, one write (or nothing) out.
        foreach ($subjects as $key => $class) {
            $this->counts[$key] = $this->emptyCounts();
            $subject = $this->subject($key, $class);
            foreach ($subject->rows() as $row) {
                $id = $subject->idOf($row);
                $page = $subject->toPage($row);
                $composite = $this->composite($key, $id);

                if (isset($claimed[$page->path])) {
                    $this->conflicts[] = sprintf(
                        '%s wants %s, already claimed by %s',
                        $this->label($composite),
                        $page->path,
                        $this->label($claimed[$page->path]),
                    );
                    continue;
                }
                $claimed[$page->path] = $composite;
                $seen[$composite] = true;

                $owner = $this->pathOwner[$page->path] ?? null;
                if ($owner !== null && $owner !== $composite) {
                    $deferred[$composite] = [$key, $id, $page];
                    continue;
                }
                $this->store($key, $id, $page);
            }
        }

        // Stored pages that no longer appear: superseded (a live page took the
        // path) or gone.
        foreach ($this->existing as $composite => $stored) {
            if (isset($seen[$composite])) {
                continue;
            }
            $claimant = $claimed[$stored['path']] ?? null;
            if ($claimant !== null && $claimant !== $composite) {
                // Its redirects go with it (ON DELETE CASCADE on seo_redirects.seo_page_id).
                $pages->deleteAll(['id' => $stored['id']]);
                unset($this->existing[$composite], $this->pathOwner[$stored['path']]);
                $this->bump($stored['subject'], 'gone');
                continue;
            }
            if ($stored['status'] === SeoPagesTable::STATUS_LIVE) {
                $pages->updateAll(
                    ['status' => SeoPagesTable::STATUS_GONE, 'modified' => DateTime::now()],
                    ['id' => $stored['id']],
                );
                $this->existing[$composite]['status'] = SeoPagesTable::STATUS_GONE;
                $this->bump($stored['subject'], 'gone');
            }
        }

        // Deferred pages: their path is free now unless its holder stayed put.
        foreach ($deferred as $composite => [$key, $id, $page]) {
            $owner = $this->pathOwner[$page->path] ?? null;
            if ($owner !== null && $owner !== $composite) {
                $this->conflicts[] = sprintf(
                    '%s wants %s, held by %s',
                    $this->label($composite),
                    $page->path,
                    $this->label($owner),
                );
                continue;
            }
            $this->store($key, $id, $page);
        }

        $this->dropRedirectsThatAreNowPages();

        return ['subjects' => $this->counts, 'conflicts' => $this->conflicts, 'redirects' => $this->redirects];
    }

    /**
     * Write one page unless the stored copy already matches it.
     */
    private function store(string $subject, string $id, PageData $page): void
    {
        $composite = $this->composite($subject, $id);
        $checksum = $this->checksum($page);
        $stored = $this->existing[$composite] ?? null;

        if (
            $stored !== null
            && $stored['status'] === SeoPagesTable::STATUS_LIVE
            && $stored['checksum'] === $checksum
        ) {
            $this->bump($subject, 'unchanged');

            return;
        }

        $pages = $this->pages();
        $entity = $stored === null ? $pages->newEmptyEntity() : $pages->get($stored['id']);
        $pages->patchEntity($entity, [
            'subject' => $subject,
            'subject_id' => $id,
            'path' => $page->path,
            'title' => $page->title,
            'description' => $page->description,
            'robots' => $page->robots,
            'og_type' => $page->ogType,
            'og_image' => $page->ogImage,
            'lastmod' => $page->lastmod === null ? null : $this->wholeSeconds($page->lastmod),
            'schema' => $page->schema,
            'checksum' => $checksum,
            'status' => SeoPagesTable::STATUS_LIVE,
        ], ['validate' => false]);
        // Path uniqueness is tracked in memory (pathOwner); the unique index is the backstop.
        $pages->saveOrFail($entity, ['checkRules' => false]);

        if ($stored !== null && ($this->pathOwner[$stored['path']] ?? null) === $composite) {
            unset($this->pathOwner[$stored['path']]);
        }
        $this->pathOwner[$page->path] = $composite;
        if ($stored !== null && $stored['path'] !== $page->path) {
            $this->recordMove($stored['path'], (int)$entity->get('id'));
        }
        $this->syncDeclared((int)$entity->get('id'), $composite, $page);
        $this->existing[$composite] = [
            'id' => (int)$entity->get('id'),
            'subject' => $subject,
            'path' => $page->path,
            'status' => SeoPagesTable::STATUS_LIVE,
            'checksum' => $checksum,
        ];
        $this->bump($subject, $stored === null ? 'created' : 'updated');
    }

    /**
     * Fingerprint of everything a page writes, so unchanged pages are skipped.
     */
    private function checksum(PageData $page): string
    {
        return sha1((string)json_encode([
            $page->path,
            $page->title,
            $page->description,
            $page->robots,
            $page->lastmod === null ? null : $this->wholeSeconds($page->lastmod)->format('Y-m-d H:i:s'),
            $page->schema,
            $page->ogType,
            $page->ogImage,
            $this->cleanPaths($page->redirectsFrom),
        ]));
    }

    /**
     * DATETIME columns keep whole seconds; without this a fresh `now()` never
     * equals the stored value and every rebuild would report an update.
     */
    private function wholeSeconds(DateTimeInterface $date): DateTime
    {
        return DateTime::createFromInterface($date)->setTime(
            (int)$date->format('H'),
            (int)$date->format('i'),
            (int)$date->format('s'),
            0,
        );
    }

    /**
     * Record that `$from` used to be this page's path: it now 301s to the page.
     */
    private function recordMove(string $from, int $pageId): void
    {
        $redirect = $this->redirectFor($from);
        if (!$redirect->isNew() && (int)$redirect->seo_page_id === $pageId) {
            return;
        }
        $redirect->from_path = $from;
        $redirect->seo_page_id = $pageId;
        $redirect->source = SeoRedirectsTable::SOURCE_MOVED;
        $this->redirectsTable()->saveOrFail($redirect);
        $this->redirects++;
    }

    /**
     * The redirect for an old path: the stored one, or a new empty one.
     */
    private function redirectFor(string $from): SeoRedirect
    {
        /** @var \TheMusicDev\Seo\Model\Entity\SeoRedirect|null $redirect */
        $redirect = $this->redirectsTable()->find()->where(['from_path' => $from])->first();

        /** @var \TheMusicDev\Seo\Model\Entity\SeoRedirect */
        return $redirect ?? $this->redirectsTable()->newEmptyEntity();
    }

    /**
     * Make the page's `declared` redirects match its `redirectsFrom` list: add new
     * ones, remove ones no longer listed. A listed path that is another page's own
     * path is reported and skipped — the live page wins.
     */
    private function syncDeclared(int $pageId, string $composite, PageData $page): void
    {
        $declared = $this->cleanPaths($page->redirectsFrom);
        $redirects = $this->redirectsTable();

        $current = $redirects->find()
            ->select(['id', 'from_path'])
            ->where(['seo_page_id' => $pageId, 'source' => SeoRedirectsTable::SOURCE_DECLARED])
            ->disableHydration();
        foreach ($current as $row) {
            if (!in_array($row['from_path'], $declared, true)) {
                $redirects->deleteAll(['id' => $row['id']]);
            }
        }

        foreach ($declared as $from) {
            if ($from === $page->path) {
                continue;
            }
            $owner = $this->pathOwner[$from] ?? null;
            if ($owner !== null && $owner !== $composite) {
                $this->conflicts[] = sprintf(
                    '%s declares the old URL %s, but that is the path of %s',
                    $this->label($composite),
                    $from,
                    $this->label($owner),
                );
                continue;
            }
            $redirect = $this->redirectFor($from);
            if (!$redirect->isNew() && (int)$redirect->seo_page_id === $pageId) {
                continue;
            }
            $redirect->from_path = $from;
            $redirect->seo_page_id = $pageId;
            $redirect->source = SeoRedirectsTable::SOURCE_DECLARED;
            $redirects->saveOrFail($redirect);
            $this->redirects++;
        }
    }

    /**
     * After the run: a redirect whose old path is now a page's own path (a path
     * that came back into use, or was taken by another page) is dropped — the
     * page wins. A dropped `declared` one is reported; a `moved` one is silent.
     */
    private function dropRedirectsThatAreNowPages(): void
    {
        $redirects = $this->redirectsTable();
        $clashing = $redirects->find()
            ->select(['SeoRedirects.id', 'SeoRedirects.from_path', 'SeoRedirects.source'])
            ->join(['p' => [
                'table' => 'seo_pages',
                'type' => 'INNER',
                'conditions' => 'p.path = SeoRedirects.from_path',
            ]])
            ->disableHydration()
            ->all();
        foreach ($clashing as $row) {
            if ($row['source'] === SeoRedirectsTable::SOURCE_DECLARED) {
                $this->conflicts[] = sprintf('Declared redirect from %s dropped: it is now a page', $row['from_path']);
            }
            $redirects->deleteAll(['id' => $row['id']]);
        }
    }

    /**
     * Declared old paths, normalized: one leading slash, none trailing, no root,
     * no duplicates.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function cleanPaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $path = '/' . trim($path, '/');
            if ($path !== '/') {
                $clean[$path] = $path;
            }
        }

        return array_values($clean);
    }

    /**
     * The redirects table.
     */
    private function redirectsTable(): SeoRedirectsTable
    {
        /** @var \TheMusicDev\Seo\Model\Table\SeoRedirectsTable */
        return $this->fetchTable('TheMusicDev/Seo.SeoRedirects');
    }

    /**
     * The page index table.
     */
    private function pages(): SeoPagesTable
    {
        /** @var \TheMusicDev\Seo\Model\Table\SeoPagesTable */
        return $this->fetchTable('TheMusicDev/Seo.SeoPages');
    }

    /**
     * @param class-string<\TheMusicDev\Seo\Subject\SubjectInterface> $class
     */
    private function subject(string $key, string $class): SubjectInterface
    {
        if (!is_a($class, SubjectInterface::class, true)) {
            throw new InvalidArgumentException(
                "Seo.subjects['{$key}'] must be a SubjectInterface class, got '{$class}'.",
            );
        }

        return new $class($key);
    }

    /**
     * The (subject, id) identity as one map key.
     */
    private function composite(string $subject, string $id): string
    {
        return $subject . self::SEPARATOR . $id;
    }

    /**
     * Readable `subject#id` for messages.
     */
    private function label(string $composite): string
    {
        return str_replace(self::SEPARATOR, '#', $composite);
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, gone: int}
     */
    private function emptyCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'gone' => 0];
    }

    /**
     * @param 'created'|'updated'|'unchanged'|'gone' $counter
     */
    private function bump(string $subject, string $counter): void
    {
        $this->counts[$subject] ??= $this->emptyCounts();
        $this->counts[$subject][$counter]++;
    }
}
