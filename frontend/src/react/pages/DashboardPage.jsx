import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import ChartStateBox from "../components/charts/ChartStateBox";
import SectorDonutChart from "../components/charts/SectorDonutChart";
import FinancingBarChart from "../components/charts/FinancingBarChart";
import FilterableCountryMap from "../components/charts/FilterableCountryMap";
import { formatCompactUsd, formatCount } from "../data/analyticsConstants";
import NevKpi from "../components/ui/NevKpi";
import NevChartCard from "../components/ui/NevChartCard";
import { ChartBarIcon, DatabaseIcon } from "../components/ui/icons";

const DATA_SOURCE_LABEL = "Source : API NEV Climate Data (GET /api/analytics/*)";
const DATA_PERIOD_LABEL = "Mise à jour toutes les 15 minutes";

// C1.1: mirrors AnalyticsService::STALE_THRESHOLD_DAYS on the backend -
// only used here to decide which row gets the "en retard" styling, never
// to recompute staleSourceCount itself (that count always comes from the
// API response, never re-derived client-side).
const STALE_THRESHOLD_DAYS = 100;

function sumTotals(rows) {
  return rows.reduce((acc, row) => acc + row.total, 0);
}

/** Same hero-stats fetch as IndexPage/VisualizationsPage (A2.7) - the Dashboard's own copy, since C1.1 consolidates the two pages' KPI bands into one, not a shared hook (each page still owns its loading/error copy). */
function useHeroStats() {
  const { API_BASE_URL, authorizedFetch } = useAuth();
  const [state, setState] = useState("loading");
  const [values, setValues] = useState({ countries: 0, sectors: 0, funding: 0, sources: 0 });

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
        setValues({ countries: body.countriesCovered, sectors: body.sectorsTracked, funding: body.fundingRecords, sources: body.activeSources });
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

export default function DashboardPage() {
  const { t } = useI18n();
  const { isAuthenticated } = useAuth();
  const heroStats = useHeroStats();
  const freshness = useDataFreshness();

  const financing = useAnalyticsFetch("/api/analytics/financing-trends", { emptyWhen: (body) => 0 === body.data.length });
  const sectors = useAnalyticsFetch("/api/analytics/sector-distribution", { emptyWhen: (body) => 0 === body.data.length });

  const fundingTotalUsd = useMemo(() => (financing.data ? sumTotals(financing.data.data) : null), [financing.data]);
  // C1.2: years for the CountryMap layer selector's "Année" filter - reuses
  // financing-trends' own periods instead of a second endpoint.
  const availableYears = useMemo(() => (financing.data ? financing.data.data.map((row) => row.period) : []), [financing.data]);

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
            {t("dashboardPage.subtitle", "L'essentiel du financement climatique africain et de la fraîcheur des données, en un coup d'œil.")}
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
              className="border-t-4 border-t-primary"
            />
            <NevKpi
              label={t("dashboardPage.kpiCountries", "Pays couverts")}
              value={formatCount(heroStats.values.countries)}
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

        {/* Data freshness (C1.1) + sector distribution */}
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
            </NevChartCard>
          </div>
        </section>

        {/* Financing trend */}
        <section className="mb-8">
          <NevChartCard title={t("dashboardPage.financingByYear", "Financement par année et type (USD)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
            <ChartStateBox state={financing.state} message={financing.message} onRetry={financing.reload}>
              {financing.data && <FinancingBarChart rows={financing.data.data} />}
            </ChartStateBox>
          </NevChartCard>
        </section>

        {/* Country map */}
        <section className="mb-8">
          <NevChartCard title={t("dashboardPage.byCountry", "Financement par pays (Afrique)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
            <FilterableCountryMap availableYears={availableYears} />
          </NevChartCard>
        </section>

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
        </section>
      </div>
    </>
  );
}
