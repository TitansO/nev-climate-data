import { useEffect, useMemo, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import useMercureKpis from "../hooks/useMercureKpis";
import ChartStateBox from "../components/charts/ChartStateBox";
import TypeDonutChart from "../components/charts/TypeDonutChart";
import SectorDonutChart from "../components/charts/SectorDonutChart";
import FinancingBarChart from "../components/charts/FinancingBarChart";
import FinancingTable from "../components/charts/FinancingTable";
import GenericTrendChart from "../components/charts/GenericTrendChart";
import SankeyFlow from "../components/charts/SankeyFlow";
import FilterableCountryMap from "../components/charts/FilterableCountryMap";
import { formatCompactUsd, formatUsd, formatCount } from "../data/analyticsConstants";
import NevKpi from "../components/ui/NevKpi";
import NevChartCard from "../components/ui/NevChartCard";
import NevSelect from "../components/ui/NevSelect";
import { ChartBarIcon } from "../components/ui/icons";

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

const DATA_SOURCE_LABEL = "Source : API NEV Climate Data (GET /api/analytics/*)";
const DATA_PERIOD_LABEL = "Mise à jour toutes les 15 minutes";

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

/** Small local hook, matches loadKpiHeroStats(): populates 3 of the 4 KPI tiles (countries/sectors/sources), each falling back to "-" independently on error - no retry UI, same as the original. */
function useKpiHeroStats() {
  const { API_BASE_URL } = useAuth();
  const [stats, setStats] = useState({ countries: "…", sectors: "…", sources: "…" });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/analytics/hero-stats", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setStats({ countries: formatCount(body.countriesCovered), sectors: formatCount(body.sectorsTracked), sources: formatCount(body.activeSources) });
        }
      } catch (error) {
        if (!cancelled) {
          setStats({ countries: "-", sectors: "-", sources: "-" });
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return stats;
}

/** Local hook for /api/analytics/co2-reduction - its {available: false} shape doesn't fit the generic empty/error/success chart-card pattern (it's a single value tile, not a chart), same as analytics.js's loadCo2Reduction(). B3.2: `data` is now a structured object ({reductionMt, latestYear, previousYear, countriesCompared}), never a bare number. */
function useCo2Reduction() {
  const { API_BASE_URL } = useAuth();
  const [value, setValue] = useState("…");
  const [note, setNote] = useState("Chargement…");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/analytics/co2-reduction", { headers: { Accept: "application/json" } });
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
        setNote(
          (reductionMt < 0 ? "Hausse des émissions - " : "") +
            previousYear + " → " + latestYear + " · " + countriesCompared + " pays comparés"
        );
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

export default function VisualizationsPage() {
  const { t } = useI18n();
  const heroKpis = useKpiHeroStats();
  const co2 = useCo2Reduction();

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
  // financing-trends' own periods (already fetched above) instead of a
  // second endpoint just to list which years have data.
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
  const co2ReductionMt = mercureKpis.snapshot && null !== mercureKpis.snapshot.co2ReductionMt && undefined !== mercureKpis.snapshot.co2ReductionMt
    ? mercureKpis.snapshot.co2ReductionMt
    : null;

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <ChartBarIcon className="h-3 w-3" /> {t("vizPage.pill", "Visualisations")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("vizPage.title", "Visualisations et tendances")}</h1>
          <p className="max-w-[520px] text-base text-white/80">{t("vizPage.subtitle", "Analysez l'évolution du financement climatique et sa répartition par secteur et par région.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        {/* KPI band */}
        <section className="relative z-20 -mt-10 mb-8">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <NevKpi
              label={t("vizPage.kpiTotalFunding", "Financement total")}
              value={null === fundingTotalUsd ? undefined : formatCompactUsd(fundingTotalUsd)}
              state={null === fundingTotalUsd ? "loading" : "success"}
              liveBadge={mercureKpis.connected}
              liveLabel="Direct"
              className="border-t-4 border-t-primary"
            />
            <NevKpi
              label={t("vizPage.kpiCountries", "Pays couverts")}
              value={mercureKpis.snapshot ? formatCount(mercureKpis.snapshot.countriesCovered) : heroKpis.countries}
              state="success"
              className="border-t-4 border-t-primary"
            />
            <NevKpi label={t("vizPage.kpiSectors", "Secteurs suivis")} value={heroKpis.sectors} state="success" className="border-t-4 border-t-primary" />
            <NevKpi label={t("vizPage.kpiSources", "Sources actives")} value={heroKpis.sources} state="success" className="border-t-4 border-t-primary" />
          </div>
        </section>

        {/* Financing type donut + sector distribution */}
        <section className="mb-8">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
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
            <NevChartCard title={t("vizPage.bySector", "Répartition par secteur")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
              <ChartStateBox state={sectors.state} message={sectors.message} onRetry={sectors.reload}>
                {sectors.data && <SectorDonutChart rows={sectors.data.data} />}
              </ChartStateBox>
            </NevChartCard>
          </div>
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
              {trends.data && (
                "" === trendGroupBy ? (
                  <>
                    <FinancingBarChart rows={trends.data.data} />
                    {financingTotals && <FinancingTable rows={trends.data.data} totals={financingTotals} />}
                  </>
                ) : (
                  <GenericTrendChart rows={trends.data.data} groupBy={trendGroupBy} />
                )
              )}
            </ChartStateBox>
            {trends.data && trends.data.movingAverageTotal.length > 0 && (
              <div className="mt-4 border-t border-stroke pt-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-body-color">
                  {t("vizPage.movingAverage", "Moyenne mobile (3 ans, total tous groupes confondus)")}
                </p>
                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-dark-4">
                  {trends.data.movingAverageTotal.map((point) => (
                    <span key={point.period}>
                      {point.period} : <strong className="text-dark">{formatUsd(point.value)}</strong>
                    </span>
                  ))}
                </div>
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

        {/* Country map */}
        <section className="mb-16">
          <NevChartCard title={t("vizPage.byCountry", "Financement par pays (Afrique)")} sourceLabel={DATA_SOURCE_LABEL} periodLabel={DATA_PERIOD_LABEL}>
            <FilterableCountryMap availableYears={availableYears} />
          </NevChartCard>
        </section>

        {/* Other indicators */}
        <section className="pb-20">
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
            <NevKpi label={t("vizPage.activeProjects", "Projets actifs")} state="empty" context={t("vizPage.comingIndicator", "Indicateur à venir (Volet B)")} />
            <NevKpi label={t("vizPage.validatedCountries", "Pays avec données validées")} state="empty" context={t("vizPage.comingIndicator", "Indicateur à venir (Volet B)")} />
          </div>
          <p className="mt-6 text-xs text-dark-5">
            {t(
              "vizPage.disclaimer",
              'Bandeau d\'indicateurs, répartition par type/secteur/pays et financement par année : données réelles (GET /api/analytics/*, A2.5), mises en cache 15 minutes. "Projets actifs" et "Pays avec données validées" : hors périmètre A2.5/A2.6, restent en indicateur à venir.'
            )}
          </p>
        </section>
      </div>
    </>
  );
}
