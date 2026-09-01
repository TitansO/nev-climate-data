import { createContext, useCallback, useContext, useRef } from "react";

/**
 * NEV Climate Data - React port of assets/js/auth.js, for pages migrated
 * to React (A3.1/A3.2 plan). Same behavior, same endpoints, same
 * localStorage keys as the vanilla-JS version still used by not-yet-
 * migrated pages - both can run side by side (they share the same tokens)
 * during the page-by-page migration.
 *
 * Token storage: localStorage (not a cookie - same trade-off/reasoning as
 * auth.js: the backend only ever issues JWTs for the client to send back
 * as `Authorization: Bearer`, never a session cookie).
 */

function resolveApiBaseUrl() {
  const host = window.location.hostname;
  if (host === "localhost" || host === "127.0.0.1") {
    return "http://localhost:8080";
  }
  const codespaceMatch = host.match(/^(.+)-8123\.app\.github\.dev$/);
  if (codespaceMatch) {
    return "https://" + codespaceMatch[1] + "-8080.app.github.dev";
  }
  // Production (Netlify): relative URLs, proxied server-side by
  // frontend/_redirects to the real backend - avoids CORS entirely.
  return "";
}

const API_BASE_URL = resolveApiBaseUrl();
const STORAGE_TOKEN_KEY = "nev_token";
const STORAGE_REFRESH_TOKEN_KEY = "nev_refresh_token";

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

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  // Single-flight guard: the refresh token is single-use (rotated on every
  // /api/auth/refresh call). If two requests 401 at the same time and each
  // fired its own refresh, the second would arrive with a refresh token
  // the first already rotated away and fail. Every caller awaits this same
  // in-flight promise instead. A ref (not state) since it never needs to
  // trigger a re-render.
  const refreshInFlight = useRef(null);

  const login = useCallback(async (email, password) => {
    let response;
    try {
      response = await fetch(API_BASE_URL + "/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });
    } catch (networkError) {
      throw new Error("Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.");
    }

    const body = await safeJson(response);
    if (!response.ok) {
      throw new Error(body && body.message ? body.message : "Email ou mot de passe incorrect.");
    }

    setTokens(body.token, body.refresh_token);
  }, []);

  const logout = useCallback(async () => {
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
  }, []);

  const refreshSession = useCallback(() => {
    if (refreshInFlight.current) {
      return refreshInFlight.current;
    }

    refreshInFlight.current = (async () => {
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

    return refreshInFlight.current.finally(() => {
      refreshInFlight.current = null;
    });
  }, []);

  /**
   * fetch() wrapper that attaches the current JWT and, on a single 401,
   * refreshes the session once (see the single-flight note above) and
   * retries the original request exactly once. Never loops.
   */
  const authorizedFetch = useCallback(
    async (url, options = {}) => {
      const doFetch = () => {
        const headers = Object.assign({}, options.headers, { Authorization: "Bearer " + getToken() });
        return fetch(url, Object.assign({}, options, { headers }));
      };

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
    },
    [refreshSession]
  );

  const getCurrentUser = useCallback(async () => {
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
  }, [authorizedFetch]);

  /**
   * Guard for private pages: call at the top of the page component (see
   * useRequireAuth() below). Client-side only, same as auth.js's
   * requireAuth() - hides the page from a casual visitor but is not
   * itself a security boundary; the API endpoints stay the real one.
   */
  const requireAuth = useCallback(() => {
    if (!isAuthenticated()) {
      window.location.href = "login.html";
    }
  }, []);

  const value = {
    API_BASE_URL,
    login,
    logout,
    isAuthenticated,
    getToken,
    getCurrentUser,
    authorizedFetch,
    requireAuth,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth() must be used inside <AuthProvider>.");
  }
  return ctx;
}
