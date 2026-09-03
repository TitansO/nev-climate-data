import NevButton from "./NevButton";

/**
 * Précédent/Suivant pagination, replacing the duplicated markup in
 * DataPage and ReportsPage. pageLabel is precomputed by the caller (e.g.
 * "Page 2 sur 5") so this generic component never hardcodes a language.
 */
export default function NevPagination({ pageLabel, onPrevious, onNext, disabledPrevious, disabledNext, previousLabel = "Précédent", nextLabel = "Suivant" }) {
  return (
    <nav className="flex items-center justify-center gap-3" aria-label="Pagination">
      <NevButton variant="outline" size="sm" onClick={onPrevious} disabled={disabledPrevious}>
        {previousLabel}
      </NevButton>
      <span className="text-sm text-body-color" aria-live="polite">
        {pageLabel}
      </span>
      <NevButton variant="outline" size="sm" onClick={onNext} disabled={disabledNext}>
        {nextLabel}
      </NevButton>
    </nav>
  );
}
