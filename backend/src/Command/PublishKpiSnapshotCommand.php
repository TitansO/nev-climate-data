<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * A3.1: periodically republishes a KPI snapshot to the Mercure hub
 * (docker-compose service "mercure"), powering the frontend's
 * useMercureKpis() hook (A3.2, frontend/src/react/hooks/useMercureKpis.js)
 * - the "Direct" badge on visualizations.html's funding-total tile.
 *
 * This is a *periodic republish*, not a real change event: nothing in this
 * backend can observe when Funding data actually changes. The Volet B
 * pipeline (pipeline/processors/*_validator.py) writes straight to
 * TimescaleDB via its own SQLAlchemy connection, entirely outside the
 * Symfony process - there is no Doctrine lifecycle event, no message queue
 * hook, nothing this command could subscribe to. Publishing the same
 * snapshot every {interval} seconds regardless of whether anything actually
 * changed is the honest equivalent given that constraint: a genuinely live
 * feed, just not an event-driven one. See the migration plan's A3.1 section
 * for the full reasoning.
 *
 * Reuses AnalyticsService's existing cached aggregates (getFinancingTrends(),
 * getHeroStats()) rather than querying the database directly - the number
 * this command publishes is always exactly what GET /api/analytics/* would
 * return at that moment (same 15-minute Redis cache), never a second,
 * independently-computed source of truth that could silently drift from the
 * REST API.
 *
 * Run as its own long-lived docker-compose service ("mercure-publisher",
 * same image as "backend", restart: unless-stopped - same pattern as
 * "backend-worker" for messenger:consume) rather than a cron job: there is
 * no cron/scheduler infrastructure in this project, and a tight sleep loop
 * is simpler and sufficient at this traffic scale. Most ticks are pure
 * Redis cache hits (AnalyticsService's 15-minute TTL), so this rarely
 * touches Doctrine/the database at all.
 */
#[AsCommand(name: 'app:mercure:publish-kpis', description: 'Republie périodiquement un instantané des KPIs analytics vers le hub Mercure (A3.1)')]
final class PublishKpiSnapshotCommand extends Command
{
    /**
     * Must match the frontend's MERCURE_KPI_TOPIC exactly (frontend/src/
     * react/hooks/useMercureKpis.js) - Mercure topics are plain string
     * identifiers (conventionally URI-shaped, never resolved as a real
     * address); the hub only ever matches them by exact string equality.
     */
    public const TOPIC = 'https://nev-climate-data.local/kpis/analytics';

    private const DEFAULT_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly HubInterface $hub,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Délai en secondes entre deux publications', (string) self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Publie un seul instantané puis quitte (tests/vérification manuelle)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $interval = max(1, (int) $input->getOption('interval'));
        $once = (bool) $input->getOption('once');

        $io->writeln(sprintf('Publication des instantanés KPI sur le topic Mercure "%s" toutes les %ds.', self::TOPIC, $interval));

        do {
            $this->publishSnapshot($io);

            if ($once) {
                return Command::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }

    private function publishSnapshot(SymfonyStyle $io): void
    {
        try {
            $trends = $this->analyticsService->getFinancingTrends();
            $heroStats = $this->analyticsService->getHeroStats();

            $fundingTotalUsd = array_sum(array_map(static fn (array $row): float => (float) $row['total'], $trends));

            $payload = json_encode([
                'fundingTotalUsd' => $fundingTotalUsd,
                'countriesCovered' => $heroStats['countriesCovered'],
                'publishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], \JSON_THROW_ON_ERROR);

            // No `private:` argument -> defaults to false (a public update),
            // matching the hub's "anonymous" subscription config: this
            // topic is already public data (same figures as
            // GET /api/analytics/hero-stats, PUBLIC_ACCESS), so no
            // subscriber JWT is required to receive it - necessary anyway
            // since the frontend uses a native EventSource, which cannot
            // send a custom Authorization header.
            $this->hub->publish(new Update(self::TOPIC, $payload));

            $io->writeln(sprintf('[%s] Instantané publié (financement total : %.0f USD, %d pays).', date('H:i:s'), $fundingTotalUsd, $heroStats['countriesCovered']));
        } catch (\Throwable $e) {
            // A publish failure (hub unreachable, network hiccup) must never
            // kill this long-running process - the frontend's
            // useMercureKpis() already tolerates missing/stale snapshots by
            // design (falls back to the REST aggregates), so the correct
            // behavior here is to log and try again on the next tick, not
            // to crash the container into a restart loop.
            $io->error(sprintf('Échec de publication : %s', $e->getMessage()));
        }
    }
}
