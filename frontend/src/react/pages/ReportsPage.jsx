import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevButton from "../components/ui/NevButton";
import NevDataState from "../components/ui/NevDataState";
import NevPagination from "../components/ui/NevPagination";

const PAGE_SIZE = 12;

// Maps a report's raw `type` (as stored in the database) to the badge
// color and i18n label key used across this page's filter pills and card
// badges, so the two stay visually and textually consistent.
const TYPE_META = {
  "Annual Report": { tone: "success", iconClass: "bg-deep-3", i18nKey: "reportsPage.typeAnnual", fallback: "Rapport annuel" },
  "Regional Report": { tone: "info", iconClass: "bg-primary", i18nKey: "reportsPage.typeRegional", fallback: "Rapport régional" },
  "Country Report": { tone: "warning", iconClass: "bg-status-demo", i18nKey: "reportsPage.typeCountry", fallback: "Rapport pays" },
  "Sector Report": { tone: "info", iconClass: "bg-status-review", i18nKey: "reportsPage.typeSectorStudy", fallback: "Étude sectorielle" },
};
const DEFAULT_TYPE_META = { tone: "neutral", iconClass: "bg-deep-3", i18nKey: null, fallback: "" };

const FILTER_PILLS = [
  { type: "", i18nKey: "reportsPage.filterAll", fallback: "Tous" },
  { type: "Annual Report", i18nKey: "reportsPage.typeAnnual", fallback: "Rapport annuel" },
  { type: "Regional Report", i18nKey: "reportsPage.typeRegional", fallback: "Rapport régional" },
  { type: "Country Report", i18nKey: "reportsPage.typeCountry", fallback: "Rapport pays" },
  { type: "Sector Report", i18nKey: "reportsPage.typeSectorStudy", fallback: "Étude sectorielle" },
];

function formatDate(isoDate) {
  return new Date(isoDate + "T00:00:00").toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

function ReportCard({ report, t, apiBaseUrl }) {
  const meta = TYPE_META[report.type] || DEFAULT_TYPE_META;
  const badgeLabel = meta.i18nKey ? t(meta.i18nKey, meta.fallback) : report.type;

  return (
    <NevCard as="article" padding="lg" interactive className="flex flex-col">
      <div className="mb-5 flex items-start justify-between">
        <div className={"flex h-12 w-12 items-center justify-center rounded-xl text-white " + meta.iconClass}>
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 20H7a2 2 0 01-2-2V6a2 2 0 012-2h7l5 5v9a2 2 0 01-2 2z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M14 4v5h5" />
          </svg>
        </div>
        <NevBadge tone={meta.tone}>{badgeLabel}</NevBadge>
      </div>
      <h3 className="mb-2 text-lg font-bold leading-snug text-dark">{report.title}</h3>
      <dl className="mb-5 flex-1 space-y-1.5 text-sm text-dark-5">
        {report.publicationDate && (
          <div className="flex justify-between gap-3">
            <dt>{t("reportsPage.date", "Date")}</dt>
            <dd className="font-medium text-dark-3">{formatDate(report.publicationDate)}</dd>
          </div>
        )}
        {(report.country || report.region) && (
          <div className="flex justify-between gap-3">
            <dt>{t("dataPage.country", "Pays")}</dt>
            <dd className="font-medium text-dark-3">{report.country ? report.country.name : report.region}</dd>
          </div>
        )}
        <div className="flex justify-between gap-3">
          <dt>{t("reportsPage.downloads", "Téléchargements")}</dt>
          <dd className="font-medium tabular-nums text-dark-3">{report.downloadCount}</dd>
        </div>
      </dl>
      <NevButton as="a" href={apiBaseUrl + report.downloadUrl} variant="outline" size="md">
        {t("reportsPage.view", "Consulter")}
      </NevButton>
    </NevCard>
  );
}

export default function ReportsPage() {
  const { API_BASE_URL } = useAuth();
  const { t } = useI18n();

  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("loading"); // loading | error | empty | data
  const [reports, setReports] = useState([]);
  const [meta, setMeta] = useState({ page: 1, totalPages: 1, total: 0 });

  async function loadReports(targetType, targetPage) {
    setStatus("loading");

    const url = new URL(API_BASE_URL + "/api/reports", window.location.origin);
    url.searchParams.set("page", String(targetPage));
    url.searchParams.set("limit", String(PAGE_SIZE));
    if (targetType) {
      url.searchParams.set("type", targetType);
    }

    let response;
    try {
      response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
    } catch (networkError) {
      setStatus("error");
      return;
    }

    if (!response.ok) {
      setStatus("error");
      return;
    }

    const body = await response.json();
    setMeta({ page: body.meta.page, totalPages: body.meta.totalPages, total: body.meta.total });
    setReports(body.data);
    setStatus(0 === body.data.length ? "empty" : "data");
  }

  useEffect(() => {
    loadReports(type, page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type, page]);

  function selectType(nextType) {
    setType(nextType);
    setPage(1);
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("reportsPage.title", "Rapports & analyses")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("reportsPage.subtitle", "Rapports annuels, études sectorielles et publications régionales sur le financement climatique.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 pb-20">
          <NevCard as="div" padding="md" className="mb-8 flex flex-wrap items-center gap-2 shadow-card">
            {FILTER_PILLS.map((pill) => {
              const active = pill.type === type;
              return (
                <button
                  key={pill.type}
                  type="button"
                  onClick={() => selectType(pill.type)}
                  aria-pressed={active}
                  className={
                    "rounded-full px-4 py-1.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary " +
                    (active ? "bg-primary text-white" : "border border-stroke font-medium text-dark-4 hover:bg-gray-2")
                  }
                >
                  {t(pill.i18nKey, pill.fallback)}
                </button>
              );
            })}
          </NevCard>

          <p className="mb-4 text-sm text-body-color" aria-live="polite">
            {"loading" === status ? t("hero.loading", "Chargement…") : meta.total + " rapport" + (meta.total > 1 ? "s" : "")}
          </p>

          <NevDataState
            state={"data" === status ? "success" : status}
            loadingText={t("dataPage.loadingData", "Chargement des données…")}
            errorText={t("dataPage.loadError", "Impossible de charger les données") + " - " + t("dataPage.tryAgain", "Une erreur est survenue. Veuillez réessayer.")}
            emptyText={t("dataPage.noData", "Aucune donnée trouvée") + " - " + t("dataPage.widenFilters", "Essayez d'élargir vos critères de filtrage.")}
            onRetry={() => loadReports(type, page)}
          >
            <>
              <div className="grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-3">
                {reports.map((report) => (
                  <ReportCard key={report.id} report={report} t={t} apiBaseUrl={API_BASE_URL} />
                ))}
              </div>

              {meta.totalPages > 1 && (
                <div className="mt-8">
                  <NevPagination
                    pageLabel={"Page " + meta.page + " sur " + meta.totalPages}
                    onPrevious={() => setPage((p) => p - 1)}
                    onNext={() => setPage((p) => p + 1)}
                    disabledPrevious={meta.page <= 1}
                    disabledNext={meta.page >= meta.totalPages}
                    previousLabel={t("dataPage.previous", "Précédent")}
                    nextLabel={t("dataPage.next", "Suivant")}
                  />
                </div>
              )}
            </>
          </NevDataState>

          <p
            className="mt-8 text-xs text-dark-5"
            dangerouslySetInnerHTML={{
              __html: t("reportsPage.disclaimer", 'Données réelles issues de <code class="font-mono">GET /api/reports</code> (A2.13). Le jeu de rapports provient actuellement des fixtures de démonstration (A1.6).'),
            }}
          />
        </section>
      </div>
    </>
  );
}
