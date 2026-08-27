<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SearchQuery;
use App\Entity\Country;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use App\Repository\CountryRepository;
use App\Repository\ReportRepository;
use App\Repository\SectorRepository;
use App\Repository\SourceRepository;

/**
 * A2.8 global search. Scope deliberately limited to Country, Sector,
 * Source and published Report - see the A2.7/A2.8 implementation report
 * for why: these are the entities with a real, human-typed name/title to
 * match against and a real destination page to send the user to.
 * Funding has neither (it's a data row, not a named thing); User/ApiKey/
 * Notification are personal-account data with no public search
 * justification.
 *
 * No caching (unlike App\Service\AnalyticsService): every table searched
 * here is tiny (tens of rows), so a query costs nothing worth caching, and
 * caching by arbitrary free-text search terms would grow the cache
 * unboundedly for no measurable benefit - a different cost profile than
 * A2.5's handful of fixed, expensive aggregates.
 */
final class SearchService
{
    /** Per type, not a single combined total - keeps every result type represented rather than one type crowding out the rest. */
    private const MAX_RESULTS_PER_TYPE = 5;

    public function __construct(
        private readonly CountryRepository $countryRepository,
        private readonly SectorRepository $sectorRepository,
        private readonly SourceRepository $sourceRepository,
        private readonly ReportRepository $reportRepository,
    ) {
    }

    /**
     * @return list<array{type: string, id: int, title: string, description: string, destination: string}>
     */
    public function search(SearchQuery $query): array
    {
        $results = [];

        foreach ($this->countryRepository->searchByName($query, self::MAX_RESULTS_PER_TYPE) as $country) {
            $results[] = self::countryResult($country);
        }

        foreach ($this->sectorRepository->searchByName($query, self::MAX_RESULTS_PER_TYPE) as $sector) {
            $results[] = self::sectorResult($sector);
        }

        foreach ($this->sourceRepository->searchByName($query, self::MAX_RESULTS_PER_TYPE) as $source) {
            $results[] = self::sourceResult($source);
        }

        foreach ($this->reportRepository->searchPublishedByTitle($query, self::MAX_RESULTS_PER_TYPE) as $report) {
            $results[] = self::reportResult($report);
        }

        return $results;
    }

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function countryResult(Country $country): array
    {
        return [
            'type' => 'country',
            'id' => $country->getId(),
            'title' => $country->getName(),
            'description' => \sprintf('Pays (%s) - %s', $country->getIsoCode(), $country->getRegion()),
            // Real, existing destination: data.html's own country filter (A2.2), not a page that doesn't exist.
            'destination' => 'data.html?country='.$country->getIsoCode(),
        ];
    }

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function sectorResult(Sector $sector): array
    {
        return [
            'type' => 'sector',
            'id' => $sector->getId(),
            'title' => $sector->getName(),
            'description' => 'Secteur suivi par NEV Climate Data',
            'destination' => 'data.html?sector='.$sector->getId(),
        ];
    }

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function sourceResult(Source $source): array
    {
        return [
            'type' => 'source',
            'id' => $source->getId(),
            'title' => $source->getName(),
            'description' => \sprintf('Source de données (%s)', $source->getType()->value),
            // sources.html lists every source; there is no per-source page
            // to deep-link to (that page is still fully static - A2.13).
            'destination' => 'sources.html',
        ];
    }

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function reportResult(Report $report): array
    {
        return [
            'type' => 'report',
            'id' => $report->getId(),
            'title' => $report->getTitle(),
            'description' => \sprintf('Rapport (%s)', $report->getType()),
            // Same reasoning as sourceResult(): reports.html has no
            // per-report route yet (A2.13), so this links to the listing.
            'destination' => 'reports.html',
        ];
    }
}
