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
import CountryMap from "../components/charts/CountryMap";
import { formatCompactUsd, formatCount } from "../data/analyticsConstants";

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

/** Local hook for /api/analytics/co2-reduction - its {available: false} shape doesn't fit the generic empty/error/success chart-card pattern (it's a single value tile, not a chart), same as analytics.js's loadCo2Reduction(). */
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
        setValue(String(body.data));
        setNote("");
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
  const countries = useAnalyticsFetch("/api/analytics/country-distribution", { emptyWhen: (body) => 0 === body.data.length });

  const financingTotals = useMemo(() => (financing.data ? sumTotals(financing.data.data) : null), [financing.data]);

  // A3.2: live KPI snapshot pushed by the backend's mercure-publisher over
  // Mercure/SSE (A3.1) - when connected, its most recent snapshot overrides
  // the funding-total tile without a page reload. Falls back to the
  // financing-trends grand total above whenever no snapshot has arrived yet
  // (hub unreachable, still connecting, or feature not deployed in this
  // environment) - the KPI band never depends on Mercure to render.
  const mercureKpis = useMercureKpis();
  const fundingTotalUsd = mercureKpis.snapshot ? mercureKpis.snapshot.fundingTotalUsd : financingTotals ? financingTotals.grandTotal : null;

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("vizPage.title", "Visualisations et tendances")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("vizPage.subtitle", "Analysez l'évolution du financement climatique et sa répartition par secteur et par région.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        {/* KPI band */}
        <section className="relative z-20 -mt-10 mb-8">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div className="relative rounded-2xl border border-stroke border-t-4 border-t-primary bg-white p-5 shadow-2">
              <div className="text-2xl font-extrabold text-dark">{null === fundingTotalUsd ? "…" : formatCompactUsd(fundingTotalUsd)}</div>
              <div className="mt-1 text-xs font-semibold uppercase tracking-wide text-dark-5">{t("vizPage.kpiTotalFunding", "Financement total")}</div>
              {mercureKpis.connected && (
                <span className="absolute right-3 top-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-primary" title="Mis à jour en direct via Mercure">
                  <span className="h-1.5 w-1.5 rounded-full bg-primary"></span>
                  Direct
                </span>
              )}
            </div>
            <div className="rounded-2xl border border-stroke border-t-4 border-t-primary bg-white p-5 shadow-2">
              <div className="text-2xl font-extrabold text-dark">{mercureKpis.snapshot ? formatCount(mercureKpis.snapshot.countriesCovered) : heroKpis.countries}</div>
              <div className="mt-1 text-xs font-semibold uppercase tracking-wide text-dark-5">{t("vizPage.kpiCountries", "Pays couverts")}</div>
            </div>
            <div className="rounded-2xl border border-stroke border-t-4 border-t-primary bg-white p-5 shadow-2">
              <div className="text-2xl font-extrabold text-dark">{heroKpis.sectors}</div>
              <div className="mt-1 text-xs font-semibold uppercase tracking-wide text-dark-5">{t("vizPage.kpiSectors", "Secteurs suivis")}</div>
            </div>
            <div className="rounded-2xl border border-stroke border-t-4 border-t-primary bg-white p-5 shadow-2">
              <div className="text-2xl font-extrabold text-dark">{heroKpis.sources}</div>
              <div className="mt-1 text-xs font-semibold uppercase tracking-wide text-dark-5">{t("vizPage.kpiSources", "Sources actives")}</div>
            </div>
          </div>
        </section>

        {/* Financing type donut + sector distribution */}
        <section className="mb-8">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="rounded-2xl border border-stroke bg-white p-5 shadow-2 sm:p-6">
              <h3 className="mb-3 text-lg font-bold text-dark">{t("vizPage.fundingByType", "Répartition par type de financement")}</h3>
              <ChartStateBox state={financing.state} message={financing.message} onRetry={financing.reload}>
                {financingTotals && <TypeDonutChart totals={financingTotals} />}
              </ChartStateBox>
            </div>
            <div className="rounded-2xl border border-stroke bg-white p-5 shadow-2 sm:p-6">
              <h3 className="mb-3 text-lg font-bold text-dark">{t("vizPage.bySector", "Répartition par secteur")}</h3>
              <ChartStateBox state={sectors.state} message={sectors.message} onRetry={sectors.reload}>
                {sectors.data && <SectorDonutChart rows={sectors.data.data} />}
              </ChartStateBox>
            </div>
          </div>
        </section>

        {/* Financing by year and type */}
        <section className="mb-8">
          <div className="rounded-2xl border border-stroke bg-white p-5 shadow-2 sm:p-6">
            <h3 className="mb-3 text-lg font-bold text-dark">{t("vizPage.financingByYear", "Financement par année et type (USD)")}</h3>
            <ChartStateBox state={financing.state} message={financing.message} onRetry={financing.reload}>
              {financing.data && financingTotals && (
                <>
                  <FinancingBarChart rows={financing.data.data} />
                  <FinancingTable rows={financing.data.data} totals={financingTotals} />
                </>
              )}
            </ChartStateBox>
          </div>
        </section>

        {/* Country map */}
        <section className="mb-16">
          <div className="rounded-2xl border border-stroke bg-white p-5 shadow-2 sm:p-6">
            <h3 className="mb-3 text-lg font-bold text-dark">{t("vizPage.byCountry", "Financement par pays (Afrique)")}</h3>
            <ChartStateBox state={countries.state} message={countries.message} onRetry={countries.reload} minHeight={false}>
              {countries.data && <CountryMap rows={countries.data.data} />}
            </ChartStateBox>
          </div>
        </section>

        {/* Other indicators */}
        <section className="pb-20">
          <h2 className="mb-6 text-xl font-bold text-dark">{t("vizPage.otherIndicators", "Autres indicateurs climatiques")}</h2>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <div className="rounded-2xl border border-stroke bg-white p-6 text-center shadow-1">
              <div className="mb-1 text-2xl font-extrabold text-deep-3">{co2.value}</div>
              <div className="text-sm font-medium text-dark-4">{t("vizPage.co2Reduction", "Réduction CO2 (M tonnes/an)")}</div>
              <div className="mt-2 text-xs text-dark-5">{co2.note}</div>
            </div>
            <div className="rounded-2xl border border-stroke bg-white p-6 text-center shadow-1">
              <div className="mb-1 text-2xl font-extrabold text-deep-3">-</div>
              <div className="text-sm font-medium text-dark-4">{t("vizPage.activeProjects", "Projets actifs")}</div>
              <div className="mt-2 text-xs text-dark-5">{t("vizPage.comingIndicator", "Indicateur à venir (Volet B)")}</div>
            </div>
            <div className="rounded-2xl border border-stroke bg-white p-6 text-center shadow-1">
              <div className="mb-1 text-2xl font-extrabold text-deep-3">-</div>
              <div className="text-sm font-medium text-dark-4">{t("vizPage.validatedCountries", "Pays avec données validées")}</div>
              <div className="mt-2 text-xs text-dark-5">{t("vizPage.comingIndicator", "Indicateur à venir (Volet B)")}</div>
            </div>
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
