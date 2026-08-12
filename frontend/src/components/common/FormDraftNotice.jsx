import { FileClock, Trash2 } from "lucide-react";

export default function FormDraftNotice({ restored = false, onDiscard }) {
  return (
    <div className={`form-draft-notice${restored ? " restored" : ""}`} role="status">
      <FileClock size={16} />
      <div>
        <strong>{restored ? "Unfinished draft restored" : "Draft protection is on"}</strong>
        <span>
          {restored
            ? "Your entered fields were recovered. Files must be selected again."
            : "Entered fields are kept in this browser tab if you close the form."}
        </span>
      </div>
      <button type="button" onClick={onDiscard}>
        <Trash2 size={14} />
        Discard draft
      </button>
    </div>
  );
}
