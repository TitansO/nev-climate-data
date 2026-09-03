/**
 * Small inline spinner shared by NevDataState's loading UI. Pure CSS
 * animation (no dependency); respects prefers-reduced-motion via the
 * global rule in input.css (animation-duration forced to ~0).
 */
export default function NevSpinner({ className = "h-8 w-8" }) {
  return <span className={"inline-block animate-spin rounded-full border-4 border-primary/20 border-t-primary " + className} role="presentation" aria-hidden="true"></span>;
}
