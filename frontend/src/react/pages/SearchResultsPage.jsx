import { useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import { COUNTRY_GROUPS } from "../data/africaCountries";
import { INSTRUMENT_LABELS } from "../data/analyticsConstants";
import NevCard from "../components/ui/NevCard";
import NevSelect from "../components/ui/NevSelect";
import NevInput from "../components/ui/NevInput";
import NevButton from "../components/ui/NevButton";
import NevDataState from "../components/ui/NevDataState";
import { SearchIcon, GlobeIcon, BoltIcon, LinkIcon, DocumentIcon, BuildingIcon, SparklesIcon } from "../components/ui/icons";

const MIN_LENGTH = 2; // matches backend/src/Dto/SearchQuery.php

const CATEGORY_ORDER = ["country", "sector", "source", "report", "project", "opportunity"];
const CATEGORY_LABELS = {
  country: "Pays",
  sector: "Secteurs",
  source: "Sources",
  report: "Rapports",
  project: "Projets",
  opportunity: "Appels à projets",
};
const CATEGORY_ICONS = {
  country: GlobeIcon,
  sector: BoltIcon,
  source: LinkIcon,
  report: DocumentIcon,
  project: BuildingIcon,
  opportunity: SparklesIcon,
};

const EMPTY_FILTERS = { country: "", instrument: "", year: "", minAmount: "" };

function stateFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return {
    term: params.get("q") || "",
    filters: {
      country: params.get("country") || "",
      instrument: params.get("instrument") || "",
      year: params.get("year") || "",
      minAmount: params.get("minAmount") || "",
    },
  };
}

function groupByType(results) {
  const byType = {};
  results.forEach((result) => {
    (byType[result.type] = byType[result.type] || []).push(result);
  });
  return CATEGORY_ORDER.filter((type) => byType[type] && byType[type].length > 0).map((type) => ({ type, items: byType[type] }));
}

function buildQueryString(term, filters) {
  const params = new URLSearchParams();
  if (term) {
    params.set("q", term);
  }
  Object.entries(filters).forEach(([key, value]) => {
    if (value) {
      params.set(key, value);
    }
  });

  return params.toString();
}

/**
 * C1.3: full-page counterpart to GlobalSearch.jsx's compact navbar
 * dropdown (still capped at 5 results/type) - lets a visitor apply the
 * facets GET /api/search gained in C1.3 (country/instrument/year/
 * minAmount) and read every result, not just the first few per type.
 *
 * "sector" is deliberately not offered as a filter here either, same
 * reasoning as C1.2's FilterableCountryMap: no endpoint currently exposes
 * Sector.id alongside its name for a dropdown to use. The backend facet
 * (App\Dto\SearchQuery::$sectorId) is ready for a future UI once one does.
 */
export default function SearchResultsPage() {
  const { API_BASE_URL } = useAuth();
  const { t } = useI18n();

  const initial = stateFromUrl();
  const [term, setTerm] = useState(initial.term);
  const [filters, setFilters] = useState(initial.filters);
  const [state, setState] = useState("empty"); // empty | loading | success | error
  const [message, setMessage] = useState("");
  const [groups, setGroups] = useState([]);
  const [resultCount, setResultCount] = useState(0);

  async function runSearch(searchTerm, searchFilters) {
    const trimmed = searchTerm.trim();
    if (trimmed.length < MIN_LENGTH) {
      setState("empty");
      setMessage(t("searchPage.tooShort", "Saisissez au moins 2 caractères pour lancer une recherche."));
      return;
    }

    setState("loading");
    const query = buildQueryString(trimmed, searchFilters);
    window.history.replaceState(null, "", window.location.pathname + (query ? "?" + query : ""));

    let response;
    try {
      response = await fetch(API_BASE_URL + "/api/search?" + query, { headers: { Accept: "application/json" } });
    } catch (networkError) {
      setState("error");
      setMessage(t("searchPage.networkError", "Impossible de contacter le serveur."));
      return;
    }

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      setState("error");
      setMessage(body && body.message ? body.message : t("searchPage.genericError", "Une erreur est survenue."));
      return;
    }

    const body = await response.json();
    setResultCount(body.results.length);
    if (0 === body.results.length) {
      setState("empty");
      setMessage(t("searchPage.noResults", "Aucun résultat pour ces critères."));
      return;
    }
    setGroups(groupByType(body.results));
    setState("success");
  }

  useEffect(() => {
    if (initial.term.trim().length >= MIN_LENGTH) {
      runSearch(initial.term, initial.filters);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleSubmit(event) {
    event.preventDefault();
    runSearch(term, filters);
  }

  function resetFilters() {
    const next = { ...EMPTY_FILTERS };
    setFilters(next);
    runSearch(term, next);
  }

  return (
    <div className="container mx-auto px-4 py-10">
      <h1 className="mb-2 flex items-center gap-2 text-2xl font-bold text-dark">
        <SearchIcon className="h-6 w-6 text-primary" />
        {t("searchPage.title", "Recherche")}
      </h1>
      <p className="mb-6 text-sm text-body-color">
        {t("searchPage.subtitle", "Pays, secteurs, sources, rapports publiés, projets et appels à projets - filtrables par pays, instrument, année et montant minimum.")}
      </p>

      <NevCard padding="md" className="mb-8">
        <form onSubmit={handleSubmit}>
          <div className="mb-4">
            <NevInput
              id="search-term"
              label={t("searchPage.term", "Terme recherché")}
              placeholder={t("searchPage.termPlaceholder", "Ex : Sénégal, énergie renouvelable...")}
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              required
            />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <NevSelect id="search-country" label={t("searchPage.country", "Pays")} value={filters.country} onChange={(event) => setFilters({ ...filters, country: event.target.value })}>
              <option value="">{t("searchPage.allCountries", "Tous les pays")}</option>
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
            <NevSelect
              id="search-instrument"
              label={t("searchPage.instrument", "Instrument financier")}
              value={filters.instrument}
              onChange={(event) => setFilters({ ...filters, instrument: event.target.value })}
              hint={t("searchPage.instrumentHint", "S'applique uniquement aux projets")}
            >
              <option value="">{t("searchPage.allInstruments", "Tous les instruments")}</option>
              {Object.entries(INSTRUMENT_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NevSelect>
            <NevInput
              id="search-year"
              type="number"
              label={t("searchPage.year", "Année")}
              placeholder={t("searchPage.yearPlaceholder", "Ex : 2025")}
              value={filters.year}
              onChange={(event) => setFilters({ ...filters, year: event.target.value })}
              hint={t("searchPage.yearHint", "Rapports, projets, appels à projets")}
            />
            <NevInput
              id="search-min-amount"
              type="number"
              min="0"
              label={t("searchPage.minAmount", "Montant minimum (USD)")}
              placeholder={t("searchPage.minAmountPlaceholder", "Ex : 500000")}
              value={filters.minAmount}
              onChange={(event) => setFilters({ ...filters, minAmount: event.target.value })}
              hint={t("searchPage.minAmountHint", "Projets et appels à projets")}
            />
          </div>
          <div className="mt-4 flex flex-wrap gap-3">
            <NevButton type="submit">{t("searchPage.search", "Rechercher")}</NevButton>
            <NevButton type="button" variant="outline" onClick={resetFilters}>
              {t("searchPage.resetFilters", "Réinitialiser les filtres")}
            </NevButton>
          </div>
        </form>
      </NevCard>

      <NevDataState
        state={state}
        loadingText={t("searchPage.loading", "Recherche en cours…")}
        emptyText={message || t("searchPage.startTyping", "Saisissez un terme pour commencer.")}
        errorText={message}
        onRetry={"error" === state ? () => runSearch(term, filters) : undefined}
      >
        <div className="mb-4 text-sm text-body-color" aria-live="polite">
          {t("searchPage.resultCount", "{count} résultat(s)").replace("{count}", resultCount)}
        </div>
        <div className="space-y-8">
          {groups.map((group) => {
            const CategoryIcon = CATEGORY_ICONS[group.type];
            return (
              <section key={group.type}>
                <h2 className="mb-3 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-dark-5">
                  {CategoryIcon ? <CategoryIcon className="h-4 w-4" /> : null}
                  {CATEGORY_LABELS[group.type] || group.type}
                  <span className="font-normal normal-case text-dark-5/70">({group.items.length})</span>
                </h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {group.items.map((result) => (
                    <a
                      key={group.type + '-' + result.id}
                      href={result.destination}
                      className="block rounded-lg border border-stroke bg-white p-4 transition hover:border-primary hover:shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                      <span className="block text-sm font-semibold text-dark">{result.title}</span>
                      <span className="mt-1 block text-xs text-body-color">{result.description}</span>
                    </a>
                  ))}
                </div>
              </section>
            );
          })}
        </div>
      </NevDataState>
    </div>
  );
}
