import { useEffect, useRef, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import RequireAuth from "../components/RequireAuth";
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
import { BanknoteIcon } from "../components/ui/icons";

const PAGE_SIZE = 10;
const EXPORT_POLL_INTERVAL_MS = 3000;

const FUNDING_TYPE_LABELS = { public: "Bilatéral", private: "Privé", multilateral: "Multilatéral" };
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
 * "Montant d'origine" column: item.originalAmount/originalCurrency hold
 * the same figure as item.amount, but NOT always in the funded country's
 * own currency - audit 2026-10-13, trouve en direct par l'utilisateur sur
 * une ligne AFD (8 300 000,00 EUR, qui n'est la devise locale d'aucun pays
 * africain). AFD/AfDB rapportent leur propre montant institutionnel tel
 * quel (EUR/XDR - voir pipeline/collectors/afd.py et afdb.py) - la devise
 * DE LA SOURCE, jamais ecrasee. Pour la vraie devise du pays beneficiaire
 * de maniere fiable quelle que soit la source, voir formatLocalCurrencyAmount()
 * et item.localAmount/localCurrency juste en dessous plutot que ces deux
 * champs-ci. Nullable (une ligne peut predater cette metadonnee), d'ou le
 * tiret plutot qu'un "0" trompeur dans ce cas.
 */
function formatOriginalAmount(item) {
  if (!item.originalAmount || !item.originalCurrency) {
    return "-";
  }
  const value = Number(item.originalAmount);
  return Number.isNaN(value) ? "-" : value.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + item.originalCurrency;
}

/**
 * "Devise locale" column (ajoutee 2026-10-13) : item.localAmount/
 * localCurrency sont TOUJOURS une conversion live-rate vers la devise du
 * PAYS beneficiaire, calculee de la meme facon pour toutes les sources -
 * voir process_message() cote pipeline/processors/funding_validator.py.
 * Contrairement a formatOriginalAmount() ci-dessus, cette colonne est donc
 * directement comparable d'une ligne a l'autre, peu importe la source.
 */
function formatLocalCurrencyAmount(item) {
  if (!item.localAmount || !item.localCurrency) {
    return "-";
  }
  const value = Number(item.localAmount);
  return Number.isNaN(value) ? "-" : value.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + item.localCurrency;
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
  const [yearOptions, setYearOptions] = useState([]); // learned from real API responses, see learnYearsFrom() - real data spans 1967-2027, a hardcoded 2022-2025 list (pre-B3.1) silently hid everything outside that range

  const [status, setStatus] = useState("loading"); // loading | error | empty | data
  const [errorMessage, setErrorMessage] = useState("");
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState({ page: 1, totalPages: 1, total: 0, totalAmount: "0.00" });

  const [exportFormat, setExportFormat] = useState("csv");
  const [exporting, setExporting] = useState(false);
  const [exportStatusMessage, setExportStatusMessage] = useState("");
  const [exportErrorMessage, setExportErrorMessage] = useState("");

  const knownSectorIds = useRef(new Set());
  const knownYears = useRef(new Set());

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

  function learnYearsFrom(responseItems) {
    let added = false;
    const next = [...yearOptions];
    responseItems.forEach((item) => {
      if (knownYears.current.has(item.year)) {
        return;
      }
      knownYears.current.add(item.year);
      next.push(item.year);
      added = true;
    });
    if (added) {
      next.sort((a, b) => b - a);
      setYearOptions(next);
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
        response = await authorizedFetch(url.toString(), { headers: { Accept: "application/json" } });
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
      learnYearsFrom(body.data);
      // totalAmount (2026-09-29) : somme de `amount` sur TOUTES les lignes
      // correspondant aux filtres actifs, pas seulement la page affichée -
      // calculée côté serveur (voir FundingRepository::sumAmountByCriteria())
      // avec exactement les mêmes filtres que `total`, jamais recalculée en
      // sommant `items` ici (qui ne contient qu'une page de 10 lignes).
      setMeta({ page: body.meta.page, totalPages: body.meta.totalPages, total: body.meta.total, totalAmount: body.meta.totalAmount });
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

  const columns = [
    { key: "country", label: t("dataPage.country", "Pays"), render: (item) => <span className="font-medium text-dark">{item.country.name}</span> },
    { key: "sector", label: t("dataPage.sector", "Secteur"), render: (item) => item.sector.name },
    { key: "year", label: t("dataPage.year", "Année") },
    { key: "fundingType", label: t("dataPage.type", "Type"), render: (item) => FUNDING_TYPE_LABELS[item.fundingType] || item.fundingType },
    { key: "amount", label: t("dataPage.amount", "Montant"), align: "right", render: (item) => <span className="font-semibold text-deep-3">{formatAmount(item.amount)}</span> },
    { key: "currency", label: t("dataPage.currency", "Devise"), width: "w-24", render: () => DISPLAY_CURRENCY },
    { key: "originalAmount", label: t("dataPage.localAmount", "Montant d'origine"), align: "right", width: "w-40", render: (item) => <span className="text-dark-4">{formatOriginalAmount(item)}</span> },
    { key: "localAmount", label: t("dataPage.localCurrencyAmount", "Devise locale"), align: "right", width: "w-40", render: (item) => <span className="text-dark-4">{formatLocalCurrencyAmount(item)}</span> },
    { key: "source", label: t("dataPage.source", "Source"), render: (item) => item.source.name },
    {
      key: "status",
      label: t("dataPage.status", "Statut"),
      render: (item) => <NevBadge tone={"demo" === item.validationStatus ? "warning" : "success"}>{VALIDATION_STATUS_LABELS[item.validationStatus] || item.validationStatus}</NevBadge>,
    },
  ];

  if (!isAuthenticated()) {
    return <RequireAuth />;
  }

  return (
    <>
      <section className="bg-gradient-to-br from-deep via-deep-2 to-deep-3 pb-[60px] pt-[48px] text-left text-white">
        <div className="container mx-auto px-4">
          <span className="mb-[18px] inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-accent">
            <BanknoteIcon className="h-3 w-3" /> {t("dataPage.pill", "Base de données")}
          </span>
          <h1 className="mb-[14px] text-[clamp(1.7rem,4vw,2.6rem)] font-bold leading-[1.2]">{t("dataPage.title", "Explorer les données de financement")}</h1>
          <p className="max-w-[520px] text-base text-white/80">{t("dataPage.subtitle", "Filtrez et parcourez les données de financement climatique collectées par NEV Climate Data.")}</p>
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
                {yearOptions.map((year) => (
                  <option key={year} value={String(year)}>
                    {year}
                  </option>
                ))}
              </NevSelect>
              <NevSelect
                id="filter-funding-type"
                label={t("dataPage.fundingType", "Type de financement")}
                value={filters.fundingType}
                onChange={(event) => setFilters({ ...filters, fundingType: event.target.value })}
              >
                <option value="">{t("dataPage.allTypes", "Tous les types")}</option>
                <option value="public">{t("dataPage.typePublic", "Bilatéral")}</option>
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
            {("data" === status || "empty" === status) && (
              <p className="text-sm font-semibold text-dark" aria-live="polite">
                {t("dataPage.totalAmount", "Montant total filtré")} :{" "}
                <span className="tabular-nums text-primary-dark">
                  {formatAmount(meta.totalAmount)} {DISPLAY_CURRENCY}
                </span>
              </p>
            )}
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
                'Données réelles issues de <code class="font-mono">GET /api/funding</code> (A2.1), collectées par les connecteurs (Banque mondiale, BAD, GCF, AFD, DFC, Norfund, BII, FMO, OCDE CRS). Le statut de validation de chaque ligne (Démonstration / Validée) est indiqué dans la colonne « Statut ».'
              ),
            }}
          />
        </section>
        {/* ====== /Résultats ====== */}
      </div>
    </>
  );
}
