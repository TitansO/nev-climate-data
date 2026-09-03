import NevCard from "./NevCard";

/**
 * Institutional KPI tile: value + label + optional context/icon, honest
 * loading/error/empty states instead of each page hand-rolling "…"/"-"
 * text. Replaces IndexPage's 4 hero-stat tiles (useHeroStats) and
 * VisualizationsPage's 4 KPI tiles (incl. the Mercure "Direct" live badge).
 */
export default function NevKpi({ label, value, context, icon, state = "success", errorMessage, liveBadge = false, liveLabel = "Direct", className = "" }) {
  const displayValue = "loading" === state ? "…" : "error" === state || "empty" === state ? "-" : value;

  return (
    <NevCard padding="md" className={"text-center " + className}>
      {icon ? <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-md bg-surface-alt text-primary">{icon}</div> : null}
      <p className="flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-wide text-body-color">
        {label}
        {liveBadge ? (
          <span className="inline-flex items-center gap-1 rounded-full bg-status-validated-bg px-2 py-0.5 text-[10px] font-bold normal-case tracking-normal text-status-validated">
            <span className="h-1.5 w-1.5 rounded-full bg-status-validated" aria-hidden="true"></span>
            {liveLabel}
          </span>
        ) : null}
      </p>
      <p className="mt-2 text-3xl font-extrabold tabular-nums text-dark" aria-live="polite">
        {displayValue}
      </p>
      {"error" === state && errorMessage ? <p className="mt-1.5 text-xs text-danger">{errorMessage}</p> : null}
      {"empty" === state && context ? <p className="mt-1.5 text-xs text-body-color">{context}</p> : null}
      {"success" === state && context ? <p className="mt-1.5 text-xs text-body-color">{context}</p> : null}
    </NevCard>
  );
}
