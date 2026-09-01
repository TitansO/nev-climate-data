import { useCallback, useEffect, useState } from "react";
import { useAuth } from "../providers/AuthProvider";

/**
 * Generic fetch hook for the /api/analytics/* aggregates (A2.5/A2.6), used
 * by every chart on visualizations.html. Mirrors assets/js/analytics.js's
 * fetchAnalytics() + the shared loading/success/empty/error state machine
 * (setChartState()) as one hook instead of duplicating that logic per
 * chart: {state: "loading"|"success"|"empty"|"error", data, message, reload}.
 *
 * "empty" means the request succeeded but returned a zero-length `data`
 * array - same distinction the original made (never conflated with a
 * network/HTTP error).
 */
export default function useAnalyticsFetch(path, { emptyWhen } = {}) {
  const { API_BASE_URL } = useAuth();
  const [state, setState] = useState("loading");
  const [data, setData] = useState(null);
  const [message, setMessage] = useState("");
  const [reloadToken, setReloadToken] = useState(0);

  const reload = useCallback(() => setReloadToken((t) => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setState("loading");

    (async () => {
      let response;
      try {
        response = await fetch(API_BASE_URL + path, { headers: { Accept: "application/json" } });
      } catch (networkError) {
        if (!cancelled) {
          setMessage("Impossible de contacter le serveur.");
          setState("error");
        }
        return;
      }

      if (cancelled) {
        return;
      }

      if (!response.ok) {
        setMessage("Une erreur est survenue (" + response.status + ").");
        setState("error");
        return;
      }

      const body = await response.json();
      if (cancelled) {
        return;
      }

      setData(body);
      if (emptyWhen && emptyWhen(body)) {
        setState("empty");
        return;
      }
      setState("success");
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [API_BASE_URL, path, reloadToken]);

  return { state, data, message, reload };
}
