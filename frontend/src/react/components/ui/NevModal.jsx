import { useEffect, useRef } from "react";

/**
 * Generic modal shell (overlay + centered panel + focus-on-open +
 * Escape/backdrop-click to close) extracted from NevConfirmDialog, which
 * now builds on top of this instead of duplicating the same mechanics.
 * Carries no built-in footer/actions - callers render their own content
 * via `children`. `initialFocusRef` lets a caller focus a specific
 * element (e.g. the primary action button) instead of the panel itself.
 */
export default function NevModal({ open, onClose, titleId, maxWidth = "max-w-sm", initialFocusRef, children }) {
  const panelRef = useRef(null);

  useEffect(() => {
    if (!open) {
      return;
    }
    (initialFocusRef && initialFocusRef.current ? initialFocusRef.current : panelRef.current)?.focus();

    function onKeyDown(event) {
      if ("Escape" === event.key) {
        onClose();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, onClose, initialFocusRef]);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-dark/40 px-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div ref={panelRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} className={"w-full " + maxWidth + " rounded-lg border border-stroke bg-white p-6 shadow-card focus:outline-none"}>
        {children}
      </div>
    </div>
  );
}
