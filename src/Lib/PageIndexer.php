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
 * Two pages can never share a path. When two subjects want the same path the
 * first one wins and the other is reported as a conflict (nothing is saved
 * for it). A `gone` page whose path a live page now wants is deleted — the
 * path is being reused.
 */
final class PageIndexer
{
    use LocatorAwareTrait;

    private const SEPARATOR = "\x1f";

    /**
     * @param array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>>|null $subjects Defaults to Configure `Seo.subjects`.
     * @return array{subjects: array<string, array{created: int, updated: int, unchanged: int, gone: int}>, conflicts: list<string>}
     */
    public function rebuild(?array $subjects = null): array
    {
        /** @var array<string, class-string<\TheMusicDev\Seo\Subject\SubjectInterface>> $subjects */
        $subjects ??= (array)Configure::read('Seo.subjects', []);

        /** @var array<string, array{created: int, updated: int, unchanged: int, gone: int}> $counts */
        $counts = [];
        /** @var list<string> $conflicts */
        $conflicts = [];

        // Phase 1: ask every subject for its pages; first claim on a path wins.
        /** @var array<string, array{subject: string, id: string, page: \TheMusicDev\Seo\Subject\PageData}> $desired */
        $desired = [];
        /** @var array<string, string> $claimed path => composite key */
        $claimed = [];
        foreach ($subjects as $key => $class) {
            $counts[$key] = $this->emptyCounts();
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
                $desired[$composite] = ['subject' => $key, 'id' => $id, 'page' => $page];
            }
        }

        $pages = $this->fetchTable('TheMusicDev/Seo.SeoPages');
        /** @var array<string, \TheMusicDev\Seo\Model\Entity\SeoPage> $existing */
        $existing = [];
        /** @var \TheMusicDev\Seo\Model\Entity\SeoPage $entity */
        foreach ($pages->find()->all() as $entity) {
            $existing[$this->composite((string)$entity->subject, (string)$entity->subject_id)] = $entity;
        }

        // Phase 2a: rows that no longer appear go away or turn gone.
        foreach ($existing as $composite => $entity) {
            if (isset($desired[$composite])) {
                continue;
            }
            $claimant = $claimed[$entity->path] ?? null;
            if ($claimant !== null && $claimant !== $composite) {
                // A live page now wants this path — the old row is superseded.
                $pages->delete($entity);
                unset($existing[$composite]);
                $this->bump($counts, (string)$entity->subject, 'gone');
                continue;
            }
            if ($entity->status === SeoPagesTable::STATUS_LIVE) {
                $entity->status = SeoPagesTable::STATUS_GONE;
                $pages->saveOrFail($entity);
                $this->bump($counts, (string)$entity->subject, 'gone');
            }
        }

        // Phase 2b: upsert the desired pages.
        foreach ($desired as $composite => $item) {
            $entity = $existing[$composite] ?? $pages->newEmptyEntity();
            $isNew = $entity->isNew();
            $pages->patchEntity($entity, $this->columns($item['subject'], $item['id'], $item['page']), [
                'validate' => false,
            ]);
            if (!$isNew && !$entity->isDirty()) {
                $this->bump($counts, $item['subject'], 'unchanged');
                continue;
            }
            if ($pages->save($entity) === false) {
                $conflicts[] = sprintf(
                    '%s could not be saved: %s',
                    $this->label($composite),
                    json_encode($entity->getErrors()),
                );
                continue;
            }
            $this->bump($counts, $item['subject'], $isNew ? 'created' : 'updated');
        }

        return ['subjects' => $counts, 'conflicts' => $conflicts];
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(string $subject, string $id, PageData $page): array
    {
        return [
            'subject' => $subject,
            'subject_id' => $id,
            'path' => $page->path,
            'title' => $page->title,
            'description' => $page->description,
            'robots' => $page->robots,
            'lastmod' => $page->lastmod === null ? null : $this->wholeSeconds($page->lastmod),
            'schema' => $page->schema,
            'status' => SeoPagesTable::STATUS_LIVE,
        ];
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
     * @param array<string, array{created: int, updated: int, unchanged: int, gone: int}> $counts
     * @param string $subject
     * @param 'created'|'updated'|'unchanged'|'gone' $counter
     */
    private function bump(array &$counts, string $subject, string $counter): void
    {
        $counts[$subject] ??= $this->emptyCounts();
        $counts[$subject][$counter]++;
    }
}
