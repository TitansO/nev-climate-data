<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SearchQuery;
use App\Entity\Country;
use App\Entity\Opportunity;
use App\Entity\Project;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use App\Repository\CountryRepository;
use App\Repository\OpportunityRepository;
use App\Repository\ProjectRepository;
use App\Repository\ReportRepository;
use App\Repository\SectorRepository;
use App\Repository\SourceRepository;

/**
 * A2.8 global search, extended by C1.3 to Project/Opportunity (F4). Scope
 * deliberately limited to Country, Sector, Source, published Report,
 * Project and Opportunity - see the A2.7/A2.8 implementation report for
 * why the original four: these are the entities with a real, human-typed
 * name/title to match against and a real destination page to send the
 * user to. Funding has neither (it's a data row, not a named thing);
 * User/ApiKey/Notification are personal-account data with no public
 * search justification. "Source" is what the plan's "jeux de données"
 * refers to (World Bank, UNFCCC, etc. - named data providers), confirmed
 * with the product owner rather than assumed. Project/Opportunity qualify
 * the same way once F4 gave them a title.
 *
 * Matching is case- and accent-insensitive in every repository query below
 * (UNACCENT() on both sides - see App\Doctrine\DQL\UnaccentFunction), so
 * "senegal" also finds "Sénégal".
 *
 * C1.3 facets (country/sector/instrument/year/minAmount, all optional and
 * cumulable - see SearchQuery) apply only where a type actually has that
 * dimension; a type with no such field is excluded entirely from the
 * results while that facet is active (SearchQuery::hasFacetOutside()),
 * rather than matched against something it doesn't represent:
 *
 *   type         | country | sector | instrument | year               | minAmount
 *   country      | yes (isoCode = facet) | -      | -          | -                  | -
 *   sector       | -       | yes (id = facet) | -  | -                  | -
 *   source       | -       | -      | -          | -                  | -
 *   report       | yes (via Country) | -   | -          | publicationDate    | -
 *   project      | yes     | yes    | yes (instrumentSought) | createdAt | amountSought
 *   opportunity  | -       | yes (nullable) | -  | applicationWindowOpensAt (nullable) | envelopeAmount
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
        private readonly ProjectRepository $projectRepository,
        private readonly OpportunityRepository $opportunityRepository,
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

        foreach ($this->projectRepository->searchByTitle($query, self::MAX_RESULTS_PER_TYPE) as $project) {
            $results[] = self::projectResult($project);
        }

        foreach ($this->opportunityRepository->searchByTitle($query, self::MAX_RESULTS_PER_TYPE) as $opportunity) {
            $results[] = self::opportunityResult($opportunity);
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
            // C1.4: institution-profile.html now exists as a real per-source
            // page (GET /api/institutions/{sourceId}) - deep-links there
            // instead of the generic sources.html listing.
            'destination' => 'institution-profile.html?sourceId='.$source->getId(),
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

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function projectResult(Project $project): array
    {
        return [
            'type' => 'project',
            'id' => $project->getId(),
            'title' => $project->getTitle(),
            'description' => \sprintf('Projet climat (%s, %s)', $project->getPipelineStage()->value, $project->getCountry()->getName()),
            // No dedicated project page exists yet (C2.3 Project
            // Pipeline/Kanban is a later sprint task) - same "link to the
            // closest existing real page" reasoning as sourceResult()/
            // reportResult() above; dashboard.html is the platform's own
            // overview page (C1.1).
            'destination' => 'dashboard.html',
        ];
    }

    /**
     * @return array{type: string, id: int, title: string, description: string, destination: string}
     */
    private static function opportunityResult(Opportunity $opportunity): array
    {
        return [
            'type' => 'opportunity',
            'id' => $opportunity->getId(),
            'title' => $opportunity->getTitle(),
            'description' => \sprintf('Appel à projets (%s)', $opportunity->getSource()->getName()),
            // No dedicated opportunity page exists yet (C1.8 Opportunity
            // Radar is a later sprint task) - same reasoning as
            // projectResult() above.
            'destination' => 'dashboard.html',
        ];
    }
}
