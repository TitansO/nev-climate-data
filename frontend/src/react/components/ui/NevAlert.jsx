import NevButton from "./NevButton";

const TONE_STYLES = {
  success: { border: "border-status-validated/30", bg: "bg-status-validated-bg/60", text: "text-status-validated" },
  warning: { border: "border-status-demo/30", bg: "bg-status-demo-bg/60", text: "text-status-demo" },
  danger: { border: "border-danger/30", bg: "bg-danger-bg/60", text: "text-danger" },
  info: { border: "border-status-review/30", bg: "bg-status-review-bg/60", text: "text-status-review" },
};

const TONE_ICON_PATHS = {
  success: "M9 12.75l2.25 2.25 4.5-4.5m4.5 2.25a9 9 0 11-18 0 9 9 0 0118 0z",
  warning: "M12 9v3.75m0 3h.008v.008H12v-.008zM10.34 3.94l-8.2 14.2a1.5 1.5 0 001.3 2.25h16.72a1.5 1.5 0 001.3-2.25l-8.2-14.2a1.5 1.5 0 00-2.6 0z",
  danger: "M12 9v3.75m0 3h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z",
  info: "M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z",
};

/**
 * Real error/notice banner using the --nev-success/-warning/-danger/-info
 * tokens (distinct from the data-quality status colors) - replaces
 * DataPage/ReportsPage/account pages incorrectly reusing the "demo data"
 * amber for genuine request failures.
 */
export default function NevAlert({ tone = "info", title, children, onRetry, retryLabel = "Réessayer", className = "" }) {
  const style = TONE_STYLES[tone] || TONE_STYLES.info;

  return (
    <div role={"danger" === tone ? "alert" : "status"} aria-live="polite" className={"flex gap-3 rounded-lg border p-4 " + style.border + " " + style.bg + " " + className}>
      <svg className={"mt-0.5 h-5 w-5 shrink-0 " + style.text} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
        <path strokeLinecap="round" strokeLinejoin="round" d={TONE_ICON_PATHS[tone] || TONE_ICON_PATHS.info} />
      </svg>
      <div className="min-w-0 flex-1">
        {title ? <p className={"text-sm font-semibold " + style.text}>{title}</p> : null}
        {children ? <p className="mt-0.5 text-sm text-dark-4">{children}</p> : null}
        {onRetry ? (
          <NevButton variant="outline" size="sm" className="mt-3" onClick={onRetry}>
            {retryLabel}
          </NevButton>
        ) : null}
      </div>
    </div>
  );
}
