import { AlertTriangle, CheckCircle2, Clock3, ShieldCheck, X, XCircle } from "lucide-react";
import { useMemo, useState } from "react";

function formatRequestedTime(value) {
  if (!value) return "—";

  return new Date(`2000-01-01T${value}:00`).toLocaleTimeString("en-PH", {
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });
}

export default function AttendanceCorrectionRequestModal({
  mode,
  record,
  request,
  date,
  busy,
  onClose,
  onSubmit,
}) {
  const missingEntries = useMemo(
    () => record?.missing_time_out_entries || [],
    [record],
  );
  const [missingField, setMissingField] = useState(missingEntries[0]?.field || "");
  const [proposedTime, setProposedTime] = useState("");
  const [reason, setReason] = useState("");
  const [reviewRemarks, setReviewRemarks] = useState("");
  const isReview = mode === "review";

  function submitEmployeeRequest(event) {
    event.preventDefault();
    onSubmit({
      missing_field: missingField,
      proposed_time: proposedTime,
      reason: reason.trim(),
    });
  }

  return (
    <div className="modal-backdrop attendance-request-backdrop" role="presentation" onMouseDown={onClose}>
      <form
        className="app-modal attendance-request-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="attendance-request-title"
        onSubmit={isReview ? (event) => event.preventDefault() : submitEmployeeRequest}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="app-modal-header">
          <div className="app-modal-heading">
            <span className="app-modal-icon"><ShieldCheck size={20} /></span>
            <div>
              <span className="app-modal-eyebrow">
                {isReview ? "HR correction review" : "Employee correction request"}
              </span>
              <h2 id="attendance-request-title">
                {isReview ? request.personnel?.full_name : record.full_name}
              </h2>
              <p>
                {new Date(`${isReview ? request.attendance_date : date}T00:00:00`)
                  .toLocaleDateString("en-PH", {
                    weekday: "long",
                    month: "long",
                    day: "numeric",
                    year: "numeric",
                  })}
              </p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close correction request">
            <X size={18} />
          </button>
        </header>

        <div className="app-modal-body">
          {isReview ? (
            <>
            <div className="attendance-request-review-grid">
              <div>
                <small>Missing entry</small>
                <strong>{request.missing_label}</strong>
              </div>
              <div>
                <small>Employee’s proposed time</small>
                <strong><Clock3 size={14} />{formatRequestedTime(request.proposed_time)}</strong>
              </div>
              <div>
                <small>Submitted by</small>
                <strong>{request.submitted_by || "Personnel account"}</strong>
              </div>
              <div>
                <small>Request status</small>
                <strong>{request.status}</strong>
              </div>
            </div>

            <div className="attendance-request-reason">
              <small>Employee explanation</small>
              <p>{request.reason}</p>
            </div>

            <label>
              Reviewer remarks
              <textarea
                value={reviewRemarks}
                onChange={(event) => setReviewRemarks(event.target.value)}
                maxLength={500}
                placeholder="Required when rejecting; optional when approving..."
              />
            </label>

            <div className="attendance-request-security">
              <ShieldCheck size={16} />
              Approval applies only the requested missing time-out. The resulting attendance record
              remains unverified and requires a different authorized reviewer.
            </div>

            <footer className="app-modal-footer review-actions">
              <button type="button" onClick={onClose}>Cancel</button>
              <button
                type="button"
                className="reject"
                disabled={busy || reviewRemarks.trim().length < 10}
                onClick={() => onSubmit({
                  action: "Rejected",
                  review_remarks: reviewRemarks.trim(),
                })}
              >
                <XCircle size={15} />Reject
              </button>
              <button
                type="button"
                className="approve"
                disabled={busy}
                onClick={() => onSubmit({
                  action: "Approved",
                  review_remarks: reviewRemarks.trim(),
                })}
              >
                <CheckCircle2 size={15} />{busy ? "Processing…" : "Approve"}
              </button>
            </footer>
            </>
          ) : (
            <>
            <div className="attendance-request-warning">
              <AlertTriangle size={18} />
              <div>
                <strong>Submit an explanation, not a direct attendance edit</strong>
                <span>Use the time you actually left the office. HR will review it before any record changes.</span>
              </div>
            </div>

            <label>
              Missing entry
              <select
                value={missingField}
                onChange={(event) => setMissingField(event.target.value)}
                required
              >
                {missingEntries.map((entry) => (
                  <option value={entry.field} key={entry.field}>{entry.label}</option>
                ))}
              </select>
            </label>

            <label>
              Actual time you left
              <input
                type="time"
                value={proposedTime}
                onChange={(event) => setProposedTime(event.target.value)}
                required
              />
            </label>

            <label>
              Why did you forget to time out?
              <textarea
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                minLength={10}
                maxLength={500}
                required
                placeholder="Briefly explain what happened and provide any detail HR can verify..."
              />
            </label>

            <div className="attendance-request-security">
              <ShieldCheck size={16} />
              Your account, submission time, IP address, and device information are recorded for audit.
            </div>

            <footer className="app-modal-footer">
              <button type="button" onClick={onClose}>Cancel</button>
              <button
                type="submit"
                className="submit"
                disabled={busy || !missingField || !proposedTime || reason.trim().length < 10}
              >
                {busy ? "Submitting…" : "Submit to HR"}
              </button>
            </footer>
            </>
          )}
        </div>
      </form>
    </div>
  );
}
