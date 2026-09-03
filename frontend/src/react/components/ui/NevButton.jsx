import { forwardRef } from "react";

const VARIANT_CLASSES = {
  primary: "bg-primary text-white hover:bg-primary-dark",
  outline: "border border-stroke bg-white text-dark-4 hover:bg-gray-2",
  ghost: "text-dark-4 hover:bg-gray-2",
  danger: "bg-danger text-white hover:opacity-90",
};

const SIZE_CLASSES = {
  sm: "px-4 py-2 text-xs",
  md: "px-6 py-2.5 text-sm",
};

/**
 * Shared button, used for both real <button>s and styled links (as="a").
 * Replaces the primary/outline button markup previously duplicated across
 * IndexPage, AuthSlot, DataPage. Forwards its ref (NevConfirmDialog
 * autofocuses the confirm button on open).
 */
const NevButton = forwardRef(function NevButton(
  { as = "button", variant = "primary", size = "md", type = "button", disabled = false, className = "", children, ...rest },
  ref
) {
  const classes =
    "inline-flex items-center justify-center gap-2 rounded-md font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 " +
    VARIANT_CLASSES[variant] +
    " " +
    SIZE_CLASSES[size] +
    " " +
    className;

  if ("a" === as) {
    return (
      <a ref={ref} className={classes} aria-disabled={disabled || undefined} {...rest}>
        {children}
      </a>
    );
  }

  return (
    <button ref={ref} type={type} className={classes} disabled={disabled} {...rest}>
      {children}
    </button>
  );
});

export default NevButton;
