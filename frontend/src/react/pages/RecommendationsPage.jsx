import { useI18n } from "../providers/I18nProvider";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import NevCard from "../components/ui/NevCard";
import NevDataState from "../components/ui/NevDataState";
import { SparklesIcon } from "../components/ui/icons";

const SIGNAL_LABELS = {
  needGap: "Écart de couverture CDN",
  equityGap: "Écart d'équité",
  ndcGap: "Écart d'alignement CDN",
  investmentSafety: "Sécurité d'investissement",
};

/**
 * C3.7 : "Recommendation Engine" - GET /api/recommendations. Frontend
 * jamais construit lors du premier passage sur C3 (voir le plan original,
 * "Front : RecommendationsPage.jsx (liste priorisée + pourquoi)") - le
 * backend existait déjà, seule cette page manquait. Réponse déjà triée par
 * priorityScore décroissant côté API, jamais retriée ici.
 */
export default function RecommendationsPage() {
  const { t } = useI18n();
  const { state, data, message, reload } = useAnalyticsFetch("/api/recommendations", { emptyWhen: (body) => 0 === body.length });

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8">
        <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
          <SparklesIcon className="h-3 w-3" /> {t("recommendations.pill", "Recommandations")}
        </span>
        <h1 className="text-2xl font-bold text-dark">{t("recommendations.title", "Où allouer le prochain financement ?")}</h1>
        <p className="mt-1 max-w-2xl text-sm text-body-color">
          {t(
            "recommendations.subtitle",
            "Classement transparent, sans apprentissage automatique : moyenne pondérée à parts égales de l'écart de couverture CDN (C3.1), l'écart d'équité (C3.3), l'écart d'alignement CDN (C2.6) et la sécurité d'investissement (C2.8), sur les seuls signaux réellement disponibles par pays."
          )}
        </p>
      </div>

      <NevDataState
        state={state}
        loadingText={t("hero.loading", "Chargement…")}
        emptyText={t("recommendations.empty", "Aucun pays ne dispose actuellement d'assez de signaux réels pour être classé.")}
        errorText={message}
        onRetry={reload}
      >
        {data && (
          <ol className="space-y-3">
            {data.map((row, index) => (
              <li key={row.countryIsoCode}>
                <NevCard padding="lg">
                  <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-alt text-sm font-extrabold text-primary-dark">{index + 1}</span>
                      <div>
                        <h2 className="text-base font-bold text-dark">
                          {row.country} <span className="font-normal text-body-color">({row.countryIsoCode})</span>
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm text-body-color">{row.justification}</p>
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="text-2xl font-extrabold tabular-nums text-primary-dark">{Math.round(100 * row.priorityScore)}%</p>
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-body-color">{t("recommendations.priorityScore", "score de priorité")}</p>
                    </div>
                  </div>

                  <div className="mt-4 h-2 w-full overflow-hidden rounded-full bg-gray-2">
                    <div className="h-full rounded-full bg-primary" style={{ width: Math.round(100 * row.priorityScore) + "%" }} />
                  </div>

                  <div className="mt-4 flex flex-wrap gap-2">
                    {Object.entries(row.signals)
                      .filter(([, value]) => null !== value)
                      .map(([key, value]) => (
                        <span key={key} className="inline-flex items-center gap-1.5 rounded-md bg-gray-1 px-2.5 py-1 text-xs font-medium text-dark-4">
                          {SIGNAL_LABELS[key] || key} : <span className="font-bold tabular-nums text-dark">{Math.round(100 * value)}%</span>
                        </span>
                      ))}
                  </div>
                </NevCard>
              </li>
            ))}
          </ol>
        )}
      </NevDataState>
    </div>
  );
}
