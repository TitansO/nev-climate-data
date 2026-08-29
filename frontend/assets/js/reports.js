/**
 * NEV Climate Data - reports.html wiring (A2.13).
 *
 * Connects the filter pills and the report grid to GET /api/reports (A2.13,
 * public - no login requirement, same reasoning as data.js/api.js). Each
 * card's download link points straight at
 * GET /api/reports/{id}/download - a plain anchor, not a fetch: the
 * endpoint is a public, trackable file download, no different from a
 * regular link, and letting the browser handle it natively means no extra
 * JS is needed to save the file.
 *
 * No framework/bundler exists in this project - plain DOM APIs and a
 * global namespace convention, matching assets/js/data.js.
 */
(function () {
  "use strict";

  const PAGE_SIZE = 12;

  // Maps a report's raw `type` (as stored in the database - see
  // backend/src/DataFixtures/ReportFixtures.php) to the badge color and
  // i18n label key used across this page's filter pills and card badges,
  // so the two stay visually and textually consistent.
  const TYPE_META = {
    "Annual Report": { badgeBg: "bg-status-validated-bg", badgeText: "text-status-validated", iconBg: "bg-deep-3", i18nKey: "reportsPage.typeAnnual" },
    "Regional Report": { badgeBg: "bg-status-review-bg", badgeText: "text-status-review", iconBg: "bg-primary", i18nKey: "reportsPage.typeRegional" },
    "Country Report": { badgeBg: "bg-status-demo-bg", badgeText: "text-status-demo", iconBg: "bg-status-demo", i18nKey: "reportsPage.typeCountry" },
    "Sector Report": { badgeBg: "bg-status-review-bg", badgeText: "text-status-review", iconBg: "bg-status-review", i18nKey: "reportsPage.typeSectorStudy" },
  };
  const DEFAULT_TYPE_META = { badgeBg: "bg-dark-8", badgeText: "text-dark-4", iconBg: "bg-deep-3", i18nKey: null };

  const state = { page: 1, type: "" };

  const els = {
    filtersContainer: document.getElementById("reports-filters"),
    summary: document.getElementById("reports-summary"),
    stateLoading: document.getElementById("reports-state-loading"),
    stateError: document.getElementById("reports-state-error"),
    stateEmpty: document.getElementById("reports-state-empty"),
    grid: document.getElementById("reports-grid"),
    pagination: document.getElementById("reports-pagination"),
    paginationInfo: document.getElementById("reports-pagination-info"),
    paginationPrev: document.getElementById("reports-pagination-prev"),
    paginationNext: document.getElementById("reports-pagination-next"),
    cardTemplate: document.getElementById("report-card-template"),
  };

  if (!els.grid || !els.cardTemplate) {
    return; // Not on reports.html.
  }

  function translate(key, fallback) {
    return window.NevI18n ? window.NevI18n.t(key) : fallback;
  }

  function formatDate(isoDate) {
    return new Date(isoDate + "T00:00:00").toLocaleDateString("fr-FR", { year: "numeric", month: "short", day: "2-digit" });
  }

  function show(el) {
    [els.stateLoading, els.stateError, els.stateEmpty, els.grid].forEach(function (candidate) {
      candidate.classList.toggle("hidden", candidate !== el);
    });
  }

  function buildMetaRow(labelKey, labelFallback, value) {
    const row = document.createElement("div");
    row.className = "flex justify-between gap-3";
    const dt = document.createElement("dt");
    dt.setAttribute("data-i18n", labelKey);
    dt.textContent = translate(labelKey, labelFallback);
    const dd = document.createElement("dd");
    dd.className = "font-medium text-dark-3";
    dd.textContent = value;
    row.append(dt, dd);
    return row;
  }

  function renderCard(report) {
    const fragment = els.cardTemplate.content.cloneNode(true);
    const meta = TYPE_META[report.type] || DEFAULT_TYPE_META;

    const icon = fragment.querySelector(".report-icon");
    icon.className = "report-icon flex h-12 w-12 items-center justify-center rounded-xl text-white " + meta.iconBg;

    const badge = fragment.querySelector(".report-badge");
    badge.className = "report-badge rounded-full px-3 py-1 text-xs font-semibold " + meta.badgeBg + " " + meta.badgeText;
    if (meta.i18nKey) {
      badge.setAttribute("data-i18n", meta.i18nKey);
      badge.textContent = translate(meta.i18nKey, report.type);
    } else {
      badge.textContent = report.type;
    }

    fragment.querySelector(".report-title").textContent = report.title;

    const metaList = fragment.querySelector(".report-meta");
    if (report.publicationDate) {
      metaList.appendChild(buildMetaRow("reportsPage.date", "Date", formatDate(report.publicationDate)));
    }
    if (report.country) {
      metaList.appendChild(buildMetaRow("dataPage.country", "Pays", report.country.name));
    } else if (report.region) {
      metaList.appendChild(buildMetaRow("dataPage.country", "Pays", report.region));
    }
    metaList.appendChild(buildMetaRow("reportsPage.downloads", "Téléchargements", String(report.downloadCount)));

    const downloadLink = fragment.querySelector(".report-download");
    downloadLink.href = window.NevApi.API_BASE_URL + report.downloadUrl;

    return fragment;
  }

  function setActiveFilter(type) {
    els.filtersContainer.querySelectorAll(".report-filter-btn").forEach(function (btn) {
      const active = btn.getAttribute("data-type") === type;
      btn.classList.toggle("bg-primary", active);
      btn.classList.toggle("text-white", active);
      btn.classList.toggle("border", !active);
      btn.classList.toggle("border-stroke", !active);
      btn.classList.toggle("text-dark-4", !active);
    });
  }

  async function loadReports() {
    show(els.stateLoading);
    els.pagination.classList.add("hidden");
    els.pagination.classList.remove("flex", "flex-wrap");

    const url = new URL(window.NevApi.API_BASE_URL + "/api/reports");
    url.searchParams.set("page", String(state.page));
    url.searchParams.set("limit", String(PAGE_SIZE));
    if (state.type) {
      url.searchParams.set("type", state.type);
    }

    let response;
    try {
      response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
    } catch (networkError) {
      show(els.stateError);
      return;
    }

    if (!response.ok) {
      show(els.stateError);
      return;
    }

    const body = await response.json();

    els.summary.textContent = body.meta.total + " rapport" + (body.meta.total > 1 ? "s" : "");

    if (0 === body.data.length) {
      show(els.stateEmpty);
      return;
    }

    els.grid.innerHTML = "";
    body.data.forEach(function (report) {
      els.grid.appendChild(renderCard(report));
    });
    show(els.grid);

    if (body.meta.totalPages > 1) {
      els.pagination.classList.remove("hidden");
      els.pagination.classList.add("flex", "flex-wrap");
      els.paginationInfo.textContent = "Page " + body.meta.page + " sur " + body.meta.totalPages;
      els.paginationPrev.disabled = body.meta.page <= 1;
      els.paginationNext.disabled = body.meta.page >= body.meta.totalPages;
    }
  }

  els.filtersContainer.querySelectorAll(".report-filter-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      state.type = btn.getAttribute("data-type") || "";
      state.page = 1;
      setActiveFilter(state.type);
      loadReports();
    });
  });

  els.paginationPrev.addEventListener("click", function () {
    if (state.page > 1) {
      state.page -= 1;
      loadReports();
    }
  });

  els.paginationNext.addEventListener("click", function () {
    state.page += 1;
    loadReports();
  });

  loadReports();
})();
