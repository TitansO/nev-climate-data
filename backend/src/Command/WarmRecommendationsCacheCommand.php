<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RecommendationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A3.15 (2026-09-27) : precalcule et met en cache GET /api/recommendations
 * (App\Service\RecommendationService, deja cache 6h en interne - voir sa
 * propre docblock pour le "pourquoi" de ce TTL et de cette commande). Sans
 * ceci, le premier visiteur reel apres un demarrage de conteneur ou apres
 * expiration du cache paye le calcul complet (~49s mesures en direct en
 * production le 2026-09-27, contre <1s une fois en cache) - potentiellement
 * un timeout 504 cote proxy si son client abandonne avant la fin.
 *
 * Appelee en arriere-plan depuis docker/backend/entrypoint.sh (jamais au
 * premier plan : bloquerait le demarrage d'Apache pendant ~49s a chaque
 * deploiement, ce qui risquerait de faire echouer le healthcheck de
 * demarrage de Render avant meme qu'Apache ne reponde).
 */
#[AsCommand(name: 'app:warm-recommendations-cache', description: 'Precalcule GET /api/recommendations pour eviter un premier appel lent en production')]
final class WarmRecommendationsCacheCommand extends Command
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $startedAt = microtime(true);
        $recommendations = $this->recommendationService->getRecommendations();
        $durationSeconds = microtime(true) - $startedAt;

        $io->writeln(\sprintf('Cache des recommandations prechauffe : %d pays, %.1fs.', \count($recommendations), $durationSeconds));

        return Command::SUCCESS;
    }
}
