import NevSpinner from "./NevSpinner";
import NevButton from "./NevButton";

/**
 * The loading/empty/error/success 4-state block previously hand-rolled
 * per page (NotificationsPage, AccountApiKeysPage, AccountUsersPage,
 * DataPage, ReportsPage) - same shape as the existing charts/ChartStateBox
 * (kept as-is for charts), this is the list/table equivalent.
 *
 * Error state uses --nev-danger, not the "demo data" amber the old inline
 * markup mistakenly reused for real request failures.
 */
export default function NevDataState({ state, loadingText = "Chargement…", emptyText = "Aucune donnée disponible.", errorText, onRetry, retryLabel = "Réessayer", children }) {
  if ("success" === state) {
    return children;
  }

  if ("loading" === state) {
    return (
      <div className="rounded-lg border border-stroke bg-white p-16 text-center" aria-live="polite">
        <NevSpinner className="mx-auto h-8 w-8" />
        <p className="mt-4 text-sm text-body-color">{loadingText}</p>
      </div>
    );
  }

  if ("error" === state) {
    return (
      <div className="rounded-lg border border-danger/30 bg-danger-bg/60 p-16 text-center" role="alert" aria-live="polite">
        <p className="text-sm text-danger">{errorText || "Une erreur est survenue."}</p>
        {onRetry ? (
          <NevButton variant="outline" size="sm" className="mt-4" onClick={onRetry}>
            {retryLabel}
          </NevButton>
        ) : null}
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-stroke bg-white p-16 text-center" aria-live="polite">
      <p className="text-sm text-body-color">{emptyText}</p>
    </div>
  );
}
