/**
 * NEV Climate Data - visualizations.html chart data (A2.5/A2.6).
 *
 * Loads every real figure on this page - the KPI band, the financing-by-type
 * donut, the sector distribution donut + ranking, the financing-by-year bar
 * chart and its detail table - from the 2 real aggregate endpoints
 * (/api/analytics/financing-trends, /api/analytics/sector-distribution) plus
 * /api/analytics/hero-stats and /api/analytics/co2-reduction. Every derived
 * figure (grand totals, type shares, the donut's center label) is computed
 * client-side from those same real payloads - nothing here is invented or
 * hard-coded. Chart.js instances are created only once real data is
 * available - never seeded with placeholder numbers, so there is no window
 * where a mocked value could be mistaken for a real one.
 *
 * Each visual block is an independent state machine (loading / success /
 * empty / error), matching data.js's setVisibleState() pattern: a failure on
 * one never blocks the others.
 *
 * No framework/bundler exists in this project - plain DOM APIs, matching
 * the rest of assets/js/*.
 */
(function (global) {
  "use strict";

  function resolveApiBaseUrl() {
    const host = global.location.hostname;
    if (host === "localhost" || host === "127.0.0.1") {
      return "http://localhost:8080";
    }
    const codespaceMatch = host.match(/^(.+)-8123\.app\.github\.dev$/);
    if (codespaceMatch) {
      return "https://" + codespaceMatch[1] + "-8080.app.github.dev";
    }
    // Production (Netlify): relative URLs, proxied server-side by the
    // host's own routing to the real backend - see api.js's
    // resolveApiBaseUrl() docblock for the full reasoning.
    return "";
  }

  const API_BASE_URL = resolveApiBaseUrl();

  const SECTOR_LABELS = {
    "Renewable Energy": "Énergies renouvelables",
    "Sustainable Transport": "Transport durable",
    Agriculture: "Agriculture",
    Forestry: "Foresterie",
    Adaptation: "Adaptation",
  };

  const SECTOR_COLORS = ["#16a34a", "#22c55e", "#86efac", "#14532d", "#0b3d24"];

  // Same 3 colors used consistently across every chart on this page that
  // breaks financing down by type (the bar chart and the donut) - so a
  // color always means the same type no matter which chart it's in.
  const TYPE_COLORS = { public: "#16a34a", private: "#052e1c", multilateral: "#2563eb" };
  const TYPE_LABELS = { public: "Public", private: "Privé", multilateral: "Multilatéral" };

  async function fetchAnalytics(path) {
    let response;
    try {
      response = await fetch(API_BASE_URL + path, { headers: { Accept: "application/json" } });
    } catch (networkError) {
      throw new Error("Impossible de contacter le serveur.");
    }

    if (!response.ok) {
      throw new Error("Une erreur est survenue (" + response.status + ").");
    }

    return response.json();
  }

  function formatCompactUsd(value) {
    return new Intl.NumberFormat("fr-FR", { notation: "compact", maximumFractionDigits: 1 }).format(value) + " USD";
  }

  function formatUsd(value) {
    return Math.round(value).toLocaleString("fr-FR") + " USD";
  }

  function formatCount(value) {
    return Number(value).toLocaleString("fr-FR");
  }

  /**
   * Shared chart-card state machine: swaps the "#<id>-state" message area
   * against the chart's own content element - either "#<id>" directly (a
   * bare canvas, e.g. the bar chart) or "#<id>-wrapper" when the chart is
   * paired with extra markup (the donuts' center label / ranking list).
   * "loading"/"empty"/"error" show a message and hide the content;
   * "success" hides the message and reveals it.
   */
  function setChartState(baseId, state, options) {
    options = options || {};
    const stateEl = document.getElementById(baseId + "-state");
    const contentEl = document.getElementById(baseId + "-wrapper") || document.getElementById(baseId);
    if (!stateEl || !contentEl) {
      return;
    }

    contentEl.classList.toggle("hidden", "success" !== state);
    // stateEl carries its own min-h-[...] (so the loading/error message
    // isn't cramped) - clearing its text alone left that min-height
    // reserved as dead empty space once the real chart was showing
    // underneath it. Hide the element itself, not just its content.
    // "flex" is toggled here rather than sitting statically in the HTML
    // alongside "hidden" - two utility classes that both set `display`
    // race on CSS source order, not DOM class order, so whichever one
    // Tailwind happened to emit last would always win regardless of which
    // is actually applied. Adding/removing "flex" together with "hidden"
    // sidesteps that entirely - same convention as data.js's
    // exportControls (classList.remove("hidden") + classList.add("flex")).
    stateEl.classList.toggle("hidden", "success" === state);
    stateEl.classList.toggle("flex", "success" !== state);

    if ("success" === state) {
      stateEl.innerHTML = "";
      return;
    }

    if ("loading" === state) {
      stateEl.textContent = "Chargement…";
      return;
    }

    if ("empty" === state) {
      stateEl.textContent = "Donnée non disponible.";
      return;
    }

    // error
    stateEl.innerHTML = "";
    const message = document.createElement("p");
    message.textContent = options.message || "Une erreur est survenue.";
    stateEl.appendChild(message);

    if (options.onRetry) {
      const retryBtn = document.createElement("button");
      retryBtn.type = "button";
      retryBtn.className = "rounded-md border border-stroke px-4 py-1.5 text-xs font-semibold text-dark-4 transition hover:bg-gray-2";
      retryBtn.textContent = "Réessayer";
      retryBtn.addEventListener("click", options.onRetry);
      stateEl.appendChild(retryBtn);
    }
  }

  async function loadKpiHeroStats() {
    const els = {
      countries: document.getElementById("kpi-countries"),
      sectors: document.getElementById("kpi-sectors"),
      sources: document.getElementById("kpi-sources"),
    };
    if (!els.countries || !els.sectors || !els.sources) {
      return;
    }

    try {
      const body = await fetchAnalytics("/api/analytics/hero-stats");
      els.countries.textContent = formatCount(body.countriesCovered);
      els.sectors.textContent = formatCount(body.sectorsTracked);
      els.sources.textContent = formatCount(body.activeSources);
    } catch (error) {
      [els.countries, els.sectors, els.sources].forEach(function (el) {
        el.textContent = "-";
      });
    }
  }

  /**
   * Powers 3 blocks from the single financing-trends payload: the KPI
   * band's "Financement total" tile, the financing-by-type donut (with its
   * center label), and the by-year bar chart + detail table - so they can
   * never disagree with each other about the underlying numbers.
   */
  async function loadFinancingTrends() {
    setChartState("financingChart", "loading");
    setChartState("typeChart", "loading");

    try {
      const body = await fetchAnalytics("/api/analytics/financing-trends");

      if (0 === body.data.length) {
        setChartState("financingChart", "empty");
        setChartState("typeChart", "empty");
        return;
      }

      const totals = body.data.reduce(
        function (acc, row) {
          acc.public += row.public;
          acc.private += row.private;
          acc.multilateral += row.multilateral;
          acc.grandTotal += row.total;
          return acc;
        },
        { public: 0, private: 0, multilateral: 0, grandTotal: 0 }
      );

      const kpiTotalEl = document.getElementById("kpi-funding-total");
      if (kpiTotalEl) {
        kpiTotalEl.textContent = formatCompactUsd(totals.grandTotal);
      }

      renderFinancingBarChart(body.data);
      renderFinancingTable(body.data);
      renderTypeDonut(totals);
    } catch (error) {
      setChartState("financingChart", "error", { message: error.message, onRetry: loadFinancingTrends });
      setChartState("typeChart", "error", { message: error.message, onRetry: loadFinancingTrends });
    }
  }

  function renderFinancingBarChart(rows) {
    setChartState("financingChart", "success");
    new Chart(document.getElementById("financingChart"), {
      type: "bar",
      data: {
        labels: rows.map(function (row) {
          return String(row.period);
        }),
        datasets: [
          { label: TYPE_LABELS.public, data: rows.map((r) => r.public), backgroundColor: TYPE_COLORS.public, borderRadius: 4 },
          { label: TYPE_LABELS.private, data: rows.map((r) => r.private), backgroundColor: TYPE_COLORS.private, borderRadius: 4 },
          { label: TYPE_LABELS.multilateral, data: rows.map((r) => r.multilateral), backgroundColor: TYPE_COLORS.multilateral, borderRadius: 4 },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } },
        scales: {
          y: { beginAtZero: true, grid: { color: "rgba(5,46,28,0.06)" }, title: { display: true, text: "USD" } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  function renderFinancingTable(rows) {
    const tableWrapper = document.getElementById("financingTable-wrapper");
    const tableBody = document.getElementById("financingTable-body");
    if (!tableWrapper || !tableBody) {
      return;
    }

    tableBody.innerHTML = "";
    rows.forEach(function (row) {
      const tr = document.createElement("tr");
      tr.className = "hover:bg-surface-alt";
      tr.innerHTML =
        '<td class="py-3 pr-4 font-semibold text-dark">' + row.period + "</td>" +
        '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(row.public) + "</td>" +
        '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(row.private) + "</td>" +
        '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(row.multilateral) + "</td>" +
        '<td class="py-3 pl-4 text-right font-semibold tabular-nums text-dark">' + formatUsd(row.total) + "</td>";
      tableBody.appendChild(tr);
    });

    const totals = rows.reduce(
      function (acc, row) {
        acc.public += row.public;
        acc.private += row.private;
        acc.multilateral += row.multilateral;
        acc.grandTotal += row.total;
        return acc;
      },
      { public: 0, private: 0, multilateral: 0, grandTotal: 0 }
    );
    const totalRow = document.createElement("tr");
    totalRow.className = "border-t-2 border-stroke bg-surface-alt font-bold text-dark";
    totalRow.innerHTML =
      '<td class="py-3 pr-4" data-i18n="vizPage.tableTotal">Total</td>' +
      '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(totals.public) + "</td>" +
      '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(totals.private) + "</td>" +
      '<td class="py-3 pr-4 text-right tabular-nums">' + formatUsd(totals.multilateral) + "</td>" +
      '<td class="py-3 pl-4 text-right tabular-nums">' + formatUsd(totals.grandTotal) + "</td>";
    tableBody.appendChild(totalRow);

    tableWrapper.classList.remove("hidden");
  }

  /**
   * Gauge-style donut (visually echoes a Power BI "% objectif" ring): the
   * center label surfaces the majority financing type and its real share of
   * the grand total, rather than an invented target - there is no "target"
   * figure anywhere in this schema, so the honest equivalent is "what
   * actually dominates today".
   */
  function renderTypeDonut(totals) {
    const majorityKey = ["public", "private", "multilateral"].reduce(function (best, key) {
      return totals[key] > totals[best] ? key : best;
    }, "public");
    const majorityShare = totals.grandTotal > 0 ? Math.round((totals[majorityKey] / totals.grandTotal) * 100) : 0;

    setChartState("typeChart", "success");
    document.getElementById("typeChart-center-value").textContent = majorityShare + "%";
    document.getElementById("typeChart-center-label").textContent = TYPE_LABELS[majorityKey];

    new Chart(document.getElementById("typeChart"), {
      type: "doughnut",
      data: {
        labels: [TYPE_LABELS.public, TYPE_LABELS.private, TYPE_LABELS.multilateral],
        datasets: [
          {
            data: [totals.public, totals.private, totals.multilateral],
            backgroundColor: [TYPE_COLORS.public, TYPE_COLORS.private, TYPE_COLORS.multilateral],
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "72%",
        plugins: {
          legend: { position: "bottom" },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                const share = totals.grandTotal > 0 ? Math.round((ctx.parsed / totals.grandTotal) * 1000) / 10 : 0;
                return ctx.label + " : " + share + "% (" + formatUsd(ctx.parsed) + ")";
              },
            },
          },
        },
      },
    });
  }

  /**
   * Powers 2 blocks from the single sector-distribution payload: the
   * existing donut (proportions at a glance) and a ranked bar list next to
   * it (precise ranking + amount) - the same real data shown two
   * complementary ways.
   */
  async function loadSectorDistribution() {
    setChartState("sectorChart", "loading");

    try {
      const body = await fetchAnalytics("/api/analytics/sector-distribution");

      if (0 === body.data.length) {
        setChartState("sectorChart", "empty");
        return;
      }

      setChartState("sectorChart", "success");
      document.getElementById("sectorChart-wrapper").classList.add("grid");

      new Chart(document.getElementById("sectorChart"), {
        type: "doughnut",
        data: {
          labels: body.data.map(function (row) {
            return SECTOR_LABELS[row.sector] || row.sector;
          }),
          datasets: [
            {
              data: body.data.map((r) => r.percentage),
              backgroundColor: body.data.map(function (_row, i) {
                return SECTOR_COLORS[i % SECTOR_COLORS.length];
              }),
              borderWidth: 0,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  const row = body.data[ctx.dataIndex];
                  return ctx.label + " : " + row.percentage + "% (" + formatUsd(row.amount) + ")";
                },
              },
            },
          },
        },
      });

      const rankingEl = document.getElementById("sector-ranking");
      rankingEl.innerHTML = "";
      body.data.forEach(function (row, i) {
        const label = SECTOR_LABELS[row.sector] || row.sector;
        const color = SECTOR_COLORS[i % SECTOR_COLORS.length];
        const item = document.createElement("div");
        item.innerHTML =
          '<div class="mb-1 flex items-center justify-between text-sm">' +
          '<span class="flex items-center gap-2 font-medium text-dark"><span class="h-2 w-2 rounded-full" style="background-color:' + color + '"></span>' + label + "</span>" +
          '<span class="font-semibold text-dark-4">' + row.percentage + "%</span>" +
          "</div>" +
          '<div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-alt"><div class="h-full rounded-full" style="width:' + row.percentage + "%;background-color:" + color + '"></div></div>';
        rankingEl.appendChild(item);
      });
    } catch (error) {
      setChartState("sectorChart", "error", { message: error.message, onRetry: loadSectorDistribution });
    }
  }

  async function loadCo2Reduction() {
    const valueEl = document.getElementById("co2-value");
    const noteEl = document.getElementById("co2-note");
    if (!valueEl || !noteEl) {
      return;
    }

    valueEl.textContent = "…";
    noteEl.textContent = "Chargement…";

    try {
      const body = await fetchAnalytics("/api/analytics/co2-reduction");

      if (!body.available) {
        valueEl.textContent = "-";
        noteEl.textContent = "Donnée non disponible.";
        return;
      }

      // Structure reserved for a future real calculation (Volet B) - see
      // App\Service\AnalyticsService::getCo2Reduction(). Not reachable
      // today since the endpoint always returns available: false.
      valueEl.textContent = String(body.data);
      noteEl.textContent = "";
    } catch (error) {
      valueEl.textContent = "-";
      noteEl.textContent = error.message;
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    loadKpiHeroStats();
    loadFinancingTrends();
    loadSectorDistribution();
    loadCo2Reduction();
  });
})(window);
