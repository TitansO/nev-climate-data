import { useEffect, useRef } from "react";

// Audit pre-production 2026-09-23 (P1) : selecteur standard des elements
// focusables au clavier, utilise pour boucler le focus (Tab/Shift+Tab)
// a l'interieur du panneau tant que la modale est ouverte - sans ca, un
// Tab depuis le dernier element sortait vers la page sous l'overlay
// (piege de focus manquant, WCAG 2.1 SC 2.1.2).
const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

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
        return;
      }
      if ("Tab" !== event.key || !panelRef.current) {
        return;
      }
      const focusable = Array.from(panelRef.current.querySelectorAll(FOCUSABLE_SELECTOR));
      if (0 === focusable.length) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
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
      <div ref={panelRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} className={"max-h-[90vh] w-full overflow-y-auto " + maxWidth + " rounded-lg border border-stroke bg-white p-6 shadow-card focus:outline-none"}>
        {children}
      </div>
    </div>
  );
}
