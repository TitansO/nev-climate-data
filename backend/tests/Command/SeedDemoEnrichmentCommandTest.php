<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDemoEnrichmentCommand;
use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\ImpactFactor;
use App\Entity\Opportunity;
use App\Entity\Sector;
use App\Entity\Source;
use App\Repository\CountryRepository;
use App\Repository\ImpactFactorRepository;
use App\Repository\OpportunityRepository;
use App\Repository\SectorRepository;
use App\Repository\TenderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A3.14 : "app:seed-demo-enrichment" - écrite en réaction directe à
 * l'incident du 2026-09-25 où `doctrine:fixtures:load` a purgé ~4000
 * lignes `funding` réelles en développement (voir la docblock de la
 * commande elle-même). Cette suite vérifie précisément ce que cet
 * incident aurait dû empêcher : que la commande ne touche jamais que
 * `opportunity`/`tender`, et reste sans effet sur un second passage.
 */
final class SeedDemoEnrichmentCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    private function commandTester(): CommandTester
    {
        $container = static::getContainer();
        $command = new SeedDemoEnrichmentCommand(
            $this->entityManager,
            $container->get(OpportunityRepository::class),
            $container->get(TenderRepository::class),
            $container->get(SectorRepository::class),
            $container->get(ImpactFactorRepository::class),
            $container->get(CountryRepository::class),
        );
        $application = new Application(self::$kernel);
        $application->addCommand($command);

        return new CommandTester($application->find('app:seed-demo-enrichment'));
    }

    public function testSeedsTheEightDemoTendersWhenTheTableIsEmpty(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('8 lignes de demonstration inserees', $tester->getDisplay());
        self::assertCount(8, $this->entityManager->getRepository(\App\Entity\Tender::class)->findAll());
    }

    public function testDoesNotDuplicateTendersOnASecondRun(): void
    {
        $this->commandTester()->execute([]);
        $secondTester = $this->commandTester();
        $exitCode = $secondTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('deja peuple', $secondTester->getDisplay());
        self::assertCount(8, $this->entityManager->getRepository(\App\Entity\Tender::class)->findAll());
    }

    public function testBackfillsEnrichmentFieldsForAMatchingOpportunityTitle(): void
    {
        $source = new Source('Green Climate Fund - IATI Datastore', SourceType::OfficialApi, SourceReliability::High);
        $this->entityManager->persist($source);
        $opportunity = new Opportunity('GCF Simplified Approval Process - Round 14', $source, 'Critères.', '50000000.00');
        $this->entityManager->persist($opportunity);
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1 ligne(s) enrichie(s)', $tester->getDisplay());

        $this->entityManager->refresh($opportunity);
        self::assertSame(FundingInstrument::Grant, $opportunity->getInstrument());
        self::assertSame('sap@gcfund.org', $opportunity->getContactEmail());
        self::assertNotNull($opportunity->getApplicationUrl());
        self::assertNotNull($opportunity->getRequiredDocuments());
    }

    public function testDoesNotOverwriteAnOpportunityThatAlreadyHasAnInstrument(): void
    {
        $source = new Source('Green Climate Fund - IATI Datastore', SourceType::OfficialApi, SourceReliability::High);
        $this->entityManager->persist($source);
        $opportunity = new Opportunity('GCF Simplified Approval Process - Round 14', $source, 'Critères.', '50000000.00');
        // Valeur déjà en place, délibérément différente de celle que la commande poserait -
        // si elle était écrasée, ce test le détecterait immédiatement.
        $opportunity->setInstrument(FundingInstrument::Equity);
        $this->entityManager->persist($opportunity);
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $tester->execute([]);

        $this->entityManager->refresh($opportunity);
        self::assertSame(FundingInstrument::Equity, $opportunity->getInstrument());
    }

    public function testIgnoresOpportunityTitlesThatDoNotExistOnThisEnvironment(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 ligne(s) enrichie(s)', $tester->getDisplay());
    }

    public function testAssignsTheSectorWhenAMatchingSectorExists(): void
    {
        $renewableEnergy = new Sector('Renewable Energy');
        $this->entityManager->persist($renewableEnergy);
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $tester->execute([]);

        $afdbTender = $this->entityManager->getRepository(\App\Entity\Tender::class)->findOneBy(['title' => 'Étude de faisabilité - Mini-réseaux solaires en zone rurale']);
        self::assertNotNull($afdbTender);
        self::assertSame($renewableEnergy->getId(), $afdbTender->getSector()?->getId());
    }

    public function testSeedsTheFiveImpactFactorsWhenTheTableIsEmpty(): void
    {
        $this->entityManager->persist(new Sector('Renewable Energy'));
        $this->entityManager->persist(new Sector('Sustainable Transport'));
        $this->entityManager->persist(new Sector('Agriculture'));
        $this->entityManager->persist(new Sector('Forestry'));
        $this->entityManager->persist(new Sector('Adaptation'));
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('5 lignes de demonstration inserees', $tester->getDisplay());
        self::assertCount(5, $this->entityManager->getRepository(ImpactFactor::class)->findAll());
    }

    public function testDoesNotDuplicateImpactFactorsOnASecondRun(): void
    {
        $this->entityManager->persist(new Sector('Renewable Energy'));
        $this->entityManager->flush();

        $this->commandTester()->execute([]);
        $secondTester = $this->commandTester();
        $secondTester->execute([]);

        self::assertStringContainsString('ImpactFactor : deja peuple', $secondTester->getDisplay());
    }

    public function testBackfillsPopulationAndVulnerabilityIndexForAMatchingCountry(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $this->entityManager->persist($senegal);
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $tester->execute([]);

        $this->entityManager->refresh($senegal);
        self::assertSame(17_760_000, $senegal->getPopulation());
        self::assertSame(0.44, $senegal->getVulnerabilityIndex());
    }

    public function testDoesNotOverwriteACountryThatAlreadyHasAPopulation(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $senegal->setPopulation(1);
        $senegal->setVulnerabilityIndex(0.01);
        $this->entityManager->persist($senegal);
        $this->entityManager->flush();

        $tester = $this->commandTester();
        $tester->execute([]);

        $this->entityManager->refresh($senegal);
        self::assertSame(1, $senegal->getPopulation());
        self::assertSame(0.01, $senegal->getVulnerabilityIndex());
    }
}
