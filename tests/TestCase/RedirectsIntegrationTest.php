<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The middleware as a host installs it: before routing, so a path no route
 * knows can still redirect, and a gone page answers 410 rather than 404.
 */
final class RedirectsIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects')->deleteAll([]);
        TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages')->deleteAll([]);
    }

    private function seed(string $path, string $status = 'live'): int
    {
        $pages = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoPages');

        return (int)$pages->saveOrFail($pages->newEntity([
            'subject' => 's', 'subject_id' => $path, 'path' => $path, 'title' => 'T', 'status' => $status,
        ]))->id;
    }

    public function testAnOldPathNoRouteKnowsRedirectsToThePage(): void
    {
        $id = $this->seed('/zz-new-page');
        $redirects = TableRegistry::getTableLocator()->get('TheMusicDev/Seo.SeoRedirects');
        $redirects->saveOrFail($redirects->newEntity(['from_path' => '/zz-old-page', 'seo_page_id' => $id, 'source' => 'moved']));

        $this->get('/zz-old-page?utm=x');

        $this->assertResponseCode(301);
        $this->assertHeader('Location', '/zz-new-page?utm=x');
    }

    public function testAGonePageAnswers410NotTheControllers404(): void
    {
        $this->seed('/zz-removed', 'gone');

        $this->get('/zz-removed');

        $this->assertResponseCode(410);
    }

    public function testAPathThatIsNotInTheIndexIsAnOrdinary404(): void
    {
        $this->get('/zz-never-existed');

        $this->assertResponseCode(404);
    }

    public function testTheHealthProbeNeverTouchesTheIndex(): void
    {
        $this->seed('/health', 'gone'); // would be a 410 if it were looked up

        $this->get('/health');

        $this->assertResponseOk();
    }
}
