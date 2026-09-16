import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevModal from "./ui/NevModal";
import NevDataState from "./ui/NevDataState";
import NevBadge from "./ui/NevBadge";
import { formatUsd } from "../data/analyticsConstants";

const STATUS_TONE = { open: "success", upcoming: "info", closed: "neutral" };

/**
 * C2.2 : "encart Financements pertinents" - ici un panneau de détail
 * plutôt qu'un encart de fiche projet dédiée (qui n'existe pas dans le
 * périmètre C1-C3 : la fiche projet, c'est cette carte du Kanban C2.3).
 * Ouvert au clic sur une carte de ProjectPipelinePage.
 */
export default function ProjectDetailModal({ project, onClose }) {
  const { authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const [state, setState] = useState("loading");
  const [matches, setMatches] = useState([]);

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

  return (
    <NevModal open={!!project} onClose={onClose} titleId="project-detail-title" maxWidth="max-w-lg">
      {project && (
        <>
          <h2 id="project-detail-title" className="mb-1 text-lg font-bold text-dark">
            {project.title}
          </h2>
          <p className="mb-4 text-sm text-body-color">
            {project.country} · {project.sector} · {formatUsd(project.amountSought)} · {project.promoter}
          </p>

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
