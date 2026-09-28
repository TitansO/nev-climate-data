<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\ProjectDecisionOutcome;
use App\Entity\Enum\ProjectExecutionStatus;
use App\Entity\Enum\ProjectPipelineStage;
use App\Entity\Project;
use App\Entity\Sector;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    private function makeProject(): Project
    {
        return new Project(
            'Off-grid Solar Mini-Grids for Rural Casamance',
            new Country('Senegal', 'SEN', "Afrique de l'Ouest"),
            new Sector('Renewable Energy'),
            '4500000.00',
            'Projet de démonstration.',
            "Agence Sénégalaise d'Électrification Rurale",
        );
    }

    public function testConstructorSetsFieldsAndDefaultsToIdeaStage(): void
    {
        $project = $this->makeProject();

        self::assertNull($project->getId());
        self::assertSame('Off-grid Solar Mini-Grids for Rural Casamance', $project->getTitle());
        self::assertSame('Senegal', $project->getCountry()->getName());
        self::assertSame('Renewable Energy', $project->getSector()->getName());
        self::assertNull($project->getInstrumentSought());
        self::assertSame('4500000.00', $project->getAmountSought());
        self::assertSame(ProjectPipelineStage::Idea, $project->getPipelineStage());
        self::assertSame("Agence Sénégalaise d'Électrification Rurale", $project->getPromoter());
        self::assertNull($project->getDecisionOutcome());
        self::assertNull($project->getExecutionStatus());
    }

    public function testSetPipelineStageAdvancesTheStageAndTouchesUpdatedAt(): void
    {
        $project = $this->makeProject();
        $firstUpdatedAt = $project->getUpdatedAt();
        usleep(1000);

        $project->setPipelineStage(ProjectPipelineStage::Structuring);

        self::assertSame(ProjectPipelineStage::Structuring, $project->getPipelineStage());
        self::assertGreaterThan($firstUpdatedAt, $project->getUpdatedAt());
    }

    public function testInstrumentSoughtCanBeSet(): void
    {
        $project = $this->makeProject();

        $project->setInstrumentSought(FundingInstrument::LoanConcessional);

        self::assertSame(FundingInstrument::LoanConcessional, $project->getInstrumentSought());
    }

    public function testSetDecisionOutcomeAndExecutionStatusTouchUpdatedAt(): void
    {
        $project = $this->makeProject();
        $firstUpdatedAt = $project->getUpdatedAt();
        usleep(1000);

        $project->setDecisionOutcome(ProjectDecisionOutcome::Financed);

        self::assertSame(ProjectDecisionOutcome::Financed, $project->getDecisionOutcome());
        self::assertGreaterThan($firstUpdatedAt, $project->getUpdatedAt());

        $secondUpdatedAt = $project->getUpdatedAt();
        usleep(1000);

        $project->setExecutionStatus(ProjectExecutionStatus::InProgress);

        self::assertSame(ProjectExecutionStatus::InProgress, $project->getExecutionStatus());
        self::assertGreaterThan($secondUpdatedAt, $project->getUpdatedAt());
    }

    public function testDecisionOutcomeCanBeClearedBackToNull(): void
    {
        $project = $this->makeProject();
        $project->setDecisionOutcome(ProjectDecisionOutcome::Rejected);

        $project->setDecisionOutcome(null);

        self::assertNull($project->getDecisionOutcome());
    }
}
