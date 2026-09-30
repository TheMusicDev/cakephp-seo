<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Lib;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;
use InvalidArgumentException;
use TheMusicDev\Seo\Model\Table\SeoPagesTable;
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
     * @param array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>>|null $subjects Defaults to Configure `Seo.subjects`.
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>}
     */
    public function rebuild(?array $subjects = null): array
    {
        /** @var array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>> $subjects */
        $subjects ??= (array)Configure::read('Seo.subjects', []);

        $pages = $this->pages();
        $this->counts = [];
        $this->existing = [];
        $this->pathOwner = [];
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

        /** @var list<string> $conflicts */
        $conflicts = [];
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
                    $conflicts[] = sprintf(
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
                $conflicts[] = sprintf(
                    '%s wants %s, held by %s',
                    $this->label($composite),
                    $page->path,
                    $this->label($owner),
                );
                continue;
            }
            $this->store($key, $id, $page);
        }

        return ['subjects' => $this->counts, 'conflicts' => $conflicts];
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
