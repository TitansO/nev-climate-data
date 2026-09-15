import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevKpi from "../components/ui/NevKpi";
import NevChartCard from "../components/ui/NevChartCard";
import NevTable from "../components/ui/NevTable";
import NevDataState from "../components/ui/NevDataState";
import SectorDonutChart from "../components/charts/SectorDonutChart";
import { formatCompactUsd, formatUsd, formatCount } from "../data/analyticsConstants";
import { BuildingIcon } from "../components/ui/icons";

const SOURCE_TYPE_LABELS = {
  official_api: "API officielle",
  pdf_report: "Rapport PDF",
  green_access_event: "Évènements applicatifs",
  internal_demo: "Démonstration interne",
};

const RELIABILITY_LABELS = { high: "Élevée", medium: "Moyenne", low: "Faible" };
const RELIABILITY_TONES = { high: "success", medium: "info", low: "warning" };

function sourceIdFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const raw = params.get("sourceId");
  return raw && /^\d+$/.test(raw) ? Number(raw) : null;
}

/**
 * C1.4 : fiche institution (bailleur) - GET /api/institutions/{sourceId}.
 * Route MPA autonome (institution-profile.html?sourceId=...), au même
 * titre que dashboard.html/search.html - lue depuis GET /api/search's
 * propre destination pour un résultat de type "source" (SearchService).
 */
export default function InstitutionProfilePage() {
  const { API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const sourceId = sourceIdFromUrl();

  const [state, setState] = useState(null === sourceId ? "error" : "loading");
  const [profile, setProfile] = useState(null);
  const [message, setMessage] = useState(null === sourceId ? t("institutionPage.missingId", "Aucune institution demandée (paramètre \"sourceId\" manquant dans l'URL).") : "");

  useEffect(() => {
    if (null === sourceId) {
      return;
    }
    let cancelled = false;
    (async () => {
      let response;
      try {
        response = await fetch(API_BASE_URL + "/api/institutions/" + sourceId, { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setState("error");
          setMessage(t("institutionPage.networkError", "Impossible de contacter le serveur."));
        }
        return;
      }
      if (cancelled) {
        return;
      }
      if (404 === response.status) {
        setState("error");
        setMessage(t("institutionPage.notFound", "Institution introuvable."));
        return;
      }
      if (!response.ok) {
        setState("error");
        setMessage(t("institutionPage.genericError", "Une erreur est survenue."));
        return;
      }
      const body = await response.json();
      if (cancelled) {
        return;
      }
      setProfile(body);
      setState("success");
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sourceId]);

  return (
    <div className="container mx-auto px-4 py-10">
      <NevDataState state={state} loadingText={t("institutionPage.loading", "Chargement de la fiche institution…")} errorText={message} emptyText={message}>
        {profile && (
          <>
            <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
              <div>
                <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
                  <BuildingIcon className="h-3 w-3" /> {t("institutionPage.pill", "Fiche institution")}
                </span>
                <h1 className="text-2xl font-bold text-dark">{profile.name}</h1>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <NevBadge tone="neutral">{SOURCE_TYPE_LABELS[profile.type] || profile.type}</NevBadge>
                  <NevBadge tone={RELIABILITY_TONES[profile.reliability] || "neutral"}>
                    {t("institutionPage.reliability", "Fiabilité")} : {RELIABILITY_LABELS[profile.reliability] || profile.reliability}
                  </NevBadge>
                </div>
              </div>
            </div>

            {!profile.portfolio ? (
              <NevCard padding="lg" className="mb-8 text-center text-sm text-body-color">
                {t("institutionPage.noPortfolio", "Cette institution n'a encore aucun financement réel enregistré.")}
              </NevCard>
            ) : (
              <section className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <NevKpi
                  label={t("institutionPage.kpiTotal", "Montant total")}
                  value={formatCompactUsd(profile.portfolio.totalAmountUsd)}
                  state="success"
                  className="border-t-4 border-t-primary"
                />
                <NevKpi
                  label={t("institutionPage.kpiRecords", "Enregistrements")}
                  value={formatCount(profile.portfolio.fundingRecordCount)}
                  state="success"
                  className="border-t-4 border-t-primary"
                />
                <NevKpi
                  label={t("institutionPage.kpiCountries", "Pays financés")}
                  value={formatCount(profile.portfolio.countriesFundedCount)}
                  state="success"
                  className="border-t-4 border-t-primary"
                />
                <NevKpi
                  label={t("institutionPage.kpiSectors", "Secteurs financés")}
                  value={formatCount(profile.portfolio.sectorsFundedCount)}
                  state="success"
                  className="border-t-4 border-t-primary"
                />
              </section>
            )}

            {profile.sectorBreakdown.length > 0 && (
              <section className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <NevChartCard title={t("institutionPage.bySector", "Répartition par secteur")}>
                  <SectorDonutChart rows={profile.sectorBreakdown} />
                </NevChartCard>
                <NevChartCard title={t("institutionPage.byCountry", "Répartition par pays")}>
                  <NevTable
                    bordered={false}
                    rowKey={(row) => row.isoCode}
                    columns={[
                      { key: "country", label: t("institutionPage.country", "Pays") },
                      { key: "amount", label: t("institutionPage.amount", "Montant"), align: "right", render: (row) => formatUsd(row.amount) },
                      { key: "percentage", label: "%", align: "right", render: (row) => row.percentage + "%" },
                    ]}
                    rows={profile.countryBreakdown}
                  />
                </NevChartCard>
              </section>
            )}

            {profile.yearlyTrend.length > 0 && (
              <section className="mb-8">
                <NevChartCard title={t("institutionPage.yearlyTrend", "Évolution annuelle")}>
                  <NevTable
                    bordered={false}
                    rowKey={(row) => row.period}
                    columns={[
                      { key: "period", label: t("institutionPage.year", "Année") },
                      { key: "amount", label: t("institutionPage.amount", "Montant"), align: "right", render: (row) => formatUsd(row.amount) },
                    ]}
                    rows={profile.yearlyTrend}
                  />
                </NevChartCard>
              </section>
            )}

            <NevCard padding="md" className="text-sm text-body-color">
              <strong className="text-dark">{t("institutionPage.transparency", "Transparence")}</strong> :{" "}
              {profile.transparency.lastCollectedAt
                ? t("institutionPage.lastCollected", "dernière collecte réelle le {date} (il y a {n} j)")
                    .replace("{date}", profile.transparency.lastCollectedAt)
                    .replace("{n}", profile.transparency.daysSinceCollection)
                : t("institutionPage.noCollection", "aucune collecte réelle connue pour cette institution.")}
            </NevCard>
          </>
        )}
      </NevDataState>
    </div>
  );
}
