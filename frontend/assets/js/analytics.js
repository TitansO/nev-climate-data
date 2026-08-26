/**
 * NEV Climate Data - visualizations.html chart data (A2.6).
 *
 * Loads the financing-trends and sector-distribution charts, and the CO2
 * reduction stat tile, from the real aggregate endpoints (A2.5). Chart.js
 * instances are created only once real data is available - never seeded
 * with the old hard-coded demo numbers, so there is no window where a
 * mocked value could be mistaken for a real one.
 *
 * Each of the 3 elements is an independent state machine (loading / success
 * / empty / error), matching data.js's setVisibleState() pattern: a failure
 * on one never blocks the other two.
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
    return "http://localhost:8080";
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

  /**
   * Shared chart-card state machine: swaps the "#<id>-state" message area
   * against the "#<id>" canvas. "loading"/"empty"/"error" show a message
   * and hide the canvas; "success" hides the message and reveals the
   * canvas for Chart.js to draw into.
   */
  function setChartState(chartId, state, options) {
    options = options || {};
    const stateEl = document.getElementById(chartId + "-state");
    const canvasEl = document.getElementById(chartId);
    if (!stateEl || !canvasEl) {
      return;
    }

    canvasEl.classList.toggle("hidden", "success" !== state);

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

  async function loadFinancingTrends() {
    setChartState("financingChart", "loading");

    try {
      const body = await fetchAnalytics("/api/analytics/financing-trends");

      if (0 === body.data.length) {
        setChartState("financingChart", "empty");
        return;
      }

      setChartState("financingChart", "success");
      new Chart(document.getElementById("financingChart"), {
        type: "line",
        data: {
          labels: body.data.map(function (row) {
            return String(row.period);
          }),
          datasets: [
            { label: "Public", data: body.data.map((r) => r.public), borderColor: "#16a34a", backgroundColor: "rgba(22,163,74,0.12)", fill: true, tension: 0.4, borderWidth: 3 },
            { label: "Privé", data: body.data.map((r) => r.private), borderColor: "#052e1c", backgroundColor: "rgba(5,46,28,0.08)", fill: true, tension: 0.4, borderWidth: 3 },
            { label: "Multilatéral", data: body.data.map((r) => r.multilateral), borderColor: "#2563eb", backgroundColor: "rgba(37,99,235,0.08)", fill: true, tension: 0.4, borderWidth: 3 },
          ],
        },
        options: {
          responsive: true,
          plugins: { legend: { position: "bottom" } },
          scales: {
            y: { beginAtZero: true, grid: { color: "rgba(5,46,28,0.06)" }, title: { display: true, text: "USD" } },
            x: { grid: { display: false } },
          },
        },
      });
    } catch (error) {
      setChartState("financingChart", "error", { message: error.message, onRetry: loadFinancingTrends });
    }
  }

  async function loadSectorDistribution() {
    setChartState("sectorChart", "loading");

    try {
      const body = await fetchAnalytics("/api/analytics/sector-distribution");

      if (0 === body.data.length) {
        setChartState("sectorChart", "empty");
        return;
      }

      setChartState("sectorChart", "success");
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
          plugins: {
            legend: { position: "bottom" },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  const row = body.data[ctx.dataIndex];
                  return ctx.label + " : " + row.percentage + "% (" + row.amount.toLocaleString("fr-FR") + " USD)";
                },
              },
            },
          },
        },
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
    loadFinancingTrends();
    loadSectorDistribution();
    loadCo2Reduction();
  });
})(window);
