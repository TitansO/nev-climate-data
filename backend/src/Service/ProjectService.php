<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ProjectPipelineStage;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * C2.3 : "Project Pipeline" (Kanban) - liste/déplacement de stade d'un
 * `Project` (F4). Pas de cache (comme App\Service\OpportunityService) :
 * même raisonnement, table de démonstration minuscule (F4 : 12 lignes).
 */
final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, country: string, countryIsoCode: string, sector: string, instrumentSought: ?string, amountSought: float, pipelineStage: string, promoter: string, updatedAt: string}>
     */
    public function list(?ProjectPipelineStage $stage): array
    {
        $projects = null === $stage
            ? $this->projectRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->projectRepository->findBy(['pipelineStage' => $stage], ['updatedAt' => 'DESC']);

        return array_map(self::toArray(...), $projects);
    }

    public function find(int $id): ?Project
    {
        return $this->projectRepository->find($id);
    }

    public function updateStage(Project $project, ProjectPipelineStage $stage): void
    {
        $project->setPipelineStage($stage);
        $this->entityManager->flush();
    }

    public function setTaxonomyClass(Project $project, string $taxonomyClass): void
    {
        $project->setTaxonomyClass($taxonomyClass);
        $this->entityManager->flush();
    }

    /**
     * @return array{id: int, title: string, description: string, country: string, countryIsoCode: string, sector: string, instrumentSought: ?string, amountSought: float, pipelineStage: string, taxonomyClass: ?string, promoter: string, updatedAt: string}
     */
    public static function toArray(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'title' => $project->getTitle(),
            // C3.5 : requis côté front pour appeler POST /api/greenwashing/check
            // (le seul champ de texte libre disponible sur ce projet) -
            // jamais exposé avant faute d'un consommateur qui en avait besoin.
            'description' => $project->getDescription(),
            'country' => $project->getCountry()->getName(),
            'countryIsoCode' => $project->getCountry()->getIsoCode(),
            'sector' => $project->getSector()->getName(),
            'instrumentSought' => $project->getInstrumentSought()?->value,
            'amountSought' => (float) $project->getAmountSought(),
            'pipelineStage' => $project->getPipelineStage()->value,
            'taxonomyClass' => $project->getTaxonomyClass(),
            'promoter' => $project->getPromoter(),
            'updatedAt' => $project->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
