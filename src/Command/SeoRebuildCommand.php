<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use TheMusicDev\Seo\Lib\PageIndexer;

/**
 * `bin/cake seo rebuild` — rebuild the page index from `Seo.subjects`. Run on
 * deploy and nightly. Exits non-zero when two pages claim the same path.
 */
final class SeoRebuildCommand extends Command
{
    /**
     * Space-separated name; Cake would derive `seo_rebuild` from the class.
     */
    public static function defaultName(): string
    {
        return 'seo rebuild';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Rebuild the SEO page index from the configured subjects.');
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $report = (new PageIndexer())->rebuild();

        if ($report['subjects'] === []) {
            $io->warning('No subjects configured (Seo.subjects).');
        }
        foreach ($report['subjects'] as $subject => $counts) {
            $io->out(sprintf(
                '%s: %d created, %d updated, %d unchanged, %d gone',
                $subject,
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['gone'],
            ));
        }
        foreach ($report['conflicts'] as $conflict) {
            $io->err('Conflict: ' . $conflict);
        }

        return $report['conflicts'] === [] ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
