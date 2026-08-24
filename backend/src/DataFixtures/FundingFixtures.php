<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The core A1.6 dataset: one Funding record per (country x sector x year)
 * combination, for every one of the 54 countries and all 5 sectors, across
 * a 4-year window — 54 x 5 x 4 = 1,080 records.
 *
 * A full country x sector cross-join was kept (rather than a sparser sample)
 * because it's what makes every filter combination and every trend chart
 * meaningful in a demo; the year window was kept short (4 years, not the
 * project's full history) specifically to keep that cross-join's volume
 * reasonable — see README's A1.6 section for the reasoning spelled out.
 *
 * Every value below is computed from deterministic formulas seeded by each
 * combination's position (country index, sector index, year offset) rather
 * than randomness, so re-running `doctrine:fixtures:load` always produces
 * byte-identical amounts (cahier des charges / A1.6 requirement:
 * reproducibility). Amounts are illustrative demonstration figures, not
 * real-world financial data.
 *
 * cahier des charges 5.7 / A1.6: every record uses
 * ValidationStatus::Demo — never ::Validated.
 */
final class FundingFixtures extends Fixture implements DependentFixtureInterface
{
    /** Inclusive — 4 consecutive years, the most recent full years relative to the project's 2026 timeline. */
    private const START_YEAR = 2022;
    private const END_YEAR = 2025;

    /**
     * Base annual amount (USD) per sector, in SectorFixtures::SECTORS order
     * (Renewable Energy, Sustainable Transport, Agriculture, Forestry,
     * Adaptation) — loosely reflects that energy typically attracts the
     * largest share of climate finance and forestry the smallest, without
     * claiming to represent real-world figures.
     *
     * @var list<int>
     */
    private const SECTOR_BASE_AMOUNT = [4_500_000, 3_200_000, 2_600_000, 1_800_000, 2_200_000];

    public function getDependencies(): array
    {
        return [CountryFixtures::class, SectorFixtures::class, SourceFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $isoCodes = CountryFixtures::isoCodes();
        $sectorSlugs = SectorFixtures::slugs();

        foreach ($isoCodes as $countryIndex => $isoCode) {
            /** @var Country $country */
            $country = $this->getReference(CountryFixtures::countryReference($isoCode), Country::class);

            foreach ($sectorSlugs as $sectorIndex => $sectorSlug) {
                /** @var Sector $sector */
                $sector = $this->getReference(SectorFixtures::sectorReference($sectorSlug), Sector::class);

                for ($year = self::START_YEAR; $year <= self::END_YEAR; ++$year) {
                    $yearOffset = $year - self::START_YEAR;
                    $fundingType = $this->fundingTypeFor($countryIndex, $sectorIndex, $yearOffset);
                    $source = $this->sourceFor($fundingType);

                    $amount = $this->amountFor(self::SECTOR_BASE_AMOUNT[$sectorIndex], $countryIndex, $yearOffset, $fundingType);

                    $funding = new Funding(
                        $country,
                        $sector,
                        $year,
                        $amount,
                        $fundingType,
                        $source,
                        new \DateTimeImmutable(\sprintf('%d-03-15', $year)),
                        ValidationStatus::Demo,
                    );

                    // Illustrative currency-conversion metadata (Volet B's
                    // pivot-currency fields) on Multilateral records only —
                    // GCF ("gcf-pdf-report" source) commonly reports in EUR.
                    if (FundingType::Multilateral === $fundingType) {
                        $exchangeRate = '1.080000'; // illustrative fixed USD-per-EUR rate
                        $originalAmount = number_format((float) $amount / 1.08, 2, '.', '');
                        $funding->setOriginalAmount($originalAmount);
                        $funding->setOriginalCurrency('EUR');
                        $funding->setExchangeRate($exchangeRate);
                    }

                    $manager->persist($funding);
                }
            }

            // Flush per country (5 sectors x 4 years = 20 records) rather
            // than once at the very end, to keep the unit-of-work's
            // identity map from growing to hold all 1,080 entities at once.
            $manager->flush();
            $manager->clear(Funding::class);
        }
    }

    private function fundingTypeFor(int $countryIndex, int $sectorIndex, int $yearOffset): FundingType
    {
        return match (($countryIndex + $sectorIndex + $yearOffset) % 3) {
            0 => FundingType::Public,
            1 => FundingType::Private,
            default => FundingType::Multilateral,
        };
    }

    private function sourceFor(FundingType $fundingType): Source
    {
        $slug = match ($fundingType) {
            FundingType::Public => 'world-bank-api',
            FundingType::Multilateral => 'gcf-pdf-report',
            FundingType::Private => 'internal-demo',
        };

        /** @var Source */
        return $this->getReference(SourceFixtures::sourceReference($slug), Source::class);
    }

    private function amountFor(int $sectorBase, int $countryIndex, int $yearOffset, FundingType $fundingType): string
    {
        $countryFactor = 0.5 + (($countryIndex % 12) * 0.075);
        $yearGrowth = 1 + ($yearOffset * 0.06);
        $typeMultiplier = match ($fundingType) {
            FundingType::Public => 1.0,
            FundingType::Private => 0.65,
            FundingType::Multilateral => 1.6,
        };

        $amount = $sectorBase * $countryFactor * $yearGrowth * $typeMultiplier;

        return number_format($amount, 2, '.', '');
    }
}
