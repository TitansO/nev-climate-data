import { useEffect, useRef, useState } from "react";
import { useAuth } from "../providers/AuthProvider";

/**
 * React port of assets/js/search.js (A2.8/A2.9). Every keystroke that
 * changes the effective query is debounced and sent to GET /api/search -
 * this never filters a locally-loaded list, there is nothing loaded
 * locally to filter. Same category grouping/order/labels as the original.
 */
const MIN_LENGTH = 2; // matches backend/src/Dto/SearchQuery.php
const DEBOUNCE_MS = 300;

const CATEGORY_ORDER = ["country", "sector", "source", "report"];
const CATEGORY_LABELS = {
  country: "🌍 Pays",
  sector: "⚡ Secteurs",
  source: "🔗 Sources",
  report: "📄 Rapports",
};

function groupByType(results) {
  const byType = {};
  results.forEach((result) => {
    (byType[result.type] = byType[result.type] || []).push(result);
  });
  return CATEGORY_ORDER.filter((type) => byType[type] && byType[type].length > 0).map((type) => ({ type, items: byType[type] }));
}

export default function GlobalSearch() {
  const { API_BASE_URL } = useAuth();
  const [term, setTerm] = useState("");
  const [status, setStatus] = useState("idle"); // idle | loading | message | results
  const [message, setMessage] = useState("");
  const [groups, setGroups] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const debounceTimer = useRef(null);
  const requestToken = useRef(0);
  const containerRef = useRef(null);

  async function runSearch(query) {
    const myToken = ++requestToken.current;
    setStatus("message");
    setMessage("Recherche…");
    setPanelOpen(true);

    let response;
    try {
      const url = new URL(API_BASE_URL + "/api/search", window.location.origin);
      url.searchParams.set("q", query);
      response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
    } catch (networkError) {
      if (myToken === requestToken.current) {
        setStatus("message");
        setMessage("Impossible de contacter le serveur.");
      }
      return;
    }

    if (myToken !== requestToken.current) {
      return; // a newer search has since started - drop this stale response
    }

    if (!response.ok) {
      const body = await response.json().catch(() => null);
      setStatus("message");
      setMessage(body && body.message ? body.message : "Une erreur est survenue.");
      return;
    }

    const body = await response.json();
    if (0 === body.results.length) {
      setStatus("message");
      setMessage("Aucun résultat pour ces mots-clés.");
      return;
    }
    setStatus("results");
    setGroups(groupByType(body.results));
  }

  function handleQueryChange(nextTerm, immediate) {
    setTerm(nextTerm);

    if (debounceTimer.current) {
      clearTimeout(debounceTimer.current);
      debounceTimer.current = null;
    }

    const trimmed = nextTerm.trim();
    if (trimmed.length < MIN_LENGTH) {
      setPanelOpen(false);
      return;
    }

    if (immediate) {
      runSearch(trimmed);
    } else {
      debounceTimer.current = setTimeout(() => runSearch(trimmed), DEBOUNCE_MS);
    }
  }

  useEffect(() => {
    function onDocumentClick(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setPanelOpen(false);
      }
    }
    document.addEventListener("click", onDocumentClick);
    return () => document.removeEventListener("click", onDocumentClick);
  }, []);

  return (
    <label className="relative" ref={containerRef}>
      <span className="sr-only">Rechercher (fonctionnalité à venir)</span>
      <svg className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-dark-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M18 11a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
      <input
        type="search"
        id="global-search-input"
        placeholder="Rechercher des données…"
        autoComplete="off"
        title="Recherche globale"
        value={term}
        onChange={(event) => handleQueryChange(event.target.value, false)}
        onKeyDown={(event) => {
          if ("Enter" === event.key) {
            event.preventDefault();
            handleQueryChange(term, true);
          } else if ("Escape" === event.key) {
            setPanelOpen(false);
            event.currentTarget.blur();
          }
        }}
        onFocus={() => {
          if (term.trim().length >= MIN_LENGTH && ("message" === status || "results" === status)) {
            setPanelOpen(true);
          }
        }}
        className="w-56 rounded-md border-0 bg-white/95 py-2 pl-9 pr-3 text-sm text-dark shadow-input placeholder:text-dark-5 focus:outline-none focus:ring-2 focus:ring-primary"
      />
      <div
        id="global-search-results"
        className={"absolute left-0 right-0 top-full z-50 mt-2 max-h-96 w-80 overflow-y-auto rounded-md border border-stroke bg-white p-2 text-left shadow-2" + (panelOpen ? "" : " hidden")}
      >
        {"message" === status && <p className="px-3 py-4 text-center text-sm text-body-color">{message}</p>}
        {"results" === status && (
          <div className="divide-y divide-stroke">
            {groups.map((group) => (
              <div key={group.type} className="py-1.5">
                <p className="px-3 pb-1 pt-1 text-[11px] font-bold uppercase tracking-wide text-dark-5">{CATEGORY_LABELS[group.type] || group.type}</p>
                <ul>
                  {group.items.map((result, index) => (
                    <li key={result.destination + index}>
                      <a href={result.destination} className="block rounded-md px-3 py-2 hover:bg-surface">
                        <span className="block text-sm font-semibold text-dark">{result.title}</span>
                        <span className="block text-xs text-body-color">{result.description}</span>
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </div>
    </label>
  );
}
