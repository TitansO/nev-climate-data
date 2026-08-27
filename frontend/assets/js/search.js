/**
 * NEV Climate Data - global search (A2.8).
 *
 * Powers the navbar search box present on 7 pages (about, api-docs, data,
 * index, reports, sources, visualizations). Every keystroke that changes
 * the effective query is debounced and sent to GET /api/search - this
 * never filters a locally-loaded list, there is nothing loaded locally to
 * filter.
 *
 * No framework/bundler exists in this project - plain DOM APIs, matching
 * the rest of assets/js/*.
 */
(function (global) {
  "use strict";

  const MIN_LENGTH = 2; // matches backend/src/Dto/SearchQuery.php
  const DEBOUNCE_MS = 300;

  // A2.9: the display order for grouped categories - matches the order
  // App\Service\SearchService already returns results in (country, sector,
  // source, report), so this doesn't reorder anything the backend sends,
  // it only decides which categories get a heading and in what sequence.
  const CATEGORY_ORDER = ["country", "sector", "source", "report"];

  const CATEGORY_LABELS = {
    country: "🌍 Pays",
    sector: "⚡ Secteurs",
    source: "🔗 Sources",
    report: "📄 Rapports",
  };

  function resolveApiBaseUrl() {
    const host = global.location.hostname;
    if (host === "localhost" || host === "127.0.0.1") {
      return "http://localhost:8080";
    }
    const codespaceMatch = host.match(/^(.+)-8123\.app\.github\.dev$/);
    if (codespaceMatch) {
      return "https://" + codespaceMatch[1] + "-8080.app.github.dev";
    }
    return "http://localhost:8080";
  }

  const API_BASE_URL = resolveApiBaseUrl();

  function initSearch() {
    const input = document.getElementById("global-search-input");
    const panel = document.getElementById("global-search-results");
    if (!input || !panel) {
      return;
    }

    let debounceTimer = null;
    let requestToken = 0; // guards against an older, slower request overwriting a newer one

    function showPanel() {
      panel.classList.remove("hidden");
    }

    function hidePanel() {
      panel.classList.add("hidden");
      panel.innerHTML = "";
    }

    function renderMessage(text) {
      panel.innerHTML = "";
      const p = document.createElement("p");
      p.className = "px-3 py-4 text-center text-sm text-body-color";
      p.textContent = text;
      panel.appendChild(p);
      showPanel();
    }

    /**
     * A2.9: groups the flat `results` array (as returned by the API) into
     * sections by `type`, one heading per non-empty category, in
     * CATEGORY_ORDER - a category with zero matches simply never gets a
     * section (nothing to hide, nothing rendered). Only the type actually
     * present in the response drives what's shown - never a hard-coded
     * assumption that all 4 always have results.
     */
    function groupByType(results) {
      const byType = {};
      results.forEach(function (result) {
        (byType[result.type] = byType[result.type] || []).push(result);
      });
      return CATEGORY_ORDER.filter(function (type) {
        return byType[type] && byType[type].length > 0;
      }).map(function (type) {
        return { type: type, items: byType[type] };
      });
    }

    function renderResults(results) {
      panel.innerHTML = "";

      if (0 === results.length) {
        renderMessage("Aucun résultat pour ces mots-clés.");
        return;
      }

      const container = document.createElement("div");
      container.className = "divide-y divide-stroke";

      groupByType(results).forEach(function (group) {
        const section = document.createElement("div");
        section.className = "py-1.5";

        const heading = document.createElement("p");
        heading.className = "px-3 pb-1 pt-1 text-[11px] font-bold uppercase tracking-wide text-dark-5";
        heading.textContent = CATEGORY_LABELS[group.type] || group.type;
        section.appendChild(heading);

        const list = document.createElement("ul");
        group.items.forEach(function (result) {
          const li = document.createElement("li");
          const link = document.createElement("a");
          link.href = result.destination;
          link.className = "block rounded-md px-3 py-2 hover:bg-surface";

          const title = document.createElement("span");
          title.className = "block text-sm font-semibold text-dark";
          title.textContent = result.title;

          const description = document.createElement("span");
          description.className = "block text-xs text-body-color";
          description.textContent = result.description;

          link.appendChild(title);
          link.appendChild(description);
          li.appendChild(link);
          list.appendChild(li);
        });
        section.appendChild(list);
        container.appendChild(section);
      });

      panel.appendChild(container);
      showPanel();
    }

    async function runSearch(term) {
      const myToken = ++requestToken;
      renderMessage("Recherche…");

      let response;
      try {
        const url = new URL(API_BASE_URL + "/api/search");
        url.searchParams.set("q", term);
        response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (myToken === requestToken) {
          renderMessage("Impossible de contacter le serveur.");
        }
        return;
      }

      if (myToken !== requestToken) {
        return; // a newer search has since started - drop this stale response
      }

      if (!response.ok) {
        const body = await response.json().catch(function () {
          return null;
        });
        renderMessage(body && body.message ? body.message : "Une erreur est survenue.");
        return;
      }

      const body = await response.json();
      renderResults(body.results);
    }

    function handleQueryChange(immediate) {
      const term = input.value.trim();

      if (debounceTimer) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
      }

      if (term.length < MIN_LENGTH) {
        hidePanel();
        return;
      }

      if (immediate) {
        runSearch(term);
      } else {
        debounceTimer = setTimeout(function () {
          runSearch(term);
        }, DEBOUNCE_MS);
      }
    }

    input.addEventListener("input", function () {
      handleQueryChange(false);
    });

    input.addEventListener("keydown", function (event) {
      if ("Enter" === event.key) {
        event.preventDefault();
        handleQueryChange(true);
      } else if ("Escape" === event.key) {
        hidePanel();
        input.blur();
      }
    });

    input.addEventListener("focus", function () {
      if (input.value.trim().length >= MIN_LENGTH && panel.innerHTML) {
        showPanel();
      }
    });

    document.addEventListener("click", function (event) {
      if (event.target !== input && !panel.contains(event.target)) {
        hidePanel();
      }
    });
  }

  document.addEventListener("DOMContentLoaded", initSearch);
})(window);
