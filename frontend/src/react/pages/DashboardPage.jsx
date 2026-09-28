import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import useMercureKpis from "../hooks/useMercureKpis";
import ChartStateBox from "../components/charts/ChartStateBox";
import TypeDonutChart from "../components/charts/TypeDonutChart";
import SectorDonutChart from "../components/charts/SectorDonutChart";
import FinancingBarChart from "../components/charts/FinancingBarChart";
import FinancingTable from "../components/charts/FinancingTable";
import GenericTrendChart from "../components/charts/GenericTrendChart";
import MovingAverageChart from "../components/charts/MovingAverageChart";
import SankeyFlow from "../components/charts/SankeyFlow";
import FilterableCountryMap from "../components/charts/FilterableCountryMap";
import { formatCompactUsd, formatUsd, formatCount } from "../data/analyticsConstants";
import NevKpi from "../components/ui/NevKpi";
import NevChartCard from "../components/ui/NevChartCard";
import NevSelect from "../components/ui/NevSelect";
import { ChartBarIcon, DatabaseIcon, ChevronDownIcon } from "../components/ui/icons";

const DATA_SOURCE_LABEL = "Source : API NEV Climate Data (GET /api/analytics/*)";
const DATA_PERIOD_LABEL = "Mise à jour toutes les 15 minutes";

// C1.1: mirrors AnalyticsService::STALE_THRESHOLD_DAYS on the backend -
// only used here to decide which row gets the "en retard" styling, never
// to recompute staleSourceCount itself (that count always comes from the
// API response, never re-derived client-side).
const STALE_THRESHOLD_DAYS = 100;

// C1.6: the 4 breakdown dimensions GET /api/analytics/financing-trends?groupBy=
// accepts - "" (omitted) keeps the historical public/private/multilateral shape.
const TREND_GROUP_OPTIONS = [
  { value: "", label: "Type de financement" },
  { value: "sector", label: "Secteur" },
  { value: "instrument", label: "Instrument financier" },
  { value: "country", label: "Pays" },
];

function trendPath(groupBy) {
  return "/api/analytics/financing-trends" + (groupBy ? "?groupBy=" + groupBy : "");
}

function sumTotals(rows) {
  return rows.reduce(
    (acc, row) => {
      acc.public += row.public;
      acc.private += row.private;
      acc.multilateral += row.multilateral;
      acc.grandTotal += row.total;
      return acc;
    },
    { public: 0, private: 0, multilateral: 0, grandTotal: 0 }
  );
}

/** 2026-09-28 : fusion de VisualizationsPage dans DashboardPage (les deux pages répétaient le même bandeau de KPI, le même donut sectoriel, le même graphique de financement et la même carte pays) - un seul hook hero-stats pour toute la page plutôt qu'une copie par ancienne page. */
function useHeroStats() {
  const { API_BASE_URL, authorizedFetch } = useAuth();
  const [state, setState] = useState("loading");
  const [values, setValues] = useState({ countries: 0, sectors: 0, sources: 0 });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/analytics/hero-stats", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }
        setValues({ countries: body.countriesCovered, sectors: body.sectorsTracked, sources: body.activeSources });
        setState("success");
      } catch (error) {
        if (!cancelled) {
          setState("error");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { state, values };
}

/**
 * C1.1: the "fraîcheur des données" tile - GET /api/analytics/data-freshness.
 * Its own small fetch rather than useAnalyticsFetch(), since the response
 * shape ({sources, oldestCollectionAt, staleSourceCount}) doesn't fit that
 * hook's "data.data is an array" empty-detection convention.
 */
function useDataFreshness() {
  const { API_BASE_URL, authorizedFetch } = useAuth();
  const [state, setState] = useState("loading");
  const [freshness, setFreshness] = useState(null);
  const [message, setMessage] = useState("");

  useEffect(() => {
    let cancelled = false;
    setState("loading");
    (async () => {
      let response;
      try {
        response = await authorizedFetch(API_BASE_URL + "/api/analytics/data-freshness", { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setMessage("Impossible de contacter le serveur.");
          setState("error");
        }
        return;
      }
      if (cancelled) {
        return;
      }
      if (!response.ok) {
        setMessage("Une erreur est survenue (" + response.status + ").");
        setState("error");
        return;
      }
      const body = await response.json();
      if (cancelled) {
        return;
      }
      setFreshness(body);
      setState(0 === body.sources.length ? "empty" : "success");
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { state, freshness, message };
}

/** Local hook for /api/analytics/co2-reduction - its {available: false} shape doesn't fit the generic empty/error/success chart-card pattern (it's a single value tile, not a chart), same as analytics.js's loadCo2Reduction(). B3.2: `data` is now a structured object ({reductionMt, latestYear, previousYear, countriesCompared}), never a bare number. */
function useCo2Reduction() {
  const { API_BASE_URL, authorizedFetch } = useAuth();
  const [value, setValue] = useState("…");
  const [note, setNote] = useState("Chargement…");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/analytics/co2-reduction", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("Une erreur est survenue (" + response.status + ").");
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }
        if (!body.available) {
          setValue("-");
          setNote("Donnée non disponible.");
          return;
        }
        const { reductionMt, latestYear, previousYear, countriesCompared } = body.data;
        setValue(reductionMt.toLocaleString("fr-FR", { maximumFractionDigits: 1, signDisplay: "exceptZero" }));
        setNote((reductionMt < 0 ? "Hausse des émissions - " : "") + previousYear + " → " + latestYear + " · " + countriesCompared + " pays comparés");
      } catch (error) {
        if (!cancelled) {
          setValue("-");
          setNote(error.message);
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { value, note };
}

/**
 * "Projets actifs" - GET /api/projects exige un JWT (Project est une donnée
 * de travail interne, jamais PUBLIC_ACCESS, voir ProjectController), donc
 * cet indicateur reste "-" pour un visiteur non connecté plutôt que
 * d'échouer silencieusement en 401. "Actif" = tout projet sauf ceux dont
 * l'issue est "rejected" (voir Project::$decisionOutcome, 2026-09-28 -
 * fusion des anciens stades "financed"/"rejected" en un seul stade
 * "decision" : un projet financé, en "decision" ou "execution", reste un
 * succès du pipeline, pas un stade mort).
 */
function useActiveProjectsCount() {
  const { API_BASE_URL, authorizedFetch, isAuthenticated } = useAuth();
  const [state, setState] = useState("loading");
  const [count, setCount] = useState(null);

  useEffect(() => {
    if (!isAuthenticated()) {
      setState("unauthenticated");
      return undefined;
    }
    let cancelled = false;
    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/projects", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setCount(body.data.filter((project) => "rejected" !== project.decisionOutcome).length);
          setState("success");
        }
      } catch (error) {
        if (!cancelled) {
          setState("error");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL, authorizedFetch, isAuthenticated]);

  return { state, count };
}

export default function DashboardPage() {
  const { t } = useI18n();
  const { isAuthenticated } = useAuth();
  const heroStats = useHeroStats();
  const freshness = useDataFreshness();
  const co2 = useCo2Reduction();
  const activeProjects = useActiveProjectsCount();
  const [showAdvanced, setShowAdvanced] = useState(false);

  const financing = useAnalyticsFetch("/api/analytics/financing-trends", { emptyWhen: (body) => 0 === body.data.length });
  const sectors = useAnalyticsFetch("/api/analytics/sector-distribution", { emptyWhen: (body) => 0 === body.data.length });

  // C1.6: the "Financement par année" card's own ventilation selector -
  // deliberately a separate fetch from `financing` above (which stays
  // fixed to the default, ungrouped shape: the KPI band and the type
  // donut both depend on it keeping that exact shape).
  const [trendGroupBy, setTrendGroupBy] = useState("");
  const trends = useAnalyticsFetch(trendPath(trendGroupBy), { emptyWhen: (body) => 0 === body.data.length });

  // C1.5: bailleur -> pays -> secteur Sankey - its own fetch, {nodes, links}
  // rather than {data: [...]}, so it can't reuse `financing`/`trends` above.
  const flows = useAnalyticsFetch("/api/analytics/funding-flows", { emptyWhen: (body) => 0 === body.links.length });

  const financingTotals = useMemo(() => (financing.data ? sumTotals(financing.data.data) : null), [financing.data]);
  // C1.2: years for the CountryMap layer selector's "Année" filter - reuses
  // financing-trends' own periods instead of a second endpoint.
  const availableYears = useMemo(() => (financing.data ? financing.data.data.map((row) => row.period) : []), [financing.data]);

  // A3.2: live KPI snapshot pushed by the backend's mercure-publisher over
  // Mercure/SSE (A3.1) - when connected, its most recent snapshot overrides
  // the funding-total tile without a page reload. Falls back to the
  // financing-trends grand total above whenever no snapshot has arrived yet
  // (hub unreachable, still connecting, or feature not deployed in this
  // environment) - the KPI band never depends on Mercure to render.
  const mercureKpis = useMercureKpis();
  const fundingTotalUsd = mercureKpis.snapshot ? mercureKpis.snapshot.fundingTotalUsd : financingTotals ? financingTotals.grandTotal : null;
  // B3.2: same live-override pattern as fundingTotalUsd above - null/undefined
  // (getCo2Reduction() had nothing to report) leaves the REST-fetched co2.value
  // in place instead of showing a live badge for a value that isn't actually live.
  const co2ReductionMt =
    mercureKpis.snapshot && null !== mercureKpis.snapshot.co2ReductionMt && undefined !== mercureKpis.snapshot.co2ReductionMt ? mercureKpis.snapshot.co2ReductionMt : null;

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <ChartBarIcon className="h-3 w-3" /> {t("dashboardPage.pill", "Tableau de bord")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("dashboardPage.title", "Vue d'ensemble")}</h1>
          <p className="max-w-[560px] text-base text-white/80">
            {t("dashboardPage.subtitle", "L'essentiel du financement climatique africain et de sa fraîcheur en un coup d'œil - avec les tendances, les flux et les autres indicateurs à portée d'un clic.")}
          </p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        {/* KPI band */}
        <section className="relative z-20 -mt-10 mb-8">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <NevKpi
              label={t("dashboardPage.kpiTotalFunding", "Financement total")}
              value={null === fundingTotalUsd ? undefined : formatCompactUsd(fundingTotalUsd)}
              state={null === fundingTotalUsd ? "loading" : "success"}
              liveBadge={mercureKpis.connected}
              liveLabel="Direct"
              className="border-t-4 border-t-primary"
            />
            <NevKpi
              label={t("dashboardPage.kpiCountries", "Pays couverts")}
              value={formatCount(mercureKpis.snapshot ? mercureKpis.snapshot.countriesCovered : heroStats.values.countries)}
              state={heroStats.state}
              className="border-t-4 border-t-primary"
            />
            <NevKpi
              label={t("dashboardPage.kpiSectors", "Secteurs suivis")}
              value={formatCount(heroStats.values.sectors)}
              state={heroStats.state}
              className="border-t-4 border-t-primary"
            />
            <NevKpi
              label={t("dashboardPage.kpiSources", "Sources actives")}
              value={formatCount(heroStats.values.sources)}
              state={heroStats.state}
              className="border-t-4 border-t-primary"
            />
          </div>
        </section>

        {/* Data freshness (C1.1) + sector distribution - le coeur "coup d'oeil" de la page, toujours visible */}
        <section className="mb-8">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <NevChartCard
              title={t("dashboardPage.freshnessTitle", "Fraîcheur des données")}
              subtitle={t("dashboardPage.freshnessSubtitle", "Dernière collecte connue, par source")}
              sourceLabel={DATA_SOURCE_LABEL}
              periodLabel={DATA_PERIOD_LABEL}
            >
              <ChartStateBox state={freshness.state} message={freshness.message}>
                {freshness.freshness && (
                  <div>
                    {freshness.freshness.staleSourceCount > 0 && (
                      <p className="mb-3 rounded-md bg-status-demo-bg px-3 py-2 text-xs font-semibold text-warning">
                        {t("dashboardPage.staleSources", "{count} source(s) sans nouvelle collecte depuis plus de 100 jours").replace(
                          "{count}",
                          freshness.freshness.staleSourceCount
                        )}
                      </p>
                    )}
                    <ul className="divide-y divide-stroke">
                      {freshness.freshness.sources.map((source) => (
                        <li key={source.name} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                          <span className="flex items-center gap-2 text-dark-4">
                            <span className="h-6 w-6 shrink-0 text-primary">
                              <DatabaseIcon />
                            </span>
                            {source.name}
                          </span>
                          <span
                            className={
                              "shrink-0 text-right text-xs font-semibold tabular-nums " +
                              (source.daysSinceCollection > STALE_THRESHOLD_DAYS ? "text-warning" : "text-body-color")
                            }
                          >
                            {source.lastCollectedAt} · {t("dashboardPage.daysAgo", "il y a {n} j").replace("{n}", source.daysSinceCollection)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </ChartStateBox>
            </NevChartCard>

            <NevChartCard title={t("dashboardPage.bySector", "Répartition par secteur")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
              <ChartStateBox state={sectors.state} message={sectors.message} onRetry={sectors.reload}>
                {sectors.data && <SectorDonutChart rows={sectors.data.data} />}
              </ChartStateBox>
              {/* C2.5: this platform's other secteur-scoped tool - estimating
                  what a given secteur/amount could translate to in impact. */}
              <a href="impact-calculator.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
                {t("vizPage.seeImpactCalculator", "Estimer l'impact d'un investissement par secteur →")}
              </a>
            </NevChartCard>
          </div>
        </section>

        {/* Country map */}
        <section className="mb-8">
          <NevChartCard title={t("dashboardPage.byCountry", "Financement par pays (Afrique)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
            <FilterableCountryMap availableYears={availableYears} />
            {/* C2.6/C2.8: this map shows funding volume - the country profile
                page complements it with alignment/risk scores per country. */}
            <a href="country-profile.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
              {t("vizPage.seeCountryProfile", "Consulter l'alignement CDN et le risque d'investissement d'un pays →")}
            </a>
          </NevChartCard>
        </section>

        {/* 2026-09-28 : ancien contenu de VisualizationsPage - replié par
            défaut pour garder le "coup d'oeil" ci-dessus lisible, mais
            jamais retiré : tendances, flux et indicateurs restent à un clic. */}
        <section className="mb-8">
          <button
            type="button"
            onClick={() => setShowAdvanced((open) => !open)}
            aria-expanded={showAdvanced}
            aria-controls="advanced-analytics"
            className="flex w-full items-center justify-center gap-2 rounded-lg border border-stroke bg-white px-4 py-3 text-sm font-semibold text-primary-dark shadow-xs transition hover:bg-surface-alt"
          >
            {showAdvanced ? t("dashboardPage.hideAdvanced", "Masquer les analyses avancées") : t("dashboardPage.showAdvanced", "Voir les analyses avancées")}
            <ChevronDownIcon className={"h-4 w-4 transition-transform " + (showAdvanced ? "rotate-180" : "")} />
          </button>
        </section>

        {showAdvanced && (
          <div id="advanced-analytics">
            {/* Financing type donut */}
            <section className="mb-8">
              <NevChartCard title={t("vizPage.fundingByType", "Répartition par type de financement")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
                <ChartStateBox state={financing.state} message={financing.message} onRetry={financing.reload}>
                  {financingTotals && <TypeDonutChart totals={financingTotals} />}
                </ChartStateBox>
                {/* C1.7: "type de financement" (bailleur) is one classification axis;
                    "instrument financier" (don/prêt/garantie) is the other - this card's
                    natural next step for a reader exploring how money is structured. */}
                <a href="instruments.html" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-dark hover:underline">
                  {t("vizPage.seeInstruments", "Voir la répartition par instrument financier →")}
                </a>
              </NevChartCard>
            </section>

            {/* Financing by year and type */}
            <section className="mb-8">
              <NevChartCard title={t("vizPage.financingByYear", "Financement par année (USD)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
                <div className="mb-4 max-w-xs">
                  <NevSelect
                    id="trend-groupby"
                    label={t("vizPage.trendGroupBy", "Ventiler par")}
                    value={trendGroupBy}
                    onChange={(event) => setTrendGroupBy(event.target.value)}
                  >
                    {TREND_GROUP_OPTIONS.map((option) => (
                      <option key={option.value} value={option.value}>
                        {t("vizPage.trendGroupBy." + (option.value || "fundingType"), option.label)}
                      </option>
                    ))}
                  </NevSelect>
                </div>
                <ChartStateBox state={trends.state} message={trends.message} onRetry={trends.reload}>
                  {trends.data &&
                    ("" === trendGroupBy ? (
                      <>
                        <FinancingBarChart rows={trends.data.data} />
                        {financingTotals && <FinancingTable rows={trends.data.data} totals={financingTotals} />}
                      </>
                    ) : (
                      <GenericTrendChart rows={trends.data.data} groupBy={trendGroupBy} />
                    ))}
                </ChartStateBox>
                {trends.data && trends.data.movingAverageTotal.length > 0 && (
                  <div className="mt-4 border-t border-stroke pt-3">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-body-color">
                      {t("vizPage.movingAverage", "Moyenne mobile (3 ans, total tous groupes confondus)")}
                    </p>
                    <MovingAverageChart rows={trends.data.movingAverageTotal} />
                    <details className="mt-3">
                      <summary className="cursor-pointer text-xs font-semibold text-primary-dark hover:underline">
                        {t("vizPage.movingAverageDetail", "Voir les valeurs exactes")}
                      </summary>
                      <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-dark-4">
                        {trends.data.movingAverageTotal.map((point) => (
                          <span key={point.period}>
                            {point.period} : <strong className="text-dark">{formatUsd(point.value)}</strong>
                          </span>
                        ))}
                      </div>
                    </details>
                  </div>
                )}
              </NevChartCard>
            </section>

            {/* Funding flows Sankey */}
            <section className="mb-8">
              <NevChartCard title={t("vizPage.fundingFlows", "Flux de financement (bailleurs → pays → secteurs)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
                <ChartStateBox state={flows.state} message={flows.message} onRetry={flows.reload}>
                  {flows.data && <SankeyFlow nodes={flows.data.nodes} links={flows.data.links} />}
                </ChartStateBox>
              </NevChartCard>
            </section>

            {/* Other indicators */}
            <section className="mb-8">
              <h2 className="mb-6 text-xl font-bold text-dark">{t("vizPage.otherIndicators", "Autres indicateurs climatiques")}</h2>
              <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                <NevKpi
                  label={t("vizPage.co2Reduction", "Réduction CO2 (M tonnes/an)")}
                  value={null !== co2ReductionMt ? co2ReductionMt.toLocaleString("fr-FR", { maximumFractionDigits: 1, signDisplay: "exceptZero" }) : co2.value}
                  context={co2.note}
                  state="success"
                  liveBadge={mercureKpis.connected && null !== co2ReductionMt}
                  liveLabel="Direct"
                />
                <NevKpi
                  label={t("vizPage.activeProjects", "Projets actifs")}
                  value={"success" === activeProjects.state ? formatCount(activeProjects.count) : undefined}
                  state={"success" === activeProjects.state ? "success" : "loading" === activeProjects.state ? "loading" : "empty"}
                  context={
                    "unauthenticated" === activeProjects.state
                      ? t("vizPage.activeProjectsLoginRequired", "Connectez-vous pour voir cet indicateur")
                      : "error" === activeProjects.state
                        ? t("vizPage.activeProjectsError", "Indicateur indisponible")
                        : t("vizPage.activeProjectsContext", "Stades hors « rejeté »")
                  }
                />
                <NevKpi
                  label={t("vizPage.validatedCountries", "Pays avec données validées")}
                  value={formatCount(heroStats.values.countries)}
                  state={heroStats.state}
                  context={t("vizPage.validatedCountriesContext", "Pays avec au moins un financement validé")}
                />
              </div>
            </section>
          </div>
        )}

        {/* C3.1/C3.3/C3.7 : la cible atteint-elle vraiment l'argent ? */}
        <section className="mb-16">
          <h2 className="mb-4 text-lg font-semibold text-dark">{t("dashboardPage.targetingTitle", "L'argent atteint-il la bonne cible ?")}</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="gaps.html" className="block rounded-lg border border-stroke bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:shadow-card">
              <p className="text-sm font-bold text-dark">{t("dashboardPage.linkGaps", "Détecteur d'écarts")}</p>
              <p className="mt-1 text-xs text-body-color">{t("dashboardPage.linkGapsDesc", "Le besoin CDN déclaré dépasse-t-il ce qui a été reçu ?")}</p>
            </a>
            <a href="equity.html" className="block rounded-lg border border-stroke bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:shadow-card">
              <p className="text-sm font-bold text-dark">{t("dashboardPage.linkEquity", "Score d'équité")}</p>
              <p className="mt-1 text-xs text-body-color">{t("dashboardPage.linkEquityDesc", "Classement des pays et indice de concentration (Gini).")}</p>
            </a>
            <a href="transparency.html" className="block rounded-lg border border-stroke bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:shadow-card">
              <p className="text-sm font-bold text-dark">{t("dashboardPage.linkTransparency", "Transparence des bailleurs")}</p>
              <p className="mt-1 text-xs text-body-color">{t("dashboardPage.linkTransparencyDesc", "Complétude, publication IATI et fraîcheur, par bailleur.")}</p>
            </a>
            <a href="recommendations.html" className="block rounded-lg border border-stroke bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:shadow-card">
              <p className="text-sm font-bold text-dark">{t("dashboardPage.linkRecommendations", "Recommandations")}</p>
              <p className="mt-1 text-xs text-body-color">{t("dashboardPage.linkRecommendationsDesc", "Classement priorisé de où allouer le prochain financement.")}</p>
            </a>
          </div>
          <p className="mt-6 text-xs text-dark-5">
            {t(
              "vizPage.disclaimer",
              'Bandeau d\'indicateurs, répartition par type/secteur/pays et financement par année : données réelles (GET /api/analytics/*, A2.5), mises en cache 15 minutes. "Projets actifs" (GET /api/projects, C2.3) nécessite une session connectée.'
            )}
          </p>
        </section>
      </div>
    </>
  );
}
