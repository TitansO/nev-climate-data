<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\TenderCategory;
use App\Entity\Sector;
use App\Entity\Tender;
use PHPUnit\Framework\TestCase;

final class TenderTest extends TestCase
{
    private function makeTender(): Tender
    {
        return new Tender(
            'Étude de faisabilité - Mini-réseaux solaires',
            'Banque Africaine de Développement',
            TenderCategory::Consulting,
            'Étude de faisabilité technique et financière.',
            'Cabinet de conseil justifiant 3 références similaires.',
            new \DateTimeImmutable('2026-08-15'),
            new \DateTimeImmutable('2026-11-15'),
        );
    }

    public function testConstructorSetsFieldsAndDefaultsOptionalFieldsToNull(): void
    {
        $tender = $this->makeTender();

        self::assertNull($tender->getId());
        self::assertSame('Étude de faisabilité - Mini-réseaux solaires', $tender->getTitle());
        self::assertSame('Banque Africaine de Développement', $tender->getIssuingOrganization());
        self::assertSame(TenderCategory::Consulting, $tender->getCategory());
        self::assertSame('Étude de faisabilité technique et financière.', $tender->getDescription());
        self::assertSame('Cabinet de conseil justifiant 3 références similaires.', $tender->getEligibilityCriteria());
        self::assertNull($tender->getSector());
        self::assertNull($tender->getReferenceNumber());
        self::assertNull($tender->getEstimatedValue());
        self::assertNull($tender->getDocumentsUrl());
        self::assertNull($tender->getContactEmail());
    }

    public function testOptionalFieldsCanBeSet(): void
    {
        $tender = $this->makeTender();
        $sector = new Sector('Renewable Energy');

        $tender->setSector($sector);
        $tender->setReferenceNumber('AFDB-RFP-2026-0142');
        $tender->setEstimatedValue('450000.00');
        $tender->setDocumentsUrl('https://www.afdb.org/en/projects-and-operations/procurement');
        $tender->setContactEmail('procurement@afdb.org');

        self::assertSame($sector, $tender->getSector());
        self::assertSame('AFDB-RFP-2026-0142', $tender->getReferenceNumber());
        self::assertSame('450000.00', $tender->getEstimatedValue());
        self::assertSame('https://www.afdb.org/en/projects-and-operations/procurement', $tender->getDocumentsUrl());
        self::assertSame('procurement@afdb.org', $tender->getContactEmail());
    }
}
