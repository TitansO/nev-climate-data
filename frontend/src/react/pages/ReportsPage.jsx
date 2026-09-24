import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
import { COUNTRY_GROUPS } from "../data/africaCountries";
import NevCard from "../components/ui/NevCard";
import NevBadge from "../components/ui/NevBadge";
import NevButton from "../components/ui/NevButton";
import NevSelect from "../components/ui/NevSelect";
import NevDataState from "../components/ui/NevDataState";
import NevPagination from "../components/ui/NevPagination";
import ReportDetailModal from "../components/ReportDetailModal";
import { DocumentIcon, SparklesIcon } from "../components/ui/icons";

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

function reportBadge(report, t) {
  const meta = TYPE_META[report.type] || DEFAULT_TYPE_META;
  const label = meta.i18nKey ? t(meta.i18nKey, meta.fallback) : report.type;
  return <NevBadge tone={meta.tone}>{label}</NevBadge>;
}

function ReportCard({ report, t, apiBaseUrl, viewMode, onOpenDetails }) {
  const meta = TYPE_META[report.type] || DEFAULT_TYPE_META;
  const isList = "list" === viewMode;

  return (
    <NevCard as="article" padding="lg" interactive className={"flex " + (isList ? "flex-col sm:flex-row sm:items-center sm:gap-6" : "flex-col")}>
      <div className={"mb-5 flex items-start justify-between " + (isList ? "sm:mb-0 sm:flex-col sm:items-center sm:justify-center sm:gap-2" : "")}>
        <div className={"flex h-12 w-12 items-center justify-center rounded-xl text-white " + meta.iconClass}>
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 20H7a2 2 0 01-2-2V6a2 2 0 012-2h7l5 5v9a2 2 0 01-2 2z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M14 4v5h5" />
          </svg>
        </div>
        {reportBadge(report, t)}
      </div>
      <div className="flex-1">
        <h3 className="mb-2 text-lg font-bold leading-snug text-dark">{report.title}</h3>
        <dl className={"space-y-1.5 text-sm text-dark-5 " + (isList ? "flex flex-wrap gap-x-6 gap-y-1 space-y-0" : "mb-5")}>
          {report.publicationDate && (
            <div className="flex justify-between gap-3 sm:justify-start">
              <dt>{t("reportsPage.date", "Date")}</dt>
              <dd className="font-medium text-dark-3">{formatDate(report.publicationDate)}</dd>
            </div>
          )}
          {(report.country || report.region) && (
            <div className="flex justify-between gap-3 sm:justify-start">
              <dt>{t("dataPage.country", "Pays")}</dt>
              <dd className="font-medium text-dark-3">{report.country ? report.country.name : report.region}</dd>
            </div>
          )}
          <div className="flex justify-between gap-3 sm:justify-start">
            <dt>{t("reportsPage.downloads", "Téléchargements")}</dt>
            <dd className="font-medium tabular-nums text-dark-3">{report.downloadCount}</dd>
          </div>
        </dl>
      </div>
      <div className={"flex gap-2 " + (isList ? "mt-4 sm:mt-0" : "mt-5")}>
        <NevButton as="a" href={apiBaseUrl + report.downloadUrl} variant="outline" size="md" onClick={(event) => event.stopPropagation()}>
          {t("reportsPage.view", "Consulter")}
        </NevButton>
        <NevButton variant="ghost" size="md" onClick={() => onOpenDetails(report)}>
          {t("reportsPage.details", "Détails")} →
        </NevButton>
      </div>
    </NevCard>
  );
}

const GRID_ICON = (
  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <rect x="3" y="3" width="7" height="7" rx="1" />
    <rect x="14" y="3" width="7" height="7" rx="1" />
    <rect x="3" y="14" width="7" height="7" rx="1" />
    <rect x="14" y="14" width="7" height="7" rx="1" />
  </svg>
);
const LIST_ICON = (
  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
    <line x1="3" y1="6" x2="21" y2="6" />
    <line x1="3" y1="12" x2="21" y2="12" />
    <line x1="3" y1="18" x2="21" y2="18" />
  </svg>
);

export default function ReportsPage() {
  const { API_BASE_URL, isAuthenticated } = useAuth();
  const { t } = useI18n();

  const [type, setType] = useState("");
  const [country, setCountry] = useState("");
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("loading"); // loading | error | empty | data
  const [reports, setReports] = useState([]);
  const [meta, setMeta] = useState({ page: 1, totalPages: 1, total: 0 });
  const [viewMode, setViewMode] = useState("grid");
  const [detailsReport, setDetailsReport] = useState(null);

  async function loadReports(targetType, targetCountry, targetPage) {
    setStatus("loading");

    const url = new URL(API_BASE_URL + "/api/reports", window.location.origin);
    url.searchParams.set("page", String(targetPage));
    url.searchParams.set("limit", String(PAGE_SIZE));
    if (targetType) {
      url.searchParams.set("type", targetType);
    }
    if (targetCountry) {
      url.searchParams.set("country", targetCountry);
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
    loadReports(type, country, page);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type, country, page]);

  function selectType(nextType) {
    setType(nextType);
    setPage(1);
  }

  function changeCountry(nextCountry) {
    setCountry(nextCountry);
    setPage(1);
  }

  function resetFilters() {
    setType("");
    setCountry("");
    setPage(1);
  }

  // Rapport phare : uniquement sur la page 1 non filtrée - reports[0] y est
  // déjà le plus récent (ReportRepository::findPublished trie par
  // publicationDate DESC), jamais un id/titre codé en dur.
  const featured = "" === type && "" === country && 1 === page && reports.length > 0 ? reports[0] : null;

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[72px] pt-[56px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <DocumentIcon className="h-3 w-3" /> {t("reportsPage.pill", "Bibliothèque")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("reportsPage.title", "Rapports & analyses")}</h1>
          <p className="mb-[28px] max-w-[520px] text-base text-white/80">{t("reportsPage.subtitle", "Rapports annuels, études sectorielles et publications régionales sur le financement climatique.")}</p>
          {/* Uniquement des chiffres réellement disponibles - pas de "régions
              couvertes"/"téléchargements" agrégés, non exposés par l'API sur
              l'ensemble du jeu de données (voir le rapport d'intégration). */}
          <div className="flex flex-wrap gap-9">
            <div>
              <div className="text-2xl font-bold tabular-nums">{"loading" === status ? "…" : meta.total}</div>
              <div className="text-[0.72rem] uppercase tracking-[0.05em] text-white/60">{t("reportsPage.published", "Rapports publiés")}</div>
            </div>
            {featured && featured.publicationDate && (
              <div>
                <div className="text-2xl font-bold tabular-nums">{new Date(featured.publicationDate).getFullYear()}</div>
                <div className="text-[0.72rem] uppercase tracking-[0.05em] text-white/60">{t("reportsPage.lastUpdate", "Dernière mise à jour")}</div>
              </div>
            )}
          </div>
        </div>
      </section>

      <div className="container mx-auto px-4">
        <section className="relative z-20 -mt-10 grid grid-cols-1 gap-6 pb-20 lg:grid-cols-[260px_1fr] lg:items-start">
          <aside className="lg:sticky lg:top-24">
            <NevCard padding="md" className="shadow-card">
              <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold text-dark">
                <svg className="h-4 w-4 text-dark-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3 4h18M7 8h10M11 12h2" />
                </svg>
                {t("dataPage.filters", "Filtres")}
              </h2>
              <div className="mb-5">
                <span className="mb-2 block text-xs font-semibold uppercase tracking-wide text-dark-5">{t("dataPage.type", "Type")}</span>
                <div className="flex flex-col gap-1.5">
                  {FILTER_PILLS.map((pill) => {
                    const active = pill.type === type;
                    return (
                      <button
                        key={pill.type}
                        type="button"
                        onClick={() => selectType(pill.type)}
                        aria-pressed={active}
                        className={
                          "rounded-md px-3 py-1.5 text-left text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary " +
                          (active ? "bg-primary-light/15 font-semibold text-primary-dark" : "text-dark-4 hover:bg-gray-2")
                        }
                      >
                        {t(pill.i18nKey, pill.fallback)}
                      </button>
                    );
                  })}
                </div>
              </div>
              <NevSelect id="report-country" label={t("dataPage.country", "Pays")} value={country} onChange={(event) => changeCountry(event.target.value)}>
                <option value="">{t("dataPage.allCountries", "Tous les pays")}</option>
                {COUNTRY_GROUPS.map((group) => (
                  <optgroup key={group.label} label={group.label}>
                    {group.options.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </optgroup>
                ))}
              </NevSelect>
              <NevButton variant="outline" className="mt-5 w-full" onClick={resetFilters}>
                {t("dataPage.resetFilters", "Réinitialiser")}
              </NevButton>
            </NevCard>
          </aside>

          <main>
            {featured && (
              <NevCard padding="lg" className="mb-6 border-0 bg-deep-neutral text-white shadow-card">
                <div className="flex flex-wrap items-center justify-between gap-5">
                  <div>
                    <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-primary/20 px-3 py-1 text-xs font-bold uppercase tracking-wide text-primary-light">
                      <SparklesIcon className="h-3 w-3" /> {t("reportsPage.featured", "Rapport le plus récent")}
                    </span>
                    <h2 className="mb-1 text-lg font-bold">{featured.title}</h2>
                    {featured.publicationDate && <p className="text-sm text-white/60">{formatDate(featured.publicationDate)}</p>}
                  </div>
                  <div className="flex gap-2">
                    <NevButton as="a" href={API_BASE_URL + featured.downloadUrl} variant="primary">
                      {t("reportsPage.view", "Consulter")}
                    </NevButton>
                    <NevButton
                      variant="outline"
                      className="border-white/30 bg-white/10 text-white hover:bg-white/20 hover:text-white"
                      onClick={() => setDetailsReport(featured)}
                    >
                      {t("reportsPage.details", "Détails")}
                    </NevButton>
                  </div>
                </div>
              </NevCard>
            )}

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-body-color" aria-live="polite">
                {"loading" === status ? t("hero.loading", "Chargement…") : meta.total + " rapport" + (meta.total > 1 ? "s" : "")}
              </p>
              <div className="flex gap-1 rounded-md bg-gray-2 p-1">
                <button
                  type="button"
                  onClick={() => setViewMode("grid")}
                  aria-pressed={"grid" === viewMode}
                  aria-label={t("reportsPage.viewGrid", "Vue grille")}
                  className={"flex items-center rounded p-1.5 transition " + ("grid" === viewMode ? "bg-white text-dark shadow-xs" : "text-dark-5")}
                >
                  {GRID_ICON}
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("list")}
                  aria-pressed={"list" === viewMode}
                  aria-label={t("reportsPage.viewList", "Vue liste")}
                  className={"flex items-center rounded p-1.5 transition " + ("list" === viewMode ? "bg-white text-dark shadow-xs" : "text-dark-5")}
                >
                  {LIST_ICON}
                </button>
              </div>
            </div>

            <NevDataState
              state={"data" === status ? "success" : status}
              loadingText={t("dataPage.loadingData", "Chargement des données…")}
              errorText={t("dataPage.loadError", "Impossible de charger les données") + " - " + t("dataPage.tryAgain", "Une erreur est survenue. Veuillez réessayer.")}
              emptyText={t("dataPage.noData", "Aucune donnée trouvée") + " - " + t("dataPage.widenFilters", "Essayez d'élargir vos critères de filtrage.")}
              onRetry={() => loadReports(type, country, page)}
            >
              <>
                <div className={"grid grid-cols-1 gap-6 " + ("grid" === viewMode ? "md:grid-cols-2 xl:grid-cols-3" : "")}>
                  {reports.map((report) => (
                    <ReportCard key={report.id} report={report} t={t} apiBaseUrl={API_BASE_URL} viewMode={viewMode} onOpenDetails={setDetailsReport} />
                  ))}
                </div>

                {meta.totalPages > 1 && (
                  <div className="mt-8">
                    <NevPagination
                      page={meta.page}
                      totalPages={meta.totalPages}
                      onPageChange={setPage}
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
          </main>
        </section>
      </div>

      <ReportDetailModal
        report={detailsReport}
        badge={detailsReport ? reportBadge(detailsReport, t) : null}
        formattedDate={detailsReport && detailsReport.publicationDate ? formatDate(detailsReport.publicationDate) : ""}
        apiBaseUrl={API_BASE_URL}
        t={t}
        open={null !== detailsReport}
        onClose={() => setDetailsReport(null)}
      />
    </>
  );
}
