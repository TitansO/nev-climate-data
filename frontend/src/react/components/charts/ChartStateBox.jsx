/**
 * React port of analytics.js's setChartState(): a chart card is one of
 * "loading" / "empty" / "error" / "success". In the "success" state the
 * real chart (children) renders; otherwise a centered message (+ an
 * optional "Réessayer" button on error) takes its place - conditional
 * rendering replaces the original's classList.toggle("hidden"/"flex")
 * dance, since React just omits the message markup entirely instead of
 * needing two competing display-controlling classes.
 */
export default function ChartStateBox({ state, message, onRetry, minHeight = true, children }) {
  if ("success" === state) {
    return children;
  }

  return (
    <div className={"flex flex-col items-center justify-center gap-3 text-center text-sm text-body-color" + (minHeight ? " min-h-[200px]" : "")}>
      {"loading" === state && <p>Chargement…</p>}
      {"empty" === state && <p>Donnée non disponible.</p>}
      {"error" === state && (
        <>
          <p>{message || "Une erreur est survenue."}</p>
          {onRetry && (
            <button
              type="button"
              className="rounded-md border border-stroke px-4 py-1.5 text-xs font-semibold text-dark-4 transition hover:bg-gray-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              onClick={onRetry}
            >
              Réessayer
            </button>
          )}
        </>
      )}
    </div>
  );
}
