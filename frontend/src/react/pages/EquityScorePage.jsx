import { useI18n } from "../providers/I18nProvider";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevTable from "../components/ui/NevTable";
import NevDataState from "../components/ui/NevDataState";
import { ChartBarIcon } from "../components/ui/icons";

const GRADE_TONE = { A: "success", B: "success", C: "warning", D: "warning", E: "danger" };

/**
 * C3.3 : "Equity Score" - GET /api/analytics/equity. Frontend jamais
 * construit lors du premier passage sur C3 (le plan original demandait
 * "classement + courbe de concentration", App\Service\EquityScoreService
 * existait déjà sans aucune UI). `giniIndex` rendu comme une jauge simple
 * (0 = répartition égale, 1 = concentrée sur un seul pays) plutôt qu'une
 * vraie courbe de Lorenz interactive - un seul nombre déjà calculé côté
 * API, une visualisation complète demanderait de reconstruire toute la
 * distribution ordonnée côté frontend pour un gain de lisibilité marginal.
 */
export default function EquityScorePage() {
  const { t } = useI18n();
  const { state, data, message, reload } = useAnalyticsFetch("/api/analytics/equity", { emptyWhen: (body) => 0 === body.ranking.length });

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8">
        <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
          <ChartBarIcon className="h-3 w-3" /> {t("equity.pill", "Équité")}
        </span>
        <h1 className="text-2xl font-bold text-dark">{t("equity.title", "Les pays les plus vulnérables reçoivent-ils une part juste ?")}</h1>
        <p className="mt-1 max-w-2xl text-sm text-body-color">
          {t(
            "equity.subtitle",
            "Financement par habitant vs vulnérabilité (40%), part reçue vs besoin CDN (30%), financement par tonne de CO2 inversé (30%) - restreint aux pays disposant d'une population et d'un indice de vulnérabilité renseignés."
          )}
        </p>
      </div>

      <NevDataState
        state={state}
        loadingText={t("hero.loading", "Chargement…")}
        emptyText={t("equity.empty", "Aucun pays ne dispose encore de population/indice de vulnérabilité renseignés.")}
        errorText={message}
        onRetry={reload}
      >
        {data && (
          <>
            <NevCard padding="lg" className="mb-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-body-color">{t("equity.gini", "Indice de concentration (Gini)")}</p>
                  <p className="mt-1 max-w-lg text-sm text-body-color">{t("equity.giniExplain", "Calculé sur TOUS les pays réellement financés (pas seulement ceux classés ci-dessous) - 0 = répartition parfaitement égale, 1 = un seul pays concentre tout.")}</p>
                </div>
                <p className="text-3xl font-extrabold tabular-nums text-dark">{data.giniIndex.toFixed(2)}</p>
              </div>
              <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-2">
                <div className="h-full rounded-full bg-primary" style={{ width: Math.round(100 * data.giniIndex) + "%" }} />
              </div>
            </NevCard>

            <NevCard padding="sm">
              <NevTable
                bordered={false}
                rowKey={(row) => row.countryIsoCode}
                columns={[
                  { key: "rank", label: "#", render: (row) => data.ranking.indexOf(row) + 1 },
                  { key: "country", label: t("equity.country", "Pays"), render: (row) => <span className="font-semibold text-dark">{row.country}</span> },
                  {
                    key: "value",
                    label: t("equity.score", "Score d'équité"),
                    align: "right",
                    render: (row) => (
                      <div className="flex items-center justify-end gap-2">
                        <div className="h-1.5 w-24 overflow-hidden rounded-full bg-gray-2">
                          <div className="h-full rounded-full bg-primary" style={{ width: Math.round(row.value) + "%" }} />
                        </div>
                        <span className="w-10 tabular-nums">{Math.round(row.value)}</span>
                      </div>
                    ),
                  },
                  { key: "grade", label: t("equity.grade", "Grade"), render: (row) => <NevBadge tone={GRADE_TONE[row.grade] || "neutral"}>{row.grade}</NevBadge> },
                ]}
                rows={data.ranking}
              />
            </NevCard>
          </>
        )}
      </NevDataState>
    </div>
  );
}
