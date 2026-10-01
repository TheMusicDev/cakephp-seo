<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use TheMusicDev\Seo\Test\Subject\ArraySubject;

/**
 * `bin/cake seo rebuild` — summary output and exit code.
 */
final class SeoRebuildCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private mixed $originalSubjects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSubjects = Configure::read('Seo.subjects');
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects')->deleteAll([]);
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages')->deleteAll([]);
        ArraySubject::reset();
    }

    protected function tearDown(): void
    {
        Configure::write('Seo.subjects', $this->originalSubjects);
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects')->deleteAll([]);
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages')->deleteAll([]);
        ArraySubject::reset();
        parent::tearDown();
    }

    public function testPrintsCountsPerSubject(): void
    {
        Configure::write('Seo.subjects', ['s' => ArraySubject::class]);
        ArraySubject::$pages = ['s' => ['a' => '/a/', 'b' => '/b/']];

        $this->exec('seo rebuild');

        $this->assertExitSuccess();
        $this->assertOutputContains('s: 2 created, 0 updated, 0 unchanged, 0 gone');
    }

    public function testExitsNonZeroWhenTwoPagesClaimOnePath(): void
    {
        Configure::write('Seo.subjects', ['one' => ArraySubject::class, 'two' => ArraySubject::class]);
        ArraySubject::$pages = ['one' => ['a' => '/x/'], 'two' => ['b' => '/x/']];

        $this->exec('seo rebuild');

        $this->assertExitError();
        $this->assertErrorContains('Conflict');
    }

    public function testReportsTheRedirectsItRecorded(): void
    {
        Configure::write('Seo.subjects', ['s' => ArraySubject::class]);
        ArraySubject::$pages = ['s' => ['a' => '/old']];
        $this->exec('seo rebuild');
        ArraySubject::$pages = ['s' => ['a' => '/new']];

        $this->exec('seo rebuild');

        $this->assertExitSuccess();
        $this->assertOutputContains('Redirects recorded: 1');
    }

    public function testWarnsWhenNoSubjectsAreConfigured(): void
    {
        Configure::write('Seo.subjects', []);

        $this->exec('seo rebuild');

        $this->assertExitSuccess();
        $this->assertErrorContains('No subjects configured');
    }
}
