import { useState } from "react";
import { AlertTriangle, ShieldCheck, X } from "lucide-react";

function timeInputValue(value) {
  if (!value) return "";
  return new Date(value).toLocaleTimeString("en-CA", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

export default function AttendanceCorrectionModal({
  record,
  date,
  busy,
  onClose,
  onSave,
}) {
  const absenceTypes = ["Absent", "Leave", "Official Business", "Work From Home"];
  const [recordType, setRecordType] = useState(
    absenceTypes.includes(record.display_status) ? record.display_status : "Time Entries",
  );
  const [times, setTimes] = useState({
    morning_time_in: timeInputValue(record.morning_time_in),
    morning_time_out: timeInputValue(record.morning_time_out),
    afternoon_time_in: timeInputValue(record.afternoon_time_in),
    afternoon_time_out: timeInputValue(record.afternoon_time_out),
  });
  const [reason, setReason] = useState("");
  const missingTimeOutEntries = record.missing_time_out_entries || [];

  function submit(event) {
    event.preventDefault();
    onSave({
      record_type: recordType,
      ...times,
      reason: reason.trim(),
    });
  }

  return (
    <div className="modal-backdrop attendance-correction-backdrop" role="presentation" onMouseDown={onClose}>
      <form
        className="app-modal attendance-correction-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="attendance-correction-title"
        onSubmit={submit}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="app-modal-header">
          <div className="app-modal-heading">
            <span className="app-modal-icon"><ShieldCheck size={20} /></span>
            <div>
              <span className="app-modal-eyebrow">Audited attendance correction</span>
              <h2 id="attendance-correction-title">{record.full_name}</h2>
              <p>{new Date(`${date}T00:00:00`).toLocaleDateString("en-PH", {
                weekday: "long",
                month: "long",
                day: "numeric",
                year: "numeric",
              })}</p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close correction form"><X size={18} /></button>
        </header>

        <div className="app-modal-body">
          {!!missingTimeOutEntries.length && (
            <div className="attendance-correction-exception">
              <AlertTriangle size={17} />
              <div>
                <strong>Missing time-out requires documented correction</strong>
                <span>
                  Enter the actual {missingTimeOutEntries.map((entry) => entry.label.toLowerCase()).join(" and ")}.
                  Do not use the scheduled end time unless it is supported by an approved record.
                </span>
              </div>
            </div>
          )}

          <label>
            Record type
            <select value={recordType} onChange={(event) => setRecordType(event.target.value)}>
              <option>Time Entries</option>
              {absenceTypes.map((type) => <option key={type}>{type}</option>)}
            </select>
          </label>

          {recordType === "Time Entries" && (
            <div className="attendance-correction-times">
              {[
                ["morning_time_in", "Morning time in"],
                ["morning_time_out", "Morning time out"],
                ["afternoon_time_in", "Afternoon time in"],
                ["afternoon_time_out", "Afternoon time out"],
              ].map(([field, label]) => (
                <label key={field}>
                  {label}
                  <input
                    type="time"
                    value={times[field]}
                    onChange={(event) => setTimes((current) => ({
                      ...current,
                      [field]: event.target.value,
                    }))}
                  />
                </label>
              ))}
            </div>
          )}

          <label>
            Correction reason
            <textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              minLength={10}
              maxLength={255}
              required
              placeholder="Explain why this attendance record needs to be corrected..."
            />
          </label>

          <div className="attendance-correction-note">
            <ShieldCheck size={16} />
            This edit will be recorded with your account in Activity Logs. A different authorized reviewer
            must verify it before DTR certification.
          </div>
        </div>

        <footer className="app-modal-footer">
          <button type="button" onClick={onClose}>Cancel</button>
          <button type="submit" className="save" disabled={busy || reason.trim().length < 10}>
            {busy ? "Saving…" : "Save for review"}
          </button>
        </footer>
      </form>
    </div>
  );
}
