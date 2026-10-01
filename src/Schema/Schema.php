<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Schema;

use DateTimeInterface;

/**
 * Small builders for the schema.org JSON-LD nodes a site needs. Each returns a
 * plain array (a node of the page's `@graph`) with every empty part left out, so
 * nothing is ever emitted as null or "" — Google treats a half-filled property as
 * an error. Hosts are free to return any other schema.org type as a plain array;
 * these only save the boilerplate (design doc A13).
 *
 * `url` and `item` values may be paths (`/careers/x`): the SeoHelper makes them
 * absolute at render time, like the canonical.
 */
final class Schema
{
    /**
     * schema.org employment types Google accepts for `employmentType`.
     *
     * @var list<string>
     */
    public const EMPLOYMENT_TYPES = [
        'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER',
    ];

    /**
     * Pay periods Google accepts for `QuantitativeValue.unitText`.
     *
     * @var list<string>
     */
    public const SALARY_UNITS = ['HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR'];

    /**
     * An Organization node.
     *
     * @param string $name Organization name.
     * @param string $url Home page URL.
     * @param string|null $id Node `@id` (e.g. `https://example.com/#organization`) so other nodes can refer to it.
     * @param string|null $email Contact email.
     * @param string|null $telephone Phone in E.164.
     * @param array<string, string|null> $address PostalAddress parts: streetAddress, addressLocality,
     *   addressRegion, postalCode, addressCountry.
     * @param list<string> $sameAs Profile URLs (GitHub, social…).
     * @param string|null $logo Logo URL.
     * @return array<string, mixed>
     */
    public static function organization(
        string $name,
        string $url,
        ?string $id = null,
        ?string $email = null,
        ?string $telephone = null,
        array $address = [],
        array $sameAs = [],
        ?string $logo = null,
    ): array {
        $address = array_filter($address, static fn($part): bool => $part !== null && $part !== '');

        return self::clean([
            '@type' => 'Organization',
            '@id' => $id,
            'name' => $name,
            'url' => $url,
            'logo' => $logo,
            'email' => $email,
            'telephone' => $telephone,
            'address' => $address === [] ? null : ['@type' => 'PostalAddress'] + $address,
            'sameAs' => $sameAs,
        ]);
    }

    /**
     * A WebSite node, optionally naming its publisher.
     *
     * @param string|null $publisherId `@id` of the publishing Organization.
     * @return array<string, mixed>
     */
    public static function website(string $name, string $url, ?string $id = null, ?string $publisherId = null): array
    {
        return self::clean([
            '@type' => 'WebSite',
            '@id' => $id,
            'url' => $url,
            'name' => $name,
            'publisher' => $publisherId === null ? null : ['@id' => $publisherId],
        ]);
    }

    /**
     * A BreadcrumbList. The last crumb is the current page and carries no link.
     *
     * @param list<array{name: string, path?: string|null}> $crumbs In order, first = home.
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        $items = [];
        foreach (array_values($crumbs) as $i => $crumb) {
            $items[] = self::clean([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['path'] ?? null,
            ]);
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * A JobPosting (Google for Jobs). Optional parts are omitted when not given;
     * pay is only emitted with a valid unit and a positive amount, and an
     * employment type outside the accepted list is dropped, never guessed.
     *
     * @param string $title Job title.
     * @param string $description Full description, HTML allowed.
     * @param \DateTimeInterface $datePosted When it was first published.
     * @param string|null $url The posting's page (path or URL).
     * @param \DateTimeInterface|null $validThrough Last moment applications are accepted.
     * @param string|null $employmentType One of EMPLOYMENT_TYPES.
     * @param array<string, mixed>|null $hiringOrganization Organization node (at least `name`; `@id` joins the graph's Organization).
     * @param bool $remote Fully remote (`jobLocationType` TELECOMMUTE).
     * @param string|null $applicantCountry Country remote applicants may be in (required by Google for remote jobs).
     * @param string|null $location On-site place name, when not remote.
     * @param string|null $locationCountry Country of the on-site place.
     * @param float|null $salaryMin Lowest pay.
     * @param float|null $salaryMax Highest pay.
     * @param string|null $salaryUnit One of SALARY_UNITS.
     * @param string $currency ISO 4217 currency for the pay.
     * @param string|null $workHours Free-text hours ("10–15 hours/week").
     * @param bool $directApply Whether applicants apply on the posting's own page.
     * @return array<string, mixed>
     */
    public static function jobPosting(
        string $title,
        string $description,
        DateTimeInterface $datePosted,
        ?string $url = null,
        ?DateTimeInterface $validThrough = null,
        ?string $employmentType = null,
        ?array $hiringOrganization = null,
        bool $remote = false,
        ?string $applicantCountry = null,
        ?string $location = null,
        ?string $locationCountry = null,
        ?float $salaryMin = null,
        ?float $salaryMax = null,
        ?string $salaryUnit = null,
        string $currency = 'USD',
        ?string $workHours = null,
        bool $directApply = true,
    ): array {
        $place = null;
        if (!$remote && $location !== null && $location !== '') {
            $place = [
                '@type' => 'Place',
                'address' => self::clean([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $location,
                    'addressCountry' => $locationCountry,
                ]),
            ];
        }

        return self::clean([
            '@type' => 'JobPosting',
            'title' => $title,
            'description' => $description,
            'datePosted' => $datePosted->format('Y-m-d'),
            'validThrough' => $validThrough?->format(DATE_ATOM),
            'employmentType' => in_array($employmentType, self::EMPLOYMENT_TYPES, true) ? $employmentType : null,
            'hiringOrganization' => $hiringOrganization,
            'jobLocationType' => $remote ? 'TELECOMMUTE' : null,
            'applicantLocationRequirements' => $remote && $applicantCountry !== null && $applicantCountry !== ''
                ? ['@type' => 'Country', 'name' => $applicantCountry]
                : null,
            'jobLocation' => $place,
            'baseSalary' => self::salary($salaryMin, $salaryMax, $salaryUnit, $currency),
            'workHours' => $workHours,
            'directApply' => $directApply,
            'url' => $url,
        ]);
    }

    /**
     * A MonetaryAmount, or null when there is no usable pay (no amount, or a unit
     * Google does not know). A range needs two different amounts; otherwise one value.
     *
     * @return array<string, mixed>|null
     */
    private static function salary(?float $min, ?float $max, ?string $unit, string $currency): ?array
    {
        // Zero or negative is "no pay", not a salary of nothing.
        $min = $min !== null && $min > 0 ? $min : null;
        $max = $max !== null && $max > 0 ? $max : null;
        if (($min === null && $max === null) || !in_array($unit, self::SALARY_UNITS, true)) {
            return null;
        }

        $value = ['@type' => 'QuantitativeValue', 'unitText' => $unit];
        if ($min !== null && $max !== null && $min !== $max) {
            $value['minValue'] = self::number($min);
            $value['maxValue'] = self::number($max);
        } else {
            $value['value'] = self::number((float)($min ?? $max));
        }

        return ['@type' => 'MonetaryAmount', 'currency' => $currency, 'value' => $value];
    }

    /**
     * 45.0 → 45, 45.5 stays 45.5 (so JSON prints `45`, not `45.0`).
     */
    private static function number(float $n): int|float
    {
        return floor($n) === $n ? (int)$n : $n;
    }

    /**
     * Drop null, empty-string and empty-array parts (false and 0 are kept).
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function clean(array $node): array
    {
        return array_filter($node, static fn($part): bool => $part !== null && $part !== '' && $part !== []);
    }
}
