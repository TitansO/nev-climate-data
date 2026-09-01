import { useEffect, useState } from "react";

/**
 * A3.2: subscribes to the Mercure hub's KPI topic (A3.1) via the browser's
 * native EventSource - no client library needed, per the approved
 * migration plan. Returns {connected, snapshot}: `snapshot` is the most
 * recent {fundingTotalUsd, countriesCovered, publishedAt} payload
 * published by the backend's `mercure-publisher` service (a periodic
 * republish, not a per-write event - see backend/src/Command/
 * PublishKpiSnapshotCommand.php's docblock for why).
 *
 * Deliberately fails soft: if the hub is unreachable (not deployed in this
 * environment, network blocked, wrong topic) EventSource just never opens
 * and this hook keeps returning {connected: false, snapshot: null}
 * forever - every value it feeds the page already has a non-Mercure
 * fallback (the financing-trends grand total, the hero-stats country
 * count), so visualizations.html works identically whether or not the hub
 * is reachable, exactly like every other chart on this page degrades on
 * its own fetch failure.
 */
function resolveMercureHubUrl() {
  if ("undefined" === typeof window) {
    return null;
  }
  const host = window.location.hostname;
  if ("localhost" === host || "127.0.0.1" === host) {
    return "http://localhost:3000/.well-known/mercure";
  }
  const codespaceMatch = host.match(/^(.+)-8123\.app\.github\.dev$/);
  if (codespaceMatch) {
    return "https://" + codespaceMatch[1] + "-3000.app.github.dev/.well-known/mercure";
  }
  // Production (Netlify/Render): no Mercure hub deployed yet - see the
  // migration plan's "point de vigilance" on Render's single-service
  // constraint. Returning null here means useMercureKpis() simply never
  // connects in production, which is the correct/honest behavior until
  // that infrastructure decision is made.
  return null;
}

export const MERCURE_KPI_TOPIC = "https://nev-climate-data.local/kpis/analytics";

export default function useMercureKpis() {
  const [connected, setConnected] = useState(false);
  const [snapshot, setSnapshot] = useState(null);

  useEffect(() => {
    const hubUrl = resolveMercureHubUrl();
    if (!hubUrl) {
      return undefined;
    }

    const url = new URL(hubUrl);
    url.searchParams.append("topic", MERCURE_KPI_TOPIC);

    let source;
    try {
      source = new EventSource(url);
    } catch (error) {
      return undefined;
    }

    source.onopen = () => setConnected(true);
    source.onerror = () => setConnected(false);
    source.onmessage = (event) => {
      try {
        setSnapshot(JSON.parse(event.data));
      } catch (parseError) {
        // A malformed payload from the hub is not this page's problem to
        // surface to the visitor - it just keeps showing whatever it had
        // (or the non-Mercure fallback), same as any other stale-data case.
      }
    };

    return () => {
      source.close();
      setConnected(false);
    };
  }, []);

  return { connected, snapshot };
}
