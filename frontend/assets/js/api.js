/**
 * NEV Climate Data — minimal fetch wrapper for the backend API (A2.2).
 *
 * No build step / bundler exists in this frontend, so this stays a plain
 * global namespace (window.NevApi) rather than an ES module — consistent
 * with assets/js/main.js.
 *
 * API_BASE_URL is the backend origin for local development. There is no
 * frontend build/env-injection mechanism yet (static HTML, no bundler), so
 * this is the one place to change it for a different environment.
 */
(function (global) {
  "use strict";

  const API_BASE_URL = resolveApiBaseUrl();

  /**
   * Derives the backend origin from where this page itself is being served,
   * so the same static files work unmodified whether opened through the
   * local SSH tunnel (http://localhost:8123) or through a Codespace's
   * forwarded HTTPS URL (https://<name>-8123.app.github.dev) - the two
   * environments this project is actually viewed from. A hardcoded
   * "http://localhost:8080" only ever worked for the first case.
   */
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

  /**
   * GET /api/funding with the given filter/pagination params. Empty/null/
   * undefined values are omitted so the request only carries filters the
   * caller actually set (matches the backend's "all filters optional"
   * contract — see backend/src/Dto/FundingSearchCriteria.php).
   *
   * @param {Object} params
   * @returns {Promise<{data: Array<Object>, meta: Object}>}
   * @throws {Error} with a message suitable for display, on any non-2xx
   *   response or network failure.
   */
  async function fetchFunding(params) {
    const url = new URL(API_BASE_URL + "/api/funding");
    Object.entries(params || {}).forEach(function ([key, value]) {
      if (value !== null && value !== undefined && value !== "") {
        url.searchParams.set(key, value);
      }
    });

    let response;
    try {
      response = await fetch(url.toString(), {
        method: "GET",
        headers: { Accept: "application/json" },
      });
    } catch (networkError) {
      throw new Error("Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.");
    }

    let body = null;
    try {
      body = await response.json();
    } catch (parseError) {
      // Falls through to the !response.ok branch below with body === null.
    }

    if (!response.ok) {
      const message = body && body.message ? body.message : "Une erreur est survenue (" + response.status + ").";
      throw new Error(message);
    }

    return body;
  }

  global.NevApi = { fetchFunding: fetchFunding, API_BASE_URL: API_BASE_URL };
})(window);
