<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Test\TestCase\Schema;

use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use TheMusicDev\Seo\Schema\Schema;

/**
 * The JSON-LD node builders: shape, and — as important — what is left out.
 */
final class SchemaTest extends TestCase
{
    /**
     * A minimal remote job, with overrides.
     *
     * @param array<string, mixed> $args Named arguments for Schema::jobPosting().
     * @return array<string, mixed>
     */
    private function job(array $args = []): array
    {
        return Schema::jobPosting(...$args + [
            'title' => 'Engineer',
            'description' => '<p>Build things.</p>',
            'datePosted' => new DateTime('2026-09-28 10:30:00'),
        ]);
    }

    public function testAnOrganizationKeepsOnlyWhatItIsGiven(): void
    {
        $node = Schema::organization(
            name: 'Acme',
            url: 'https://acme.test',
            id: 'https://acme.test/#organization',
            email: 'hi@acme.test',
            address: ['streetAddress' => '1 Main St', 'postalCode' => '', 'addressCountry' => 'US', 'addressRegion' => null],
            sameAs: ['https://github.com/acme'],
        );

        $this->assertSame('Organization', $node['@type']);
        $this->assertSame('https://acme.test/#organization', $node['@id']);
        $this->assertSame(
            ['@type' => 'PostalAddress', 'streetAddress' => '1 Main St', 'addressCountry' => 'US'],
            $node['address'],
        );
        $this->assertArrayNotHasKey('telephone', $node);
        $this->assertArrayNotHasKey('logo', $node);
    }

    public function testAnOrganizationWithNoAddressPartsHasNoAddress(): void
    {
        $this->assertArrayNotHasKey('address', Schema::organization(name: 'A', url: 'https://a.test', address: ['x' => '']));
    }

    public function testAWebsiteNamesItsPublisherByReference(): void
    {
        $node = Schema::website('Acme', 'https://acme.test', 'https://acme.test/#website', 'https://acme.test/#organization');

        $this->assertSame('WebSite', $node['@type']);
        $this->assertSame(['@id' => 'https://acme.test/#organization'], $node['publisher']);
    }

    public function testBreadcrumbsArePositionedAndTheLastHasNoLink(): void
    {
        $node = Schema::breadcrumbs([
            ['name' => 'Home', 'path' => '/'],
            ['name' => 'Careers', 'path' => '/careers'],
            ['name' => 'This job'],
        ]);

        $this->assertSame('BreadcrumbList', $node['@type']);
        $this->assertSame([1, 2, 3], array_column($node['itemListElement'], 'position'));
        $this->assertSame('/careers', $node['itemListElement'][1]['item']);
        $this->assertArrayNotHasKey('item', $node['itemListElement'][2]);
    }

    public function testAMinimalJobPostingHasOnlyItsRequiredParts(): void
    {
        $node = $this->job();

        $this->assertSame(
            ['@type' => 'JobPosting', 'title' => 'Engineer', 'description' => '<p>Build things.</p>',
                'datePosted' => '2026-09-28', 'directApply' => true],
            $node,
        );
    }

    public function testARemoteJobSaysTelecommuteAndWhoMayApply(): void
    {
        $node = $this->job(['remote' => true, 'applicantCountry' => 'US', 'location' => 'Remote']);

        $this->assertSame('TELECOMMUTE', $node['jobLocationType']);
        $this->assertSame(['@type' => 'Country', 'name' => 'US'], $node['applicantLocationRequirements']);
        $this->assertArrayNotHasKey('jobLocation', $node);
    }

    public function testARemoteJobWithNoCountryEmitsNoApplicantRequirement(): void
    {
        $node = $this->job(['remote' => true]);

        $this->assertSame('TELECOMMUTE', $node['jobLocationType']);
        $this->assertArrayNotHasKey('applicantLocationRequirements', $node);
    }

    public function testAnOnSiteJobHasAPlaceAndNoTelecommute(): void
    {
        $node = $this->job(['location' => 'Austin, TX', 'locationCountry' => 'US']);

        $this->assertArrayNotHasKey('jobLocationType', $node);
        $this->assertSame(
            ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Austin, TX', 'addressCountry' => 'US']],
            $node['jobLocation'],
        );
    }

    public function testDatesUseTheirSchemaOrgFormats(): void
    {
        $node = $this->job(['validThrough' => new DateTime('2026-11-01 23:59:59', 'UTC')]);

        $this->assertSame('2026-09-28', $node['datePosted']);
        $this->assertSame('2026-11-01T23:59:59+00:00', $node['validThrough']);
    }

    public function testAKnownEmploymentTypeIsKeptAndAnUnknownOneIsDropped(): void
    {
        $this->assertSame('INTERN', $this->job(['employmentType' => 'INTERN'])['employmentType']);
        $this->assertArrayNotHasKey('employmentType', $this->job(['employmentType' => 'Freelance gig']));
        $this->assertArrayNotHasKey('employmentType', $this->job(['employmentType' => null]));
    }

    public function testASinglePayIsAValueWithItsUnit(): void
    {
        $salary = $this->job(['salaryMin' => 45.0, 'salaryUnit' => 'HOUR'])['baseSalary'];

        $this->assertSame(
            ['@type' => 'MonetaryAmount', 'currency' => 'USD',
                'value' => ['@type' => 'QuantitativeValue', 'unitText' => 'HOUR', 'value' => 45]],
            $salary,
        );
    }

    public function testARangeHasMinAndMaxAndWholeNumbersPrintWithoutADecimal(): void
    {
        $node = $this->job(['salaryMin' => 60000.0, 'salaryMax' => 80000.0, 'salaryUnit' => 'YEAR']);

        $this->assertSame(60000, $node['baseSalary']['value']['minValue']);
        $this->assertSame(80000, $node['baseSalary']['value']['maxValue']);
        $this->assertStringContainsString('"minValue":60000,', (string)json_encode($node['baseSalary']));
    }

    public function testFractionsAreKept(): void
    {
        $this->assertSame(45.5, $this->job(['salaryMin' => 45.5, 'salaryUnit' => 'HOUR'])['baseSalary']['value']['value']);
    }

    public function testEqualOrOnlyOneBoundIsASingleValue(): void
    {
        $same = $this->job(['salaryMin' => 50.0, 'salaryMax' => 50.0, 'salaryUnit' => 'HOUR'])['baseSalary']['value'];
        $onlyMax = $this->job(['salaryMax' => 70.0, 'salaryUnit' => 'HOUR'])['baseSalary']['value'];

        $this->assertSame(50, $same['value']);
        $this->assertArrayNotHasKey('minValue', $same);
        $this->assertSame(70, $onlyMax['value']);
    }

    public function testPayWithoutAUsableUnitOrAmountIsLeftOutNeverGuessed(): void
    {
        $this->assertArrayNotHasKey('baseSalary', $this->job(['salaryMin' => 45.0])); // no unit
        $this->assertArrayNotHasKey('baseSalary', $this->job(['salaryMin' => 45.0, 'salaryUnit' => 'FORTNIGHT'])); // unknown unit
        $this->assertArrayNotHasKey('baseSalary', $this->job(['salaryUnit' => 'HOUR'])); // no amount
        $this->assertArrayNotHasKey('baseSalary', $this->job(['salaryMin' => 0.0, 'salaryUnit' => 'HOUR'])); // zero is no pay
    }

    public function testACustomCurrencyIsUsed(): void
    {
        $this->assertSame('EUR', $this->job(['salaryMin' => 20.0, 'salaryUnit' => 'HOUR', 'currency' => 'EUR'])['baseSalary']['currency']);
    }

    public function testFalseValuesAreKeptEmptyOnesAreNot(): void
    {
        $node = $this->job(['directApply' => false, 'workHours' => '', 'hiringOrganization' => []]);

        $this->assertFalse($node['directApply']);
        $this->assertArrayNotHasKey('workHours', $node);
        $this->assertArrayNotHasKey('hiringOrganization', $node);
    }
}
