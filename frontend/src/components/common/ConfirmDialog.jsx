import { useEffect } from "react";
import { AlertTriangle, X } from "lucide-react";

export default function ConfirmDialog({ options, onResult }) {
  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === "Escape") onResult(false);
    }

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [onResult]);

  return (
    <div
      className="modal-backdrop confirm-modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onResult(false);
      }}
    >
      <section
        className="user-modal confirm-modal"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-modal-title"
        aria-describedby="confirm-modal-message"
      >
        <div className="user-modal-header">
          <div>
            <span className="modal-icon confirm-modal-icon"><AlertTriangle size={21} /></span>
            <div>
              <h2 id="confirm-modal-title">{options.title}</h2>
              <p>{options.subtitle || "Please confirm this action before continuing."}</p>
            </div>
          </div>
          <button type="button" onClick={() => onResult(false)} aria-label="Close confirmation">
            <X size={19} />
          </button>
        </div>

        <div className="confirm-modal-body">
          <p id="confirm-modal-message">{options.message}</p>
          {options.note && <small>{options.note}</small>}
        </div>

        <div className="app-modal-footer confirm-modal-actions">
          <button type="button" autoFocus onClick={() => onResult(false)}>
            {options.cancelLabel || "Cancel"}
          </button>
          <button
            type="button"
            className={options.tone === "warning" ? "confirm-warning" : "confirm-danger"}
            onClick={() => onResult(true)}
          >
            {options.confirmLabel || "Confirm"}
          </button>
        </div>
      </section>
    </div>
  );
}
