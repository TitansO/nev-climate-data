import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevModal from "./ui/NevModal";
import NevDataState from "./ui/NevDataState";
import NevBadge from "./ui/NevBadge";
import NevScoreGauge from "./ui/NevScoreGauge";
import { formatUsd } from "../data/analyticsConstants";

const STATUS_TONE = { open: "success", upcoming: "info", closed: "neutral" };

const TAXONOMY_LABELS = {
  mitigation: "Atténuation",
  adaptation: "Adaptation",
  both: "Atténuation + adaptation",
  not_relevant: "Non pertinent",
};

/**
 * Charge un score de fiche projet (C2.4/C2.8, tous deux JWT-only, forme
 * partagée ScoreService::toArray()) - factorisé une fois ici plutôt que
 * dupliqué pour chacun des 2 scores affichés par ce panneau.
 */
function useProjectScore(project, path, authorizedFetch, API_BASE_URL) {
  const [state, setState] = useState("loading");
  const [score, setScore] = useState(null);

  useEffect(() => {
    if (!project) {
      return undefined;
    }
    let cancelled = false;
    setState("loading");

    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/projects/" + project.id + path, { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setScore(body);
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
  }, [project, path, authorizedFetch, API_BASE_URL]);

  return { state, score };
}

/**
 * C2.2 : "encart Financements pertinents" - ici un panneau de détail
 * plutôt qu'un encart de fiche projet dédiée (qui n'existe pas dans le
 * périmètre C1-C3 : la fiche projet, c'est cette carte du Kanban C2.3).
 * Ouvert au clic sur une carte de ProjectPipelinePage. Affiche aussi les
 * scores C2.4 (bancabilité) et C2.8 (risque investissement), et la
 * classification C2.7 - le volet frontend de ces 3 tâches, prévu par le
 * plan initial mais jamais construit lors du premier passage sur C2.
 */
export default function ProjectDetailModal({ project, onClose, onProjectUpdated }) {
  const { authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const [state, setState] = useState("loading");
  const [matches, setMatches] = useState([]);
  const [classifying, setClassifying] = useState(false);
  const [classifyError, setClassifyError] = useState("");

  const bankability = useProjectScore(project, "/scores/bankability", authorizedFetch, API_BASE_URL);
  const investmentRisk = useProjectScore(project, "/scores/investment-risk", authorizedFetch, API_BASE_URL);

  useEffect(() => {
    if (!project) {
      return undefined;
    }
    let cancelled = false;
    setState("loading");

    (async () => {
      try {
        const response = await authorizedFetch(API_BASE_URL + "/api/projects/" + project.id + "/matches", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("http " + response.status);
        }
        const body = await response.json();
        if (!cancelled) {
          setMatches(body.data);
          setState(0 === body.data.length ? "empty" : "success");
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
  }, [project, authorizedFetch, API_BASE_URL]);

  async function classifyProject() {
    if (!project) {
      return;
    }
    setClassifying(true);
    setClassifyError("");
    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/projects/" + project.id + "/classify", {
        method: "PATCH",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) {
        throw new Error("http " + response.status);
      }
      const updated = await response.json();
      onProjectUpdated?.(updated);
    } catch (error) {
      setClassifyError(t("projectPipeline.classifyError", "Classification indisponible (service IA injoignable, ou clé Gemini non configurée)."));
    } finally {
      setClassifying(false);
    }
  }

  return (
    <NevModal open={!!project} onClose={onClose} titleId="project-detail-title" maxWidth="max-w-2xl">
      {project && (
        <>
          <h2 id="project-detail-title" className="mb-1 text-lg font-bold text-dark">
            {project.title}
          </h2>
          <p className="mb-2 text-sm text-body-color">
            {project.country} · {project.sector} · {formatUsd(project.amountSought)} · {project.promoter}
          </p>

          <div className="mb-4 flex flex-wrap items-center gap-2">
            {project.taxonomyClass ? (
              <NevBadge tone="info">{TAXONOMY_LABELS[project.taxonomyClass] || project.taxonomyClass}</NevBadge>
            ) : (
              <button
                type="button"
                onClick={classifyProject}
                disabled={classifying}
                className="rounded-md border border-stroke px-2.5 py-1 text-xs font-semibold text-primary-dark hover:bg-surface-alt disabled:opacity-60"
              >
                {classifying ? t("projectPipeline.classifying", "Classification…") : t("projectPipeline.classify", "Classer ce projet (IA)")}
              </button>
            )}
            <a
              href={"impact-calculator.html?sector=" + encodeURIComponent(project.sector) + "&amount=" + project.amountSought + "&country=" + encodeURIComponent(project.countryIsoCode)}
              className="text-xs font-semibold text-primary-dark hover:underline"
            >
              {t("projectPipeline.estimateImpact", "Estimer l'impact →")}
            </a>
          </div>
          {classifyError && <p className="mb-4 text-xs text-danger">{classifyError}</p>}

          <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <NevScoreGauge
              label={t("projectPipeline.bankability", "Bancabilité")}
              score={bankability.score}
              state={bankability.state}
              loadingText={t("hero.loading", "Chargement…")}
              errorText={t("projectPipeline.scoreError", "Impossible de calculer ce score.")}
            />
            <NevScoreGauge
              label={t("projectPipeline.investmentRisk", "Risque d'investissement")}
              score={investmentRisk.score}
              state={investmentRisk.state}
              loadingText={t("hero.loading", "Chargement…")}
              errorText={t("projectPipeline.scoreError", "Impossible de calculer ce score.")}
            />
          </div>

          <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-dark-4">{t("projectPipeline.relevantFunding", "Financements pertinents")}</h3>
          <NevDataState
            state={state}
            loadingText={t("hero.loading", "Chargement…")}
            emptyText={t("projectPipeline.noMatches", "Aucune opportunité disponible pour ce projet actuellement.")}
            errorText={t("projectPipeline.matchesError", "Impossible de charger les correspondances.")}
          >
            <ul className="max-h-72 space-y-2 overflow-y-auto">
              {matches.map((match) => (
                <li key={match.opportunityId} className="rounded-md border border-stroke p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="text-sm font-semibold text-dark">{match.opportunityTitle}</p>
                      <p className="text-xs text-body-color">{match.sourceName}</p>
                    </div>
                    <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-bold text-primary">{Math.round(100 * match.score)}%</span>
                  </div>
                  <NevBadge tone={STATUS_TONE[match.opportunityStatus] || "neutral"} className="mt-2">
                    {t("opportunityStatus." + match.opportunityStatus, match.opportunityStatus)}
                  </NevBadge>
                </li>
              ))}
            </ul>
          </NevDataState>

          <div className="mt-5 text-right">
            <button type="button" onClick={onClose} className="text-sm font-semibold text-primary-dark hover:underline">
              {t("aiCopilot.close", "Fermer")}
            </button>
          </div>
        </>
      )}
    </NevModal>
  );
}
