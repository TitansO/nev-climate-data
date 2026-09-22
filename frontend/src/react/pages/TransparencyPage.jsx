import { useI18n } from "../providers/I18nProvider";
import useAnalyticsFetch from "../hooks/useAnalyticsFetch";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevTable from "../components/ui/NevTable";
import NevDataState from "../components/ui/NevDataState";
import { BuildingIcon } from "../components/ui/icons";

const GRADE_TONE = { A: "success", B: "success", C: "warning", D: "warning", E: "danger" };

/**
 * C3.6 : "classement des bailleurs" - GET /api/analytics/transparency.
 * Frontend jamais construit lors du premier passage sur C3 (le plan
 * original demandait "jauge sur la fiche institution + classement des
 * bailleurs" ; App\Service\TransparencyScoreService n'avait ni jauge
 * (ajoutée sur institution-profile.html) ni classement - GET /api/analytics/
 * transparency et TransparencyScoreService::getRanking() ont dû être
 * ajoutés pour cette page, ils n'existaient pas encore).
 */
export default function TransparencyPage() {
  const { t } = useI18n();
  const { state, data, message, reload } = useAnalyticsFetch("/api/analytics/transparency", { emptyWhen: (body) => 0 === body.length });

  return (
    <div className="container mx-auto px-4 py-10">
      <div className="mb-8">
        <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-primary-dark">
          <BuildingIcon className="h-3 w-3" /> {t("transparency.pill", "Transparence")}
        </span>
        <h1 className="text-2xl font-bold text-dark">{t("transparency.title", "Classement de transparence des bailleurs")}</h1>
        <p className="mt-1 max-w-2xl text-sm text-body-color">
          {t(
            "transparency.subtitle",
            "Complétude des champs (30%), publication sur le registre IATI (20%), fraîcheur de la dernière collecte (20%), granularité (20%) et présence d'un descriptif (10%)."
          )}
        </p>
      </div>

      <NevDataState state={state} loadingText={t("hero.loading", "Chargement…")} emptyText={t("transparency.empty", "Aucun bailleur enregistré.")} errorText={message} onRetry={reload}>
        {data && (
          <NevCard padding="sm">
            <NevTable
              bordered={false}
              rowKey={(row) => row.sourceId}
              columns={[
                { key: "rank", label: "#", render: (row) => data.indexOf(row) + 1 },
                {
                  key: "sourceName",
                  label: t("transparency.source", "Bailleur"),
                  render: (row) => (
                    <a href={"institution-profile.html?sourceId=" + row.sourceId} className="font-semibold text-primary-dark hover:underline">
                      {row.sourceName}
                    </a>
                  ),
                },
                {
                  key: "value",
                  label: t("transparency.score", "Score de transparence"),
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
                { key: "grade", label: t("transparency.grade", "Grade"), render: (row) => <NevBadge tone={GRADE_TONE[row.grade] || "neutral"}>{row.grade}</NevBadge> },
              ]}
              rows={data}
            />
          </NevCard>
        )}
      </NevDataState>
    </div>
  );
}
