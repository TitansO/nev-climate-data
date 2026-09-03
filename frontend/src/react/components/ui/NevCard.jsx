const PADDING_CLASSES = {
  sm: "p-4",
  md: "p-6",
  lg: "p-8",
};

/**
 * Single card shell replacing the 3 near-duplicate patterns previously
 * hand-rolled per page (viz cards, report cards, index stat cards).
 * Default elevation is deliberately light (shadow-xs) - shadow-card is
 * reserved for the hover/interactive state, not the resting state.
 */
export default function NevCard({ as: Component = "div", padding = "md", interactive = false, className = "", children, ...rest }) {
  const classes =
    "rounded-lg border border-stroke bg-white shadow-xs " +
    PADDING_CLASSES[padding] +
    (interactive ? " transition hover:-translate-y-0.5 hover:shadow-card" : "") +
    " " +
    className;

  return (
    <Component className={classes} {...rest}>
      {children}
    </Component>
  );
}
