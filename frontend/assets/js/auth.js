/**
 * NEV Climate Data — shared authentication module (frontend auth block,
 * inserted between A2.2 and A2.3: A2.3's export quotas and later A2.10's
 * notifications are per-user, and A1.5's "API keys" backend has had no
 * frontend at all until now).
 *
 * No framework/bundler exists in this project — plain DOM APIs and a
 * global namespace (window.NevAuth), matching assets/js/main.js and
 * assets/js/api.js.
 *
 * Token storage: localStorage (not a cookie — the backend only ever issues
 * JWTs for the client to send back as `Authorization: Bearer`, it never
 * sets a session cookie, so there is no alternative). Known trade-off,
 * accepted deliberately: localStorage is readable by any script running on
 * this origin, so it carries the usual XSS-exfiltration exposure a
 * cookie-based session with httpOnly would avoid. Revisit if the backend
 * ever adds cookie-based auth.
 */
(function (global) {
  "use strict";

  // Not read from global.NevApi.API_BASE_URL: script load order differs
  // across pages (login.html and account-profile.html don't load api.js at
  // all), so this resolves independently rather than depending on it.
  const API_BASE_URL = resolveApiBaseUrl();

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
  const STORAGE_TOKEN_KEY = "nev_token";
  const STORAGE_REFRESH_TOKEN_KEY = "nev_refresh_token";

  // Single-flight guard: the refresh token is single-use (rotated on every
  // /api/auth/refresh call — see A1.4). If two requests 401 at the same
  // time and each fired its own refresh, the second would arrive with a
  // refresh token the first already rotated away and fail. Every caller
  // awaits this same in-flight promise instead.
  let refreshInFlight = null;

  function getToken() {
    return localStorage.getItem(STORAGE_TOKEN_KEY);
  }

  function getRefreshToken() {
    return localStorage.getItem(STORAGE_REFRESH_TOKEN_KEY);
  }

  function setTokens(token, refreshToken) {
    localStorage.setItem(STORAGE_TOKEN_KEY, token);
    localStorage.setItem(STORAGE_REFRESH_TOKEN_KEY, refreshToken);
  }

  function clearTokens() {
    localStorage.removeItem(STORAGE_TOKEN_KEY);
    localStorage.removeItem(STORAGE_REFRESH_TOKEN_KEY);
  }

  function isAuthenticated() {
    return null !== getToken();
  }

  async function safeJson(response) {
    try {
      return await response.json();
    } catch (error) {
      return null;
    }
  }

  async function login(email, password) {
    let response;
    try {
      response = await fetch(API_BASE_URL + "/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: email, password: password }),
      });
    } catch (networkError) {
      throw new Error("Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.");
    }

    const body = await safeJson(response);
    if (!response.ok) {
      throw new Error(body && body.message ? body.message : "Email ou mot de passe incorrect.");
    }

    setTokens(body.token, body.refresh_token);
  }

  async function logout() {
    const token = getToken();
    clearTokens();
    if (null === token) {
      return;
    }
    try {
      await fetch(API_BASE_URL + "/api/auth/logout", {
        method: "POST",
        headers: { Authorization: "Bearer " + token },
      });
    } catch (error) {
      // Session is already cleared client-side regardless of the server call's outcome.
    }
  }

  function refreshSession() {
    if (refreshInFlight) {
      return refreshInFlight;
    }

    refreshInFlight = (async function () {
      const refreshToken = getRefreshToken();
      if (null === refreshToken) {
        throw new Error("No refresh token available.");
      }

      const response = await fetch(API_BASE_URL + "/api/auth/refresh", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ refresh_token: refreshToken }),
      });

      if (!response.ok) {
        clearTokens();
        throw new Error("Session expirée.");
      }

      const body = await safeJson(response);
      setTokens(body.token, body.refresh_token);
      return body.token;
    })();

    return refreshInFlight.finally(function () {
      refreshInFlight = null;
    });
  }

  /**
   * fetch() wrapper that attaches the current JWT and, on a single 401,
   * refreshes the session once (see the single-flight note above) and
   * retries the original request exactly once. Never loops.
   */
  async function authorizedFetch(url, options) {
    options = options || {};

    function doFetch() {
      const headers = Object.assign({}, options.headers, { Authorization: "Bearer " + getToken() });
      return fetch(url, Object.assign({}, options, { headers: headers }));
    }

    let response = await doFetch();

    if (401 === response.status && null !== getRefreshToken()) {
      try {
        await refreshSession();
        response = await doFetch();
      } catch (error) {
        clearTokens();
      }
    }

    return response;
  }

  async function getCurrentUser() {
    if (!isAuthenticated()) {
      return null;
    }

    const response = await authorizedFetch(API_BASE_URL + "/api/auth/me", {
      headers: { Accept: "application/json" },
    });

    if (!response.ok) {
      return null;
    }

    return response.json();
  }

  /**
   * Guard for private pages: call at the top of the page's own script.
   * Client-side only (there is no server-rendered/protected route in this
   * static-HTML frontend) — this hides the page from a casual visitor but
   * is not itself a security boundary; the API endpoints stay the real one.
   */
  function requireAuth() {
    if (!isAuthenticated()) {
      window.location.href = "login.html";
    }
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value;
    return div.innerHTML;
  }

  /**
   * Swaps the navbar's "Connexion" link (id="auth-nav-slot" - present in
   * every page's navbar) for the logged-in user's email + a logout button.
   * Left untouched when logged out, or when a page has no such slot
   * (login.html, 404.html).
   */
  async function renderNavbarAuthState() {
    const slot = document.getElementById("auth-nav-slot");
    if (!slot || !isAuthenticated()) {
      return;
    }

    const user = await getCurrentUser();
    if (null === user) {
      // Token invalid/expired and refresh failed - fall back to the logged-out navbar.
      clearTokens();
      return;
    }

    // data-i18n="profilePage.logout" (A2.11): this button is injected here,
    // after the page's initial translation pass, so its starting text comes
    // from NevI18n.t() rather than data-i18n alone; the attribute keeps it
    // correct if the user switches language afterwards. See NevI18n.refresh().
    const logoutLabel = global.NevI18n ? global.NevI18n.t("profilePage.logout") : "Déconnexion";
    slot.outerHTML =
      '<div class="flex items-center gap-3">' +
      '<a href="account-profile.html" class="text-sm font-medium text-white/90 hover:text-white">' + escapeHtml(user.email) + "</a>" +
      '<button type="button" id="auth-logout-btn" data-i18n="profilePage.logout" class="rounded-md bg-white/15 px-4 py-2 text-sm font-semibold text-white transition duration-300 ease-in-out hover:bg-white hover:text-dark">' + escapeHtml(logoutLabel) + "</button>" +
      "</div>";

    document.getElementById("auth-logout-btn").addEventListener("click", async function () {
      await logout();
      window.location.href = "index.html";
    });
  }

  document.addEventListener("DOMContentLoaded", renderNavbarAuthState);

  global.NevAuth = {
    login: login,
    logout: logout,
    isAuthenticated: isAuthenticated,
    getToken: getToken,
    getCurrentUser: getCurrentUser,
    authorizedFetch: authorizedFetch,
    requireAuth: requireAuth,
  };
})(window);
