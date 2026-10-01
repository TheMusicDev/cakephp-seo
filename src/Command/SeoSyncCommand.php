<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use InvalidArgumentException;
use TheMusicDev\Seo\Lib\PageIndexer;

/**
 * `bin/cake seo sync <subject> <id>` — re-index one row (design doc A6, D3): a changed
 * path records a redirect, a row that is no longer public goes `gone`. Queue it with
 * `Queue.Execute` after a publish or edit for near-live updates; the nightly
 * `seo rebuild` stays the source of truth. Exits non-zero on an unknown subject or a
 * path clash.
 */
final class SeoSyncCommand extends Command
{
    /**
     * Space-separated name; Cake would derive `seo_sync` from the class.
     */
    public static function defaultName(): string
    {
        return 'seo sync';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Re-index one row of a subject in the SEO page index.')
            ->addArgument('subject', [
                'help' => 'The Seo.subjects key (e.g. JobPostings).',
                'required' => true,
            ])
            ->addArgument('id', [
                'help' => 'The row id (the primary key, or the static page id).',
                'required' => true,
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $subject = (string)$args->getArgument('subject');
        $id = (string)$args->getArgument('id');

        try {
            $report = (new PageIndexer())->sync($subject, $id);
        } catch (InvalidArgumentException $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }

        $counts = $report['subjects'][$subject];
        $io->out(sprintf(
            '%s#%s: %d created, %d updated, %d unchanged, %d gone',
            $subject,
            $id,
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['gone'],
        ));
        if ($report['redirects'] > 0) {
            $io->out(sprintf('Redirects recorded: %d', $report['redirects']));
        }
        foreach ($report['conflicts'] as $conflict) {
            $io->err('Conflict: ' . $conflict);
        }

        return $report['conflicts'] === [] ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
