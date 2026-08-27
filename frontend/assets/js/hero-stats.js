/**
 * NEV Climate Data - index.html Hero stats strip (A2.7).
 *
 * Loads the 4 figures ("Pays couverts", "Secteurs suivis", "Données de
 * financement", "Sources actives") from GET /api/analytics/hero-stats
 * (A2.5's cache infrastructure, extended). The 4 numbers start as "…" in
 * the HTML itself (not the old hard-coded 54/5/1080/4) so there is no
 * window where a demo value could be mistaken for a real one - loading,
 * success and error all only ever set real API-derived text.
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

  function formatCount(value) {
    return Number(value).toLocaleString("fr-FR");
  }

  async function loadHeroStats() {
    const els = {
      countries: document.getElementById("hero-stat-countries"),
      sectors: document.getElementById("hero-stat-sectors"),
      funding: document.getElementById("hero-stat-funding"),
      sources: document.getElementById("hero-stat-sources"),
      note: document.getElementById("hero-stats-note"),
    };
    if (!els.countries || !els.sectors || !els.funding || !els.sources || !els.note) {
      return;
    }

    try {
      const response = await fetch(API_BASE_URL + "/api/analytics/hero-stats", { headers: { Accept: "application/json" } });
      if (!response.ok) {
        throw new Error("Une erreur est survenue (" + response.status + ").");
      }

      const body = await response.json();

      const allZero = 0 === body.countriesCovered && 0 === body.sectorsTracked && 0 === body.fundingRecords && 0 === body.activeSources;
      if (allZero) {
        [els.countries, els.sectors, els.funding, els.sources].forEach(function (el) {
          el.textContent = "-";
        });
        els.note.textContent = "Donnée non disponible.";
        return;
      }

      els.countries.textContent = formatCount(body.countriesCovered);
      els.sectors.textContent = formatCount(body.sectorsTracked);
      els.funding.textContent = formatCount(body.fundingRecords);
      els.sources.textContent = formatCount(body.activeSources);
      els.note.textContent = "";
    } catch (error) {
      [els.countries, els.sectors, els.funding, els.sources].forEach(function (el) {
        el.textContent = "-";
      });
      els.note.textContent = error.message || "Impossible de contacter le serveur.";
    }
  }

  document.addEventListener("DOMContentLoaded", loadHeroStats);
})(window);
