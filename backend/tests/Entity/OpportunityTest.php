<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Opportunity;
use App\Entity\Sector;
use App\Entity\Source;
use PHPUnit\Framework\TestCase;

final class OpportunityTest extends TestCase
{
    private function makeOpportunity(): Opportunity
    {
        return new Opportunity(
            'GCF Simplified Approval Process - Round 14',
            new Source('Green Climate Fund', SourceType::OfficialApi, SourceReliability::High),
            'Pays éligibles : pays NEV à faible revenu.',
            '50000000.00',
        );
    }

    public function testConstructorSetsFieldsAndDefaultsSectorToNull(): void
    {
        $opportunity = $this->makeOpportunity();

        self::assertNull($opportunity->getId());
        self::assertSame('GCF Simplified Approval Process - Round 14', $opportunity->getTitle());
        self::assertSame('Green Climate Fund', $opportunity->getSource()->getName());
        self::assertNull($opportunity->getSector());
        self::assertSame('Pays éligibles : pays NEV à faible revenu.', $opportunity->getEligibilityCriteria());
        self::assertSame('50000000.00', $opportunity->getEnvelopeAmount());
        self::assertNull($opportunity->getApplicationWindowOpensAt());
        self::assertNull($opportunity->getApplicationWindowClosesAt());
        self::assertNull($opportunity->getInstrument());
        self::assertNull($opportunity->getApplicationUrl());
        self::assertNull($opportunity->getContactEmail());
        self::assertNull($opportunity->getRequiredDocuments());
    }

    public function testEnrichmentFieldsCanBeSet(): void
    {
        $opportunity = $this->makeOpportunity();

        $opportunity->setInstrument(FundingInstrument::Grant);
        $opportunity->setApplicationUrl('https://www.greenclimate.fund/simplified-approval-process');
        $opportunity->setContactEmail('sap@gcfund.org');
        $opportunity->setRequiredDocuments('Note conceptuelle, lettre de non-objection.');

        self::assertSame(FundingInstrument::Grant, $opportunity->getInstrument());
        self::assertSame('https://www.greenclimate.fund/simplified-approval-process', $opportunity->getApplicationUrl());
        self::assertSame('sap@gcfund.org', $opportunity->getContactEmail());
        self::assertSame('Note conceptuelle, lettre de non-objection.', $opportunity->getRequiredDocuments());
    }

    public function testApplicationWindowCanBeSet(): void
    {
        $opportunity = $this->makeOpportunity();
        $opensAt = new \DateTimeImmutable('2026-06-01');
        $closesAt = new \DateTimeImmutable('2026-11-30');

        $opportunity->setApplicationWindowOpensAt($opensAt);
        $opportunity->setApplicationWindowClosesAt($closesAt);
        $opportunity->setSector(new Sector('Renewable Energy'));

        self::assertSame($opensAt, $opportunity->getApplicationWindowOpensAt());
        self::assertSame($closesAt, $opportunity->getApplicationWindowClosesAt());
        self::assertSame('Renewable Energy', $opportunity->getSector()->getName());
    }
}
