import { useEffect, useRef } from "react";
import NevButton from "./NevButton";

/**
 * Replaces the two window.confirm() calls in AccountUsersPage (delete
 * user) and AccountApiKeysPage (revoke key) - the only justified modal
 * usage found in the codebase. Same two-outcome flow: onConfirm fires the
 * same DELETE call the page already made, just no longer via a native
 * browser dialog.
 */
export default function NevConfirmDialog({ open, title, description, confirmLabel = "Confirmer", cancelLabel = "Annuler", tone = "danger", onConfirm, onCancel }) {
  const confirmRef = useRef(null);

  useEffect(() => {
    if (!open) {
      return;
    }
    confirmRef.current?.focus();

    function onKeyDown(event) {
      if ("Escape" === event.key) {
        onCancel();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, onCancel]);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-dark/40 px-4" onMouseDown={(event) => event.target === event.currentTarget && onCancel()}>
      <div role="dialog" aria-modal="true" aria-labelledby="nev-confirm-title" className="w-full max-w-sm rounded-lg border border-stroke bg-white p-6 shadow-card">
        <h2 id="nev-confirm-title" className="text-lg font-semibold text-dark">
          {title}
        </h2>
        {description ? <p className="mt-2 text-sm text-body-color">{description}</p> : null}
        <div className="mt-6 flex justify-end gap-3">
          <NevButton variant="outline" size="sm" onClick={onCancel}>
            {cancelLabel}
          </NevButton>
          <NevButton ref={confirmRef} variant={"danger" === tone ? "danger" : "primary"} size="sm" onClick={onConfirm}>
            {confirmLabel}
          </NevButton>
        </div>
      </div>
    </div>
  );
}
