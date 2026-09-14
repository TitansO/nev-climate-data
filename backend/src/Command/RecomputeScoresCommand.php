<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\ScoreSubjectType;
use App\Repository\CountryRepository;
use App\Repository\FundingRepository;
use App\Repository\ProjectRepository;
use App\Repository\SourceRepository;
use App\Service\Scoring\ScoreIndicatorInterface;
use App\Service\Scoring\ScoreService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * F3 (fondation du sprint C1-C3) : recalcule tous les scores pour tous les
 * sujets, à partir des indicateurs actuellement enregistrés
 * (`ScoreIndicatorInterface`, taggés `app.score_indicator`). N'a rien à
 * faire tant qu'aucune tâche C2/C3 n'a encore fourni d'indicateur concret -
 * c'est attendu, pas une erreur (voir le message affiché dans ce cas).
 *
 * Itère `findAll()` sur le repository du type de sujet concerné - naïf
 * mais suffisant tant qu'aucun indicateur ScoreSubjectType::Funding n'est
 * enregistré (des dizaines de milliers de lignes funding rendraient ça
 * coûteux ; à revoir si/quand un tel indicateur arrive).
 */
#[AsCommand(name: 'app:scores:recompute', description: 'Recalcule tous les scores C2/C3 a partir des indicateurs enregistres (F3)')]
final class RecomputeScoresCommand extends Command
{
    public function __construct(
        #[AutowireIterator('app.score_indicator')]
        private readonly iterable $indicators,
        private readonly ScoreService $scoreService,
        private readonly CountryRepository $countryRepository,
        private readonly SourceRepository $sourceRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly FundingRepository $fundingRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<string, array{0: string, 1: ScoreSubjectType}> $pairs */
        $pairs = [];
        foreach ($this->indicators as $indicator) {
            \assert($indicator instanceof ScoreIndicatorInterface);
            $key = $indicator->scoreType().'|'.$indicator->subjectType()->value;
            $pairs[$key] = [$indicator->scoreType(), $indicator->subjectType()];
        }

        if ([] === $pairs) {
            $io->writeln('Aucun indicateur de score enregistre (app.score_indicator) - rien a recalculer.');

            return Command::SUCCESS;
        }

        foreach ($pairs as [$scoreType, $subjectType]) {
            $computed = 0;
            $skipped = 0;

            foreach ($this->subjectIdsFor($subjectType) as $subjectId) {
                $score = $this->scoreService->recompute($scoreType, $subjectType, $subjectId);
                if (null !== $score) {
                    ++$computed;
                } else {
                    ++$skipped;
                }
            }

            $io->writeln(\sprintf(
                '%s / %s : %d calcules, %d ignores (donnee insuffisante)',
                $scoreType,
                $subjectType->value,
                $computed,
                $skipped,
            ));
        }

        return Command::SUCCESS;
    }

    /** @return list<int> */
    private function subjectIdsFor(ScoreSubjectType $subjectType): array
    {
        $entities = match ($subjectType) {
            ScoreSubjectType::Country => $this->countryRepository->findAll(),
            ScoreSubjectType::Source => $this->sourceRepository->findAll(),
            ScoreSubjectType::Project => $this->projectRepository->findAll(),
            ScoreSubjectType::Funding => $this->fundingRepository->findAll(),
        };

        /** @var list<int> */
        return array_values(array_filter(array_map(static fn (object $entity): ?int => $entity->getId(), $entities)));
    }
}
