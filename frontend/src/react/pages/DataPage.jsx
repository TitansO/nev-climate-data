import { useEffect, useRef, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import { COUNTRY_GROUPS } from "../data/africaCountries";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import NevBadge from "../components/ui/NevBadge";
import NevAlert from "../components/ui/NevAlert";
import NevDataState from "../components/ui/NevDataState";
import NevTable from "../components/ui/NevTable";
import NevPagination from "../components/ui/NevPagination";

const PAGE_SIZE = 10;
const EXPORT_POLL_INTERVAL_MS = 3000;

const FUNDING_TYPE_LABELS = { public: "Public", private: "Privé", multilateral: "Multilatéral" };
const VALIDATION_STATUS_LABELS = { demo: "Démonstration", validated: "Validée" };
// ApiKey.quota and Funding.amount both store USD (the project's pivot
// currency); the API doesn't return a currency field because it never
// varies, so this is a fixed, correct label rather than a guess.
const DISPLAY_CURRENCY = "USD";

const EMPTY_FILTERS = { country: "", sector: "", year: "", fundingType: "", periodStart: "", periodEnd: "" };
const COUNTRY_LABELS = Object.fromEntries(COUNTRY_GROUPS.flatMap((group) => group.options).map((option) => [option.value, option.label]));

function filtersFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return {
    country: params.get("country") || "",
    sector: params.get("sector") || "",
    year: params.get("year") || "",
    fundingType: params.get("fundingType") || "",
    periodStart: params.get("periodStart") || "",
    periodEnd: params.get("periodEnd") || "",
  };
}

function formatAmount(amount) {
  const value = Number(amount);
  return Number.isNaN(value) ? amount : value.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * "Montant (devise locale)" column: item.originalAmount/originalCurrency
 * hold the same figure as item.amount, expressed in the funded country's
 * own national currency instead of the USD pivot. Both are nullable (a
 * record can predate this metadata, or its country's currency isn't in
 * the reference table), so this renders a plain dash rather than a
 * misleading "0" in that case.
 */
function formatLocalAmount(item) {
  if (!item.originalAmount || !item.originalCurrency) {
    return "-";
  }
  const value = Number(item.originalAmount);
  return Number.isNaN(value) ? "-" : value.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + item.originalCurrency;
}

function formatDate(isoDate) {
  const date = new Date(isoDate + "T00:00:00");
  return Number.isNaN(date.getTime()) ? isoDate : date.toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
}

function downloadBlob(blob, filename) {
  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = objectUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(objectUrl);
}

function exportFilenameFromResponse(response, fallback) {
  const header = response.headers.get("Content-Disposition") || "";
  const match = header.match(/filename="?([^";]+)"?/);
  return match ? match[1] : fallback;
}

export default function DataPage() {
  const { isAuthenticated, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [filters, setFilters] = useState(() => ({ ...EMPTY_FILTERS, ...filtersFromUrl() }));
  const [sectorOptions, setSectorOptions] = useState([]); // learned from real API responses, see learnSectorsFrom()

  const [status, setStatus] = useState("loading"); // loading | error | empty | data
  const [errorMessage, setErrorMessage] = useState("");
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState({ page: 1, totalPages: 1, total: 0 });

  const [exportFormat, setExportFormat] = useState("csv");
  const [exporting, setExporting] = useState(false);
  const [exportStatusMessage, setExportStatusMessage] = useState("");
  const [exportErrorMessage, setExportErrorMessage] = useState("");

  const knownSectorIds = useRef(new Set());

  function learnSectorsFrom(responseItems) {
    let added = false;
    const next = [...sectorOptions];
    responseItems.forEach((item) => {
      const id = item.sector.id;
      if (knownSectorIds.current.has(id)) {
        return;
      }
      knownSectorIds.current.add(id);
      next.push({ id, name: item.sector.name });
      added = true;
    });
    if (added) {
      next.sort((a, b) => a.name.localeCompare(b.name, "fr"));
      setSectorOptions(next);
    }
  }

  async function loadFunding(page, filtersToUse) {
    setStatus("loading");

    try {
      const activeFilters = filtersToUse || filters;
      const url = new URL(API_BASE_URL + "/api/funding", window.location.origin);
      const params = { ...activeFilters, page, limit: PAGE_SIZE };
      Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && "" !== value) {
          url.searchParams.set(key, value);
        }
      });

      let response;
      try {
        response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
      } catch (networkError) {
        throw new Error("Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.");
      }

      let body = null;
      try {
        body = await response.json();
      } catch (parseError) {
        // Falls through to the !response.ok branch below.
      }

      if (!response.ok) {
        throw new Error(body && body.message ? body.message : "Une erreur est survenue (" + response.status + ").");
      }

      learnSectorsFrom(body.data);
      setMeta({ page: body.meta.page, totalPages: body.meta.totalPages, total: body.meta.total });
      setItems(body.data);
      setStatus(0 === body.data.length ? "empty" : "data");
    } catch (error) {
      setErrorMessage(error.message);
      setStatus("error");
    }
  }

  // Seeds the filters from the URL's own query string on first load (A2.8
  // global search: a Country/Sector result's destination is
  // "data.html?country=SEN"/"data.html?sector=<id>") - also what makes the
  // URL genuinely shareable/bookmarkable.
  useEffect(() => {
    loadFunding(1, filters);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function applyFilters() {
    loadFunding(1, filters);
  }

  function resetFilters() {
    setFilters(EMPTY_FILTERS);
    // Without this, a filter that arrived via the URL (global search, or a
    // shared/bookmarked link) would silently come back on the next reload
    // even after the user explicitly cleared it here.
    window.history.replaceState(null, "", window.location.pathname);
    loadFunding(1, EMPTY_FILTERS);
  }

  // Active-filters chip summary (new UI addition, reads existing `filters`
  // state only - no new business logic). Removing one chip applies
  // immediately and keeps the URL in sync, same spirit as resetFilters().
  function chipLabel(key, value) {
    if ("country" === key) {
      return t("dataPage.country", "Pays") + " : " + (COUNTRY_LABELS[value] || value);
    }
    if ("sector" === key) {
      const sector = sectorOptions.find((option) => String(option.id) === value);
      return t("dataPage.sector", "Secteur") + " : " + (sector ? sector.name : value);
    }
    if ("year" === key) {
      return t("dataPage.year", "Année") + " : " + value;
    }
    if ("fundingType" === key) {
      return t("dataPage.fundingType", "Type de financement") + " : " + (FUNDING_TYPE_LABELS[value] || value);
    }
    if ("periodStart" === key) {
      return t("dataPage.since", "Depuis") + " : " + value;
    }
    return t("dataPage.until", "Jusqu'à") + " : " + value;
  }

  function removeFilterChip(key) {
    const next = { ...filters, [key]: "" };
    setFilters(next);
    const params = new URLSearchParams();
    Object.entries(next).forEach(([k, v]) => {
      if (v) {
        params.set(k, v);
      }
    });
    const query = params.toString();
    window.history.replaceState(null, "", window.location.pathname + (query ? "?" + query : ""));
    loadFunding(1, next);
  }

  const activeFilterChips = Object.entries(filters).filter(([, value]) => "" !== value);

  function exportUrl(path) {
    const url = new URL(API_BASE_URL + path, window.location.origin);
    Object.entries(filters).forEach(([key, value]) => {
      if (value) {
        url.searchParams.set(key, value);
      }
    });
    url.searchParams.set("format", exportFormat);
    return url;
  }

  async function pollExportUntilReady(exportId) {
    const statusUrl = API_BASE_URL + "/api/funding/exports/" + exportId;

    for (;;) {
      await new Promise((resolve) => setTimeout(resolve, EXPORT_POLL_INTERVAL_MS));

      const response = await authorizedFetch(statusUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) {
        throw new Error("Le suivi de l'export a échoué (" + response.status + ").");
      }
      const body = await response.json();

      if ("ready" === body.status) {
        return body;
      }
      if ("failed" === body.status) {
        throw new Error(body.errorMessage || "La génération de l'export a échoué.");
      }
      setExportStatusMessage("Export volumineux en cours de préparation (" + body.status + ")…");
    }
  }

  async function runExport() {
    setExportErrorMessage("");
    setExportStatusMessage("");
    setExporting(true);

    try {
      const response = await authorizedFetch(exportUrl("/api/funding/export").toString(), {
        headers: { Accept: "application/json, text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
      });

      if (202 === response.status) {
        const job = await response.json();
        setExportStatusMessage(job.message);

        const ready = await pollExportUntilReady(job.exportId);
        const fileResponse = await authorizedFetch(API_BASE_URL + ready.downloadUrl);
        if (!fileResponse.ok) {
          throw new Error("Le téléchargement a échoué (" + fileResponse.status + ").");
        }
        const blob = await fileResponse.blob();
        downloadBlob(blob, exportFilenameFromResponse(fileResponse, "funding-export." + exportFormat));
        setExportStatusMessage("Export prêt et téléchargé (" + ready.rowCount + " lignes).");
        return;
      }

      if (!response.ok) {
        let message = "Une erreur est survenue (" + response.status + ").";
        try {
          const body = await response.json();
          message = body.message || message;
        } catch (parseError) {
          // Falls through to the generic message above.
        }
        throw new Error(message);
      }

      const blob = await response.blob();
      downloadBlob(blob, exportFilenameFromResponse(response, "funding-export." + exportFormat));
      setExportStatusMessage("");
    } catch (error) {
      setExportErrorMessage(error.message);
      setExportStatusMessage("");
    } finally {
      setExporting(false);
    }
  }

  const columns = [
    { key: "country", label: t("dataPage.country", "Pays"), render: (item) => <span className="font-medium text-dark">{item.country.name}</span> },
    { key: "sector", label: t("dataPage.sector", "Secteur"), render: (item) => item.sector.name },
    { key: "year", label: t("dataPage.year", "Année") },
    { key: "fundingType", label: t("dataPage.type", "Type"), render: (item) => FUNDING_TYPE_LABELS[item.fundingType] || item.fundingType },
    { key: "amount", label: t("dataPage.amount", "Montant"), align: "right", render: (item) => <span className="font-semibold text-deep-3">{formatAmount(item.amount)}</span> },
    { key: "currency", label: t("dataPage.currency", "Devise"), render: () => DISPLAY_CURRENCY },
    { key: "localAmount", label: t("dataPage.localAmount", "Montant (devise locale)"), align: "right", render: (item) => <span className="text-dark-4">{formatLocalAmount(item)}</span> },
    { key: "source", label: t("dataPage.source", "Source"), render: (item) => item.source.name },
    {
      key: "status",
      label: t("dataPage.status", "Statut"),
      render: (item) => <NevBadge tone={"demo" === item.validationStatus ? "warning" : "success"}>{VALIDATION_STATUS_LABELS[item.validationStatus] || item.validationStatus}</NevBadge>,
    },
  ];

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-16 pt-[140px] text-center text-white lg:pt-[160px]">
        <div className="container mx-auto px-4">
          <h1 className="mb-3 text-3xl font-bold sm:text-4xl">{t("dataPage.title", "Explorer les données de financement")}</h1>
          <p className="mx-auto max-w-[640px] text-white/80">{t("dataPage.subtitle", "Filtrez et parcourez les données de financement climatique collectées par NEV Climate Data.")}</p>
        </div>
      </section>

      <div className="container mx-auto px-4">
        {/* ====== Filtres ====== */}
        <NevCard as="section" padding="lg" className="relative z-20 -mt-10 shadow-card sm:p-8">
          <div className="mb-4 flex items-center justify-between lg:hidden">
            <h2 className="text-base font-semibold text-dark">{t("dataPage.filters", "Filtres")}</h2>
            <NevButton variant="outline" size="sm" onClick={() => setFiltersOpen((open) => !open)}>
              {t("dataPage.toggleFilters", "Afficher / masquer")}
            </NevButton>
          </div>
          <div className={(filtersOpen ? "block" : "hidden") + " lg:block"}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
              <NevSelect id="filter-country" label={t("dataPage.country", "Pays")} value={filters.country} onChange={(event) => setFilters({ ...filters, country: event.target.value })}>
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
              <NevSelect id="filter-sector" label={t("dataPage.sector", "Secteur")} value={filters.sector} onChange={(event) => setFilters({ ...filters, sector: event.target.value })}>
                <option value="">{t("dataPage.allSectors", "Tous les secteurs")}</option>
                {sectorOptions.map((sector) => (
                  <option key={sector.id} value={String(sector.id)}>
                    {sector.name}
                  </option>
                ))}
              </NevSelect>
              <NevSelect id="filter-year" label={t("dataPage.year", "Année")} value={filters.year} onChange={(event) => setFilters({ ...filters, year: event.target.value })}>
                <option value="">{t("dataPage.allYears", "Toutes les années")}</option>
                <option value="2025">2025</option>
                <option value="2024">2024</option>
                <option value="2023">2023</option>
                <option value="2022">2022</option>
              </NevSelect>
              <NevSelect
                id="filter-funding-type"
                label={t("dataPage.fundingType", "Type de financement")}
                value={filters.fundingType}
                onChange={(event) => setFilters({ ...filters, fundingType: event.target.value })}
              >
                <option value="">{t("dataPage.allTypes", "Tous les types")}</option>
                <option value="public">{t("dataPage.typePublic", "Public")}</option>
                <option value="private">{t("dataPage.typePrivate", "Privé")}</option>
                <option value="multilateral">{t("dataPage.typeMultilateral", "Multilatéral")}</option>
              </NevSelect>
              <NevInput
                id="filter-period-start"
                type="date"
                label={t("dataPage.since", "Depuis")}
                value={filters.periodStart}
                onChange={(event) => setFilters({ ...filters, periodStart: event.target.value })}
              />
              <NevInput
                id="filter-period-end"
                type="date"
                label={t("dataPage.until", "Jusqu'à")}
                value={filters.periodEnd}
                onChange={(event) => setFilters({ ...filters, periodEnd: event.target.value })}
              />
            </div>

            {activeFilterChips.length > 0 && (
              <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-stroke pt-5">
                <span className="text-xs font-semibold uppercase tracking-wide text-dark-5">{t("dataPage.activeFilters", "Filtres actifs")}</span>
                {activeFilterChips.map(([key, value]) => (
                  <span key={key} className="inline-flex items-center gap-1.5 rounded-full bg-surface-alt px-3 py-1 text-xs font-medium text-deep-3">
                    {chipLabel(key, value)}
                    <button
                      type="button"
                      onClick={() => removeFilterChip(key)}
                      aria-label={t("dataPage.removeFilter", "Retirer ce filtre") + " : " + chipLabel(key, value)}
                      className="rounded-full p-0.5 transition hover:bg-primary/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                      <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="3">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </span>
                ))}
              </div>
            )}

            <div className="mt-6 flex flex-wrap items-center gap-3">
              <NevButton variant="primary" onClick={applyFilters}>
                {t("dataPage.applyFilters", "Appliquer les filtres")}
              </NevButton>
              <NevButton variant="outline" onClick={resetFilters}>
                {t("dataPage.resetFilters", "Réinitialiser")}
              </NevButton>
              {isAuthenticated() && (
                <div className="ml-auto flex items-center gap-2">
                  <NevSelect id="export-format" wrapperClassName="min-w-[9rem]" value={exportFormat} onChange={(event) => setExportFormat(event.target.value)} aria-label={t("dataPage.export", "Exporter")}>
                    <option value="csv">CSV</option>
                    <option value="xlsx">Excel (XLSX)</option>
                  </NevSelect>
                  <NevButton variant="outline" disabled={exporting} onClick={runExport}>
                    {exporting ? "Export en cours…" : t("dataPage.export", "Exporter")}
                  </NevButton>
                </div>
              )}
            </div>
            {exportStatusMessage && <p className="mt-3 text-sm text-body-color">{exportStatusMessage}</p>}
            {exportErrorMessage && (
              <NevAlert tone="danger" className="mt-3">
                {exportErrorMessage}
              </NevAlert>
            )}
          </div>
        </NevCard>
        {/* ====== /Filtres ====== */}

        {/* ====== Résultats ====== */}
        <section className="py-12">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-body-color" aria-live="polite">
              {"data" === status ? (
                <>
                  <span className="font-semibold tabular-nums text-dark">{items.length}</span> résultats affichés sur <span className="font-semibold tabular-nums text-dark">{meta.total}</span> au total
                </>
              ) : (
                "loading" === status && "Chargement…"
              )}
            </p>
          </div>

          <NevDataState
            state={"data" === status ? "success" : status}
            loadingText={t("dataPage.loadingData", "Chargement des données…")}
            errorText={errorMessage || t("dataPage.tryAgain", "Une erreur est survenue. Veuillez réessayer.")}
            emptyText={t("dataPage.noData", "Aucune donnée trouvée") + " - " + t("dataPage.widenFilters", "Essayez d'élargir vos critères de filtrage.")}
            onRetry={() => loadFunding(meta.page, filters)}
          >
            <div className="overflow-hidden rounded-lg border border-stroke bg-white shadow-1">
              <NevTable columns={columns} rows={items} rowKey={(item) => item.id} bordered={false} />
              <div className="border-t border-stroke px-5 py-4">
                <NevPagination
                  pageLabel={"Page " + meta.page + " sur " + Math.max(meta.totalPages, 1)}
                  onPrevious={() => loadFunding(meta.page - 1)}
                  onNext={() => loadFunding(meta.page + 1)}
                  disabledPrevious={meta.page <= 1}
                  disabledNext={meta.page >= meta.totalPages}
                  previousLabel={t("dataPage.previous", "Précédent")}
                  nextLabel={t("dataPage.next", "Suivant")}
                />
              </div>
            </div>
          </NevDataState>
          <p
            className="mt-4 text-xs text-dark-5"
            dangerouslySetInnerHTML={{
              __html: t(
                "dataPage.disclaimer",
                'Données réelles issues de <code class="font-mono">GET /api/funding</code> (A2.1). Le jeu de données provient actuellement des fixtures de démonstration (A1.6) et porte le statut « Démonstration ».'
              ),
            }}
          />
        </section>
        {/* ====== /Résultats ====== */}
      </div>
    </>
  );
}
