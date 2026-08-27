/**
 * NEV Climate Data — data.html page logic (A2.2).
 *
 * Wires the filters/pagination/table already present in data.html to the
 * real GET /api/funding endpoint (A2.1). Replaces every previously
 * hard-coded row/count/page with data read from the API response —
 * including the sector filter's options, which cannot be hard-coded
 * client-side (Sector.id is a database-generated auto-increment value, not
 * a stable business code the way a country's ISO code is): they are
 * derived from the `sector` objects embedded in real API responses as
 * pages load, deduplicated by id.
 *
 * No framework/bundler exists in this project — plain DOM APIs, matching
 * assets/js/main.js's style.
 */
(function () {
  "use strict";

  const PAGE_SIZE = 10;

  const FUNDING_TYPE_LABELS = {
    public: "Public",
    private: "Privé",
    multilateral: "Multilatéral",
  };

  const VALIDATION_STATUS_LABELS = {
    demo: "Démonstration",
    validated: "Validée",
  };

  // ApiKey.quota and Funding.amount both store USD (the project's pivot
  // currency — see backend/docs/superpowers/specs/2026-08-22-a13-*.md); the
  // API doesn't return a currency field because it never varies, so this is
  // a fixed, correct label rather than a guess.
  const DISPLAY_CURRENCY = "USD";

  const state = {
    page: 1,
    totalPages: 1,
    knownSectorIds: new Set(),
  };

  const els = {
    filterCountry: document.getElementById("filter-country"),
    filterSector: document.getElementById("filter-sector"),
    filterYear: document.getElementById("filter-year"),
    filterType: document.getElementById("filter-type"),
    filterPeriodStart: document.getElementById("filter-period-start"),
    filterPeriodEnd: document.getElementById("filter-period-end"),
    applyButton: document.getElementById("apply-filters-btn"),
    resetButton: document.getElementById("reset-filters-btn"),
    resultsSummary: document.getElementById("results-summary"),
    stateLoading: document.getElementById("state-loading"),
    stateError: document.getElementById("state-error"),
    stateEmpty: document.getElementById("state-empty"),
    stateData: document.getElementById("state-data"),
    errorMessage: document.getElementById("error-message"),
    tableBody: document.getElementById("funding-table-body"),
    paginationPrev: document.getElementById("pagination-prev"),
    paginationNext: document.getElementById("pagination-next"),
    paginationInfo: document.getElementById("pagination-info"),
    exportButton: document.getElementById("export-csv-btn"),
    exportButtonLabel: document.getElementById("export-csv-btn-label"),
    exportErrorMessage: document.getElementById("export-error-message"),
  };

  function setVisibleState(name) {
    const map = {
      loading: els.stateLoading,
      error: els.stateError,
      empty: els.stateEmpty,
      data: els.stateData,
    };
    Object.entries(map).forEach(function ([key, el]) {
      if (!el) {
        return;
      }
      el.classList.toggle("hidden", key !== name);
    });
  }

  function formatAmount(amount) {
    const value = Number(amount);
    if (Number.isNaN(value)) {
      return amount;
    }
    return value.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatDate(isoDate) {
    const date = new Date(isoDate + "T00:00:00");
    if (Number.isNaN(date.getTime())) {
      return isoDate;
    }
    return date.toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
  }

  function statusBadgeClasses(status) {
    return "demo" === status
      ? "bg-status-demo-bg text-status-demo"
      : "bg-status-validated-bg text-status-validated";
  }

  function currentFilters() {
    return {
      country: els.filterCountry.value,
      sector: els.filterSector.value,
      year: els.filterYear.value,
      fundingType: els.filterType.value,
      periodStart: els.filterPeriodStart.value,
      periodEnd: els.filterPeriodEnd.value,
    };
  }

  function learnSectorsFrom(items) {
    let added = false;
    items.forEach(function (item) {
      const id = item.sector.id;
      if (state.knownSectorIds.has(id)) {
        return;
      }
      state.knownSectorIds.add(id);
      const option = document.createElement("option");
      option.value = String(id);
      option.textContent = item.sector.name;
      els.filterSector.appendChild(option);
      added = true;
    });

    if (added) {
      // Keep the dropdown alphabetical (skip the first "Tous les secteurs" option).
      const placeholder = els.filterSector.firstElementChild;
      const rest = Array.from(els.filterSector.children).slice(1);
      rest.sort(function (a, b) {
        return a.textContent.localeCompare(b.textContent, "fr");
      });
      els.filterSector.innerHTML = "";
      els.filterSector.appendChild(placeholder);
      rest.forEach(function (option) {
        els.filterSector.appendChild(option);
      });
    }
  }

  function renderRows(items) {
    els.tableBody.innerHTML = "";
    items.forEach(function (item) {
      const row = document.createElement("tr");
      row.className = "hover:bg-surface";
      row.innerHTML =
        '<td class="px-5 py-4 font-medium text-dark"></td>' +
        '<td class="px-5 py-4"></td>' +
        '<td class="px-5 py-4"></td>' +
        '<td class="px-5 py-4"></td>' +
        '<td class="px-5 py-4 font-semibold text-deep-3"></td>' +
        '<td class="px-5 py-4"></td>' +
        '<td class="px-5 py-4"></td>' +
        '<td class="px-5 py-4"></td>';

      const cells = row.children;
      cells[0].textContent = item.country.name;
      cells[1].textContent = item.sector.name;
      cells[2].textContent = String(item.year);
      cells[3].textContent = FUNDING_TYPE_LABELS[item.fundingType] || item.fundingType;
      cells[4].textContent = formatAmount(item.amount);
      cells[5].textContent = DISPLAY_CURRENCY;
      cells[6].textContent = item.source.name;

      const badge = document.createElement("span");
      badge.className = "inline-flex rounded-full px-2.5 py-1 text-xs font-semibold " + statusBadgeClasses(item.validationStatus);
      badge.textContent = VALIDATION_STATUS_LABELS[item.validationStatus] || item.validationStatus;
      cells[7].appendChild(badge);

      els.tableBody.appendChild(row);
    });
  }

  function renderMeta(meta, itemCount) {
    state.page = meta.page;
    state.totalPages = meta.totalPages;

    els.resultsSummary.innerHTML =
      '<span class="font-semibold text-dark">' + itemCount + "</span> résultats affichés sur " +
      '<span class="font-semibold text-dark">' + meta.total + "</span> au total";

    els.paginationInfo.textContent = "Page " + meta.page + " sur " + Math.max(meta.totalPages, 1);
    els.paginationPrev.disabled = meta.page <= 1;
    els.paginationNext.disabled = meta.page >= meta.totalPages;
  }

  /**
   * `filterOverrides`, when given, is used instead of currentFilters() -
   * only the very first call (page load, seeded from the URL - see
   * filtersFromUrl()) needs this: filter-sector's <option>s don't exist
   * yet at that point (learnSectorsFrom() only populates them from a real
   * API response), so a country/global-search click on a Sector result
   * (data.html?sector=<id>) can't be reflected by pre-setting the
   * <select>'s value the way filter-country/-year/-type can. Every other
   * caller (Apply, Reset, pagination) omits it and reads the DOM as before.
   */
  async function loadFunding(page, filterOverrides) {
    setVisibleState("loading");

    try {
      const filters = filterOverrides || currentFilters();
      const response = await NevApi.fetchFunding({
        country: filters.country,
        sector: filters.sector,
        year: filters.year,
        fundingType: filters.fundingType,
        periodStart: filters.periodStart,
        periodEnd: filters.periodEnd,
        page: page,
        limit: PAGE_SIZE,
      });

      learnSectorsFrom(response.data);

      // Now that learnSectorsFrom() may have just created the matching
      // <option>, reflect the URL-supplied sector in the dropdown itself -
      // otherwise the filter shown in the UI ("Tous les secteurs") would
      // silently disagree with the data actually on screen.
      if (filterOverrides && filterOverrides.sector) {
        els.filterSector.value = filterOverrides.sector;
      }

      renderMeta(response.meta, response.data.length);

      if (0 === response.data.length) {
        setVisibleState("empty");
        return;
      }

      renderRows(response.data);
      setVisibleState("data");
    } catch (error) {
      els.resultsSummary.textContent = "";
      els.errorMessage.textContent = error.message;
      setVisibleState("error");
    }
  }

  els.applyButton.addEventListener("click", function () {
    loadFunding(1);
  });

  els.resetButton.addEventListener("click", function () {
    els.filterCountry.value = "";
    els.filterSector.value = "";
    els.filterYear.value = "";
    els.filterType.value = "";
    els.filterPeriodStart.value = "";
    els.filterPeriodEnd.value = "";
    // Without this, a filter that arrived via the URL (global search, or a
    // shared/bookmarked link) would silently come back on the next reload
    // even after the user explicitly cleared it here.
    window.history.replaceState(null, "", window.location.pathname);
    loadFunding(1);
  });

  els.paginationPrev.addEventListener("click", function () {
    if (state.page > 1) {
      loadFunding(state.page - 1);
    }
  });

  els.paginationNext.addEventListener("click", function () {
    if (state.page < state.totalPages) {
      loadFunding(state.page + 1);
    }
  });

  /**
   * GET /api/funding/export (A2.3) - reuses currentFilters(), the exact
   * same filter values loadFunding() sends, so what gets exported always
   * matches what's currently on screen. Requires a session (the endpoint is
   * authenticated, unlike GET /api/funding): the button stays hidden for an
   * anonymous visitor rather than surfacing a 401 on click.
   */
  function exportFilenameFromResponse(response, fallback) {
    const header = response.headers.get("Content-Disposition") || "";
    const match = header.match(/filename="?([^";]+)"?/);
    return match ? match[1] : fallback;
  }

  async function exportCsv() {
    els.exportErrorMessage.classList.add("hidden");
    els.exportButton.disabled = true;
    els.exportButtonLabel.textContent = "Export en cours…";

    try {
      const filters = currentFilters();
      const url = new URL(NevApi.API_BASE_URL + "/api/funding/export");
      Object.entries(filters).forEach(function ([key, value]) {
        if (value) {
          url.searchParams.set(key, value);
        }
      });

      const response = await NevAuth.authorizedFetch(url.toString(), {
        headers: { Accept: "text/csv" },
      });

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
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = exportFilenameFromResponse(response, "funding-export.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(objectUrl);
    } catch (error) {
      els.exportErrorMessage.textContent = error.message;
      els.exportErrorMessage.classList.remove("hidden");
    } finally {
      els.exportButton.disabled = false;
      els.exportButtonLabel.textContent = "Exporter (CSV)";
    }
  }

  els.exportButton.addEventListener("click", exportCsv);

  if (NevAuth.isAuthenticated()) {
    els.exportButton.classList.remove("hidden");
    els.exportButton.classList.add("inline-flex");
  }

  /**
   * Seeds the filters from the URL's own query string on page load - the
   * fix for global search (A2.8): a Country/Sector result's destination is
   * "data.html?country=SEN"/"data.html?sector=<id>" (see
   * backend/src/Service/SearchService.php), and until this existed nothing
   * on this page ever read it back. Also what makes the URL genuinely
   * shareable/bookmarkable and makes a filtered view survive a reload
   * (the URL itself doesn't change across a reload).
   *
   * @returns {{country: string, sector: string, year: string, fundingType: string, periodStart: string, periodEnd: string}}
   */
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

  const initialFilters = filtersFromUrl();
  // filter-sector is deliberately left alone here - see loadFunding()'s
  // filterOverrides handling above for why it can't be pre-set the way the
  // other three (static <option>s) can.
  els.filterCountry.value = initialFilters.country;
  els.filterYear.value = initialFilters.year;
  els.filterType.value = initialFilters.fundingType;
  els.filterPeriodStart.value = initialFilters.periodStart;
  els.filterPeriodEnd.value = initialFilters.periodEnd;

  loadFunding(1, initialFilters);
})();
