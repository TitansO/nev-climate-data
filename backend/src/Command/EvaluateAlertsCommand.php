<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AlertEvaluationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * C3.8 : "moteur de règles" - exécute App\Service\AlertEvaluationService
 * une fois et affiche le nombre de notifications créées. Destinée à un DAG
 * Airflow quotidien (comme les connecteurs Volet A/B) plutôt qu'à un
 * scheduling propre à Symfony - aucune planification n'est faite ici.
 */
#[AsCommand(name: 'app:alerts:evaluate', description: 'Evalue les AlertRule actives et notifie leurs proprietaires (C3.8)')]
final class EvaluateAlertsCommand extends Command
{
    public function __construct(
        private readonly AlertEvaluationService $alertEvaluationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = $this->alertEvaluationService->evaluate();

        $io->writeln(\sprintf('%d notification(s) creee(s).', $created));

        return Command::SUCCESS;
    }
}
