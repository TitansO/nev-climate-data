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
   * so the same static files work unmodified across every environment this
   * project is actually viewed from: the local SSH tunnel
   * (http://localhost:8123), a Codespace's forwarded HTTPS URL
   * (https://<name>-8123.app.github.dev), and production (Netlify).
   *
   * Production returns "" (relative URLs, e.g. fetch("/api/funding")) - a
   * same-origin request the host's own routing proxies to the real backend
   * server-side (see frontend/_redirects: "/api/* -> the Render backend"
   * origin), rather than a hardcoded absolute backend URL. Two reasons:
   * this file is the one place meant to need editing per environment, and
   * a hardcoded URL here couldn't survive the backend host ever changing;
   * and a same-origin request never needs CORS at all, so it can't be
   * broken by a CORS_ALLOWED_ORIGIN_REGEX misconfiguration on the backend
   * (a real outage caught after this fell back to "http://localhost:8080"
   * for the Netlify origin instead - a request the browser rejects
   * outright as mixed content on an HTTPS page, surfacing as an opaque
   * "impossible de contacter le serveur" network error with no clue in
   * the browser console pointing at the real cause).
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
    return "";
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
    // The base argument makes this work whether API_BASE_URL is absolute
    // (dev/Codespace) or "" (production, relative - see resolveApiBaseUrl
    // above): new URL() alone throws on a relative-only input.
    const url = new URL(API_BASE_URL + "/api/funding", global.location.origin);
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
