import NevButton from "./NevButton";

/**
 * Précédent/Suivant pagination, replacing the duplicated markup in
 * DataPage and ReportsPage. pageLabel is precomputed by the caller (e.g.
 * "Page 2 sur 5") so this generic component never hardcodes a language.
 *
 * Optional numbered mode (pass `page`/`totalPages`/`onPageChange`, used by
 * ReportsPage since the API already returns both) renders individual page
 * buttons between Précédent/Suivant instead of the plain text label -
 * additive, backward-compatible with DataPage's existing prev/next-only
 * usage (pageLabel-only, no numbered props passed).
 */
function pageWindow(page, totalPages) {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, i) => i + 1);
  }
  const set = new Set([1, totalPages, page - 1, page, page + 1]);
  return Array.from(set)
    .filter((p) => p >= 1 && p <= totalPages)
    .sort((a, b) => a - b);
}

export default function NevPagination({ pageLabel, onPrevious, onNext, disabledPrevious, disabledNext, previousLabel = "Précédent", nextLabel = "Suivant", page, totalPages, onPageChange }) {
  const numbered = "number" === typeof page && "number" === typeof totalPages && totalPages > 1;
  const items = numbered ? pageWindow(page, totalPages) : [];

  return (
    <nav className="flex flex-wrap items-center justify-center gap-2" aria-label="Pagination">
      <NevButton variant="outline" size="sm" onClick={onPrevious} disabled={disabledPrevious}>
        {previousLabel}
      </NevButton>
      {numbered ? (
        <span className="flex items-center gap-1" aria-live="polite">
          {items.map((p, i) => (
            <span key={p} className="flex items-center gap-1">
              {i > 0 && p - items[i - 1] > 1 ? <span className="px-1 text-sm text-dark-6">…</span> : null}
              <button
                type="button"
                onClick={() => onPageChange(p)}
                aria-current={p === page ? "page" : undefined}
                className={
                  "flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary " +
                  (p === page ? "bg-primary text-white" : "text-dark-4 hover:bg-gray-2")
                }
              >
                {p}
              </button>
            </span>
          ))}
        </span>
      ) : (
        <span className="text-sm text-body-color" aria-live="polite">
          {pageLabel}
        </span>
      )}
      <NevButton variant="outline" size="sm" onClick={onNext} disabled={disabledNext}>
        {nextLabel}
      </NevButton>
    </nav>
  );
}
