import { useState } from "react";
import { AlertTriangle, ShieldCheck, X } from "lucide-react";
import FormStatusBanner from "../common/FormStatusBanner";
import ModalPortal from "../common/ModalPortal";

const MODAL_SUCCESS_DELAY_MS = 850;

function wait(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

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
  const isMissingDutyDay = record.display_status === "Missing";
  const [recordType, setRecordType] = useState(
    isMissingDutyDay
      ? "Absent"
      : absenceTypes.includes(record.display_status) ? record.display_status : "Time Entries",
  );
  const [times, setTimes] = useState({
    morning_time_in: timeInputValue(record.morning_time_in),
    morning_time_out: timeInputValue(record.morning_time_out),
    afternoon_time_in: timeInputValue(record.afternoon_time_in),
    afternoon_time_out: timeInputValue(record.afternoon_time_out),
  });
  const [reason, setReason] = useState("");
  const [confirmTimeEntryRemoval, setConfirmTimeEntryRemoval] = useState(false);
  const [status, setStatus] = useState(null);
  const missingTimeOutEntries = record.missing_time_out_entries || [];
  const hasExistingTimeEntries = record.has_time_entries ?? [
    record.morning_time_in,
    record.morning_time_out,
    record.afternoon_time_in,
    record.afternoon_time_out,
    record.overtime_time_in,
    record.overtime_time_out,
  ].some(Boolean);

  function clearSession(session) {
    setTimes((current) => ({
      ...current,
      [`${session}_time_in`]: "",
      [`${session}_time_out`]: "",
    }));
    setStatus(null);
  }

  async function submit(event) {
    event.preventDefault();
    setStatus(null);

    try {
      const payload = await onSave({
        record_type: recordType,
        replace_existing_time_entries: recordType === "Absent" && confirmTimeEntryRemoval,
        ...times,
        reason: reason.trim(),
      });
      setStatus({ type: "success", message: payload?.message || "Correction saved for review." });
      await wait(MODAL_SUCCESS_DELAY_MS);
      onClose();
    } catch (error) {
      setStatus({ type: "error", message: error.message || "The correction could not be saved." });
    }
  }

  return (
    <ModalPortal onClose={onClose} closeOnEscape={!busy}>
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
              <span className="app-modal-eyebrow">
                {isMissingDutyDay ? "Confirm missed duty day" : "Audited attendance correction"}
              </span>
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
          <FormStatusBanner status={status} />

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

          {isMissingDutyDay && (
            <div className="attendance-correction-exception">
              <AlertTriangle size={17} />
              <div>
                <strong>No attendance entry was recorded</strong>
                <span>
                  Confirm Absent only after checking approved leave, official business, holidays,
                  and the personnel's effective work schedule.
                </span>
              </div>
            </div>
          )}

          <label>
            Record type
            <select value={recordType} onChange={(event) => {
              setRecordType(event.target.value);
              setConfirmTimeEntryRemoval(false);
              setStatus(null);
            }}>
              <option>Time Entries</option>
              {absenceTypes.map((type) => <option key={type}>{type}</option>)}
            </select>
          </label>

          {recordType === "Absent" && hasExistingTimeEntries && (
            <div className="attendance-correction-exception">
              <AlertTriangle size={17} />
              <div>
                <strong>Existing time entries will be removed from this attendance record</strong>
                <span>
                  The original values will remain in the audit history. The absence will be unverified
                  and must be checked by a different authorized reviewer before DTR certification.
                </span>
                <label className="attendance-correction-replacement-confirm">
                  <input
                    type="checkbox"
                    checked={confirmTimeEntryRemoval}
                    onChange={(event) => {
                      setConfirmTimeEntryRemoval(event.target.checked);
                      setStatus(null);
                    }}
                  />
                  I confirm these time entries were entered by mistake and the employee was absent.
                </label>
              </div>
            </div>
          )}

          {recordType === "Time Entries" && (
            <div>
              <p className="attendance-correction-times-help">
                Keep the session the employee worked and clear the other one. At least one complete session must remain.
              </p>
              <div className="attendance-correction-times">
                {[
                  ["morning", "Morning session"],
                  ["afternoon", "Afternoon session"],
                ].map(([session, label]) => (
                  <section className="attendance-correction-session" key={session}>
                    <div className="attendance-correction-session-heading">
                      <strong>{label}</strong>
                      <button
                        type="button"
                        className="attendance-correction-clear-session"
                        onClick={() => clearSession(session)}
                        disabled={!times[`${session}_time_in`] && !times[`${session}_time_out`]}
                        aria-label={`Clear ${label.toLowerCase()} times`}
                      >
                        Clear times
                      </button>
                    </div>
                    {[
                      [`${session}_time_in`, `${label.replace(" session", "")} time in`],
                      [`${session}_time_out`, `${label.replace(" session", "")} time out`],
                    ].map(([field, fieldLabel]) => (
                      <label key={field}>
                        {fieldLabel}
                        <input
                          type="time"
                          value={times[field]}
                          onChange={(event) => {
                            setTimes((current) => ({
                              ...current,
                              [field]: event.target.value,
                            }));
                            setStatus(null);
                          }}
                        />
                      </label>
                    ))}
                  </section>
                ))}
              </div>
            </div>
          )}

          <label>
            Correction reason
            <textarea
              value={reason}
              onChange={(event) => { setReason(event.target.value); setStatus(null); }}
              minLength={10}
              maxLength={255}
              required
              placeholder={isMissingDutyDay
                ? "Document why this missed duty day is being confirmed as absent..."
                : "Explain why this attendance record needs to be corrected..."}
            />
          </label>

          <div className="attendance-correction-note">
            <ShieldCheck size={16} />
            {isMissingDutyDay
              ? "The absence will remain unverified until a different authorized reviewer checks it."
              : "This edit will be recorded with your account in Activity Logs. A different authorized reviewer must verify it before DTR certification."}
          </div>
        </div>

        <footer className="app-modal-footer">
          <button type="button" onClick={onClose}>Cancel</button>
          <button
            type="submit"
            className="save"
            disabled={busy || reason.trim().length < 10 || (
              recordType === "Absent" && hasExistingTimeEntries && !confirmTimeEntryRemoval
            )}
          >
            {busy
              ? "Saving…"
              : isMissingDutyDay && recordType === "Absent" ? "Confirm absent for review" : "Save for review"}
          </button>
        </footer>
      </form>
    </div>
    </ModalPortal>
  );
}
