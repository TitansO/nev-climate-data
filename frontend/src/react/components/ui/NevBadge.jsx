const TONE_CLASSES = {
  success: "bg-status-validated-bg text-status-validated",
  warning: "bg-status-demo-bg text-status-demo",
  danger: "bg-danger-bg text-danger",
  info: "bg-status-review-bg text-status-review",
  neutral: "bg-gray-2 text-dark-4",
};

/**
 * Status/type badge, replacing the inline status-color classes duplicated
 * in DataPage/ReportsPage. Always pairs color with a text label (never a
 * bare colored dot) so no information is conveyed by color alone.
 */
export default function NevBadge({ tone = "neutral", children, className = "" }) {
  return <span className={"inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold " + TONE_CLASSES[tone] + " " + className}>{children}</span>;
}
