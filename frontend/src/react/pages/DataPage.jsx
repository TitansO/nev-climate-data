import { useEffect, useRef, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import { COUNTRY_GROUPS } from "../data/africaCountries";

const PAGE_SIZE = 10;
const EXPORT_POLL_INTERVAL_MS = 3000;

const FUNDING_TYPE_LABELS = { public: "Public", private: "Privé", multilateral: "Multilatéral" };
const VALIDATION_STATUS_LABELS = { demo: "Démonstration", validated: "Validée" };
// ApiKey.quota and Funding.amount both store USD (the project's pivot
// currency); the API doesn't return a currency field because it never
// varies, so this is a fixed, correct label rather than a guess.
const DISPLAY_CURRENCY = "USD";

const EMPTY_FILTERS = { country: "", sector: "", year: "", fundingType: "", periodStart: "", periodEnd: "" };

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
      <section className="relative z-20 -mt-10 rounded-2xl bg-white p-6 shadow-2 sm:p-8">
        <div className="mb-4 flex items-center justify-between lg:hidden">
          <h2 className="text-base font-semibold text-dark">{t("dataPage.filters", "Filtres")}</h2>
          <button type="button" onClick={() => setFiltersOpen((open) => !open)} className="rounded-md border border-stroke px-3 py-1.5 text-sm font-medium text-dark-4">
            <span>{t("dataPage.toggleFilters", "Afficher / masquer")}</span>
          </button>
        </div>
        <div className={(filtersOpen ? "block" : "hidden") + " lg:block"}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.country", "Pays")}</label>
              <select
                value={filters.country}
                onChange={(event) => setFilters({ ...filters, country: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              >
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
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.sector", "Secteur")}</label>
              <select
                value={filters.sector}
                onChange={(event) => setFilters({ ...filters, sector: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              >
                <option value="">{t("dataPage.allSectors", "Tous les secteurs")}</option>
                {sectorOptions.map((sector) => (
                  <option key={sector.id} value={String(sector.id)}>
                    {sector.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.year", "Année")}</label>
              <select
                value={filters.year}
                onChange={(event) => setFilters({ ...filters, year: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              >
                <option value="">{t("dataPage.allYears", "Toutes les années")}</option>
                <option value="2025">2025</option>
                <option value="2024">2024</option>
                <option value="2023">2023</option>
                <option value="2022">2022</option>
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.fundingType", "Type de financement")}</label>
              <select
                value={filters.fundingType}
                onChange={(event) => setFilters({ ...filters, fundingType: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              >
                <option value="">{t("dataPage.allTypes", "Tous les types")}</option>
                <option value="public">{t("dataPage.typePublic", "Public")}</option>
                <option value="private">{t("dataPage.typePrivate", "Privé")}</option>
                <option value="multilateral">{t("dataPage.typeMultilateral", "Multilatéral")}</option>
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.since", "Depuis")}</label>
              <input
                type="date"
                value={filters.periodStart}
                onChange={(event) => setFilters({ ...filters, periodStart: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-dark-3">{t("dataPage.until", "Jusqu'à")}</label>
              <input
                type="date"
                value={filters.periodEnd}
                onChange={(event) => setFilters({ ...filters, periodEnd: event.target.value })}
                className="w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>
          </div>
          <div className="mt-6 flex flex-wrap items-center gap-3">
            <button type="button" onClick={applyFilters} className="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-dark">
              {t("dataPage.applyFilters", "Appliquer les filtres")}
            </button>
            <button type="button" onClick={resetFilters} className="rounded-md border border-stroke px-6 py-2.5 text-sm font-semibold text-dark-4 transition hover:bg-gray-2">
              {t("dataPage.resetFilters", "Réinitialiser")}
            </button>
            {isAuthenticated() && (
              <div className="ml-auto flex items-center gap-2">
                <select
                  value={exportFormat}
                  onChange={(event) => setExportFormat(event.target.value)}
                  className="rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                  <option value="csv">CSV</option>
                  <option value="xlsx">Excel (XLSX)</option>
                </select>
                <button
                  type="button"
                  disabled={exporting}
                  onClick={runExport}
                  className="rounded-md border border-primary px-6 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary hover:text-white disabled:opacity-70"
                >
                  <span>{exporting ? "Export en cours…" : t("dataPage.export", "Exporter")}</span>
                </button>
              </div>
            )}
          </div>
          {exportStatusMessage && <p className="mt-3 text-sm text-body-color">{exportStatusMessage}</p>}
          {exportErrorMessage && <p className="mt-3 text-sm text-status-demo">{exportErrorMessage}</p>}
        </div>
      </section>
      {/* ====== /Filtres ====== */}

      {/* ====== Résultats ====== */}
      <section className="py-12">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-body-color">
            {"data" === status ? (
              <>
                <span className="font-semibold text-dark">{items.length}</span> résultats affichés sur <span className="font-semibold text-dark">{meta.total}</span> au
                total
              </>
            ) : (
              "loading" === status && "Chargement…"
            )}
          </p>
        </div>

        {"loading" === status && (
          <div className="rounded-2xl border border-stroke bg-white p-16 text-center">
            <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-4 border-primary/20 border-t-primary"></div>
            <p className="text-sm text-body-color">{t("dataPage.loadingData", "Chargement des données…")}</p>
          </div>
        )}

        {"error" === status && (
          <div className="rounded-2xl border border-status-demo/30 bg-status-demo-bg/40 p-16 text-center">
            <p className="mb-1 text-base font-semibold text-status-demo">{t("dataPage.loadError", "Impossible de charger les données")}</p>
            <p className="text-sm text-body-color">{errorMessage || t("dataPage.tryAgain", "Une erreur est survenue. Veuillez réessayer.")}</p>
          </div>
        )}

        {"empty" === status && (
          <div className="rounded-2xl border border-stroke bg-white p-16 text-center">
            <p className="mb-1 text-base font-semibold text-dark">{t("dataPage.noData", "Aucune donnée trouvée")}</p>
            <p className="text-sm text-body-color">{t("dataPage.widenFilters", "Essayez d'élargir vos critères de filtrage.")}</p>
          </div>
        )}

        {"data" === status && (
          <div className="overflow-hidden rounded-2xl border border-stroke bg-white shadow-1">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[900px] text-left text-sm">
                <thead className="bg-surface-alt text-xs font-semibold uppercase tracking-wide text-dark-4">
                  <tr>
                    <th className="px-5 py-4">{t("dataPage.country", "Pays")}</th>
                    <th className="px-5 py-4">{t("dataPage.sector", "Secteur")}</th>
                    <th className="px-5 py-4">{t("dataPage.year", "Année")}</th>
                    <th className="px-5 py-4">{t("dataPage.type", "Type")}</th>
                    <th className="px-5 py-4">{t("dataPage.amount", "Montant")}</th>
                    <th className="px-5 py-4">{t("dataPage.currency", "Devise")}</th>
                    <th className="px-5 py-4">{t("dataPage.localAmount", "Montant (devise locale)")}</th>
                    <th className="px-5 py-4">{t("dataPage.source", "Source")}</th>
                    <th className="px-5 py-4">{t("dataPage.status", "Statut")}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-stroke">
                  {items.map((item) => (
                    <tr key={item.id} className="hover:bg-surface">
                      <td className="px-5 py-4 font-medium text-dark">{item.country.name}</td>
                      <td className="px-5 py-4">{item.sector.name}</td>
                      <td className="px-5 py-4">{item.year}</td>
                      <td className="px-5 py-4">{FUNDING_TYPE_LABELS[item.fundingType] || item.fundingType}</td>
                      <td className="px-5 py-4 font-semibold text-deep-3">{formatAmount(item.amount)}</td>
                      <td className="px-5 py-4">{DISPLAY_CURRENCY}</td>
                      <td className="px-5 py-4 text-dark-4">{formatLocalAmount(item)}</td>
                      <td className="px-5 py-4">{item.source.name}</td>
                      <td className="px-5 py-4">
                        <span
                          className={
                            "inline-flex rounded-full px-2.5 py-1 text-xs font-semibold " +
                            ("demo" === item.validationStatus ? "bg-status-demo-bg text-status-demo" : "bg-status-validated-bg text-status-validated")
                          }
                        >
                          {VALIDATION_STATUS_LABELS[item.validationStatus] || item.validationStatus}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-stroke px-5 py-4">
              <p className="text-xs text-dark-5">
                Page {meta.page} sur {Math.max(meta.totalPages, 1)}
              </p>
              <nav className="flex items-center gap-1.5" aria-label="Pagination">
                <button
                  type="button"
                  disabled={meta.page <= 1}
                  onClick={() => loadFunding(meta.page - 1)}
                  className="rounded-md border border-stroke px-3 py-1.5 text-sm text-dark-4 hover:bg-gray-2 disabled:cursor-not-allowed disabled:text-dark-6 disabled:hover:bg-transparent"
                >
                  {t("dataPage.previous", "Précédent")}
                </button>
                <button
                  type="button"
                  disabled={meta.page >= meta.totalPages}
                  onClick={() => loadFunding(meta.page + 1)}
                  className="rounded-md border border-stroke px-3 py-1.5 text-sm text-dark-4 hover:bg-gray-2 disabled:cursor-not-allowed disabled:text-dark-6 disabled:hover:bg-transparent"
                >
                  {t("dataPage.next", "Suivant")}
                </button>
              </nav>
            </div>
          </div>
        )}
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
