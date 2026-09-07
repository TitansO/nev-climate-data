import { useRef } from "react";
import NevModal from "./NevModal";
import NevButton from "./NevButton";

/**
 * Replaces the two window.confirm() calls in AccountUsersPage (delete
 * user) and AccountApiKeysPage (revoke key). Same two-outcome flow:
 * onConfirm fires the same DELETE call the page already made, just no
 * longer via a native browser dialog. Built on NevModal's generic shell
 * (overlay/focus/Escape/backdrop-click) - only the confirm/cancel footer
 * is specific to this component.
 */
export default function NevConfirmDialog({ open, title, description, confirmLabel = "Confirmer", cancelLabel = "Annuler", tone = "danger", onConfirm, onCancel }) {
  const confirmRef = useRef(null);

  return (
    <NevModal open={open} onClose={onCancel} titleId="nev-confirm-title" initialFocusRef={confirmRef}>
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
    </NevModal>
  );
}
