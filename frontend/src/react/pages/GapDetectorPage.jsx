import { useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import NevBadge from "../components/ui/NevBadge";
import NevTable from "../components/ui/NevTable";
import NevDataState from "../components/ui/NevDataState";
import { formatUsd } from "../data/analyticsConstants";
import { BoltIcon } from "../components/ui/icons";

const SEVERITY_TONE = { critical: "danger", moderate: "warning", low: "success" };
const SEVERITY_LABEL = { critical: "Critique", moderate: "Modéré", low: "Faible" };

/**
 * C3.1 : "Gap Detector" - GET /api/analytics/gaps?by=. Frontend jamais
 * construit lors du premier passage sur C3 (le plan original demandait
 * "carte des écarts + tableau trié", App\Service\GapDetectionService
 * existait déjà sans aucune UI). Tableau trié livré ; la carte ne l'est
 * pas - à ce jour (voir NdcTargetFixtures) seuls 2 pays sur 54 ont un
 * besoin CDN chiffré, une carte choroplèthe serait presque entièrement
 * vide et donc trompeuse ; une barre de couverture par ligne du tableau
 * porte la même information sans fabriquer une vue cartographique creuse.
 */
export default function GapDetectorPage() {
  const { t } = useI18n();
  const { isAuthenticated } = useAuth();
  const [by, setBy] = useState("country");
  const { state, data, message, reload } = useAnalyticsFetch("/api/analytics/gaps?by=" + by, { emptyWhen: (body) => 0 === body.data.length });

  const rows = data ? data.data : [];

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
            <BoltIcon className="h-3 w-3" /> {t("gaps.pill", "Détecteur d'écarts")}
          </span>
          <h1 className="text-2xl font-bold text-dark">{t("gaps.title", "Le besoin dépasse-t-il le financement reçu ?")}</h1>
          <p className="mt-1 max-w-2xl text-sm text-body-color">
            {t("gaps.subtitle", "Écart entre le besoin CDN déclaré et le montant réellement reçu - restreint aux pays/secteurs disposant d'un besoin chiffré publiquement.")}
          </p>
        </div>
        <NevSelect id="gaps-by" label={t("gaps.groupBy", "Regrouper par")} value={by} onChange={(event) => setBy(event.target.value)} wrapperClassName="min-w-[220px]">
          <option value="country">{t("gaps.byCountry", "Pays")}</option>
          <option value="sector">{t("gaps.bySector", "Secteur")}</option>
          <option value="country-sector">{t("gaps.byCountrySector", "Pays × secteur")}</option>
        </NevSelect>
      </div>

      <NevDataState
        state={state}
        loadingText={t("hero.loading", "Chargement…")}
        emptyText={t("gaps.empty", "Aucun besoin CDN chiffré publiquement pour ce regroupement.")}
        errorText={message}
        onRetry={reload}
      >
        <NevCard padding="sm">
          <NevTable
            bordered={false}
            rowKey={(row) => (row.countryIsoCode || "") + "|" + (row.sector || "")}
            columns={[
              {
                key: "subject",
                label: t("gaps.subject", "Pays / secteur"),
                render: (row) => (
                  <span className="font-semibold text-dark">
                    {row.country || "—"}
                    {row.sector ? <span className="font-normal text-body-color"> · {row.sector}</span> : null}
                  </span>
                ),
              },
              { key: "declaredNeedUsd", label: t("gaps.need", "Besoin déclaré"), align: "right", render: (row) => formatUsd(row.declaredNeedUsd) },
              { key: "receivedUsd", label: t("gaps.received", "Reçu"), align: "right", render: (row) => formatUsd(row.receivedUsd) },
              { key: "gapUsd", label: t("gaps.gap", "Écart"), align: "right", render: (row) => formatUsd(row.gapUsd) },
              {
                key: "coverageRatio",
                label: t("gaps.coverage", "Couverture"),
                align: "right",
                render: (row) => (
                  <div className="flex items-center justify-end gap-2">
                    <div className="h-1.5 w-20 overflow-hidden rounded-full bg-gray-2">
                      <div className="h-full rounded-full bg-primary" style={{ width: Math.min(100, Math.round(100 * row.coverageRatio)) + "%" }} />
                    </div>
                    <span className="w-10 tabular-nums">{Math.round(100 * row.coverageRatio)}%</span>
                  </div>
                ),
              },
              { key: "severity", label: t("gaps.severity", "Sévérité"), render: (row) => <NevBadge tone={SEVERITY_TONE[row.severity] || "neutral"}>{SEVERITY_LABEL[row.severity] || row.severity}</NevBadge> },
            ]}
            rows={rows}
          />
        </NevCard>
      </NevDataState>
    </div>
  );
}
