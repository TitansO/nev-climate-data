import NevCard from "./NevCard";

/**
 * Standardizes chart title/unit/source presentation around the existing
 * Chart.js/jsvectormap components (FinancingBarChart, SectorDonutChart,
 * TypeDonutChart, CountryMap) - wrapper only, chart internals untouched.
 * sourceLabel/periodLabel are only rendered when the caller actually has
 * them - never fabricated metadata.
 */
export default function NevChartCard({ title, subtitle, sourceLabel, periodLabel, children, className = "" }) {
  const hasFooter = sourceLabel || periodLabel;

  return (
    <NevCard padding="md" className={className}>
      <div className="mb-4">
        <h3 className="text-lg font-semibold text-dark">{title}</h3>
        {subtitle ? <p className="mt-1 text-sm text-body-color">{subtitle}</p> : null}
      </div>
      {children}
      {hasFooter ? (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-stroke pt-3 text-xs text-dark-5">
          {sourceLabel ? <span>{sourceLabel}</span> : <span />}
          {periodLabel ? <span>{periodLabel}</span> : null}
        </div>
      ) : null}
    </NevCard>
  );
}
