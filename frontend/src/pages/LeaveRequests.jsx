import { useEffect, useMemo, useState } from "react";
import {
  BadgeCheck,
  Ban,
  BriefcaseBusiness,
  CalendarDays,
  CheckCircle2,
  Clock3,
  Download,
  Eye,
  FileCheck2,
  FilePlus2,
  Plus,
  Search,
  Send,
  ShieldCheck,
  X,
  XCircle,
} from "lucide-react";
import Pagination from "../components/common/Pagination";
import { apiFetch } from "../lib/auth";

const emptyForm = {
  leave_type: "Vacation Leave",
  day_part: "Full Day",
  date_from: "",
  date_to: "",
  reason: "",
};

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(payload.message || "The request could not be completed.");
    error.fields = payload.errors || {};
    throw error;
  }

  return payload;
}

function formatDate(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", { dateStyle: "medium" }).format(
    new Date(`${value}T00:00:00`),
  );
}

function formatDateTime(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function statusClass(status) {
  return String(status || "").toLowerCase().replaceAll(" ", "-");
}

export default function LeaveRequests() {
  const [records, setRecords] = useState([]);
  const [summary, setSummary] = useState({
    total: 0,
    pending: 0,
    approved: 0,
    rejected: 0,
    cancelled: 0,
  });
  const [options, setOptions] = useState({ types: [], statuses: [] });
  const [meta, setMeta] = useState({ can_create: false, can_review: false });
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [documentFile, setDocumentFile] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [selected, setSelected] = useState(null);
  const [actionMode, setActionMode] = useState("");
  const [actionReason, setActionReason] = useState("");
  const [actionBusy, setActionBusy] = useState(false);
  const [downloadingId, setDownloadingId] = useState(null);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: "25",
      });
      if (search.trim()) params.set("search", search.trim());
      if (statusFilter) params.set("status", statusFilter);
      if (typeFilter) params.set("type", typeFilter);

      apiFetch(`/leave-requests?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then((payload) => {
          setRecords(payload.data);
          setSummary(payload.summary);
          setOptions(payload.options);
          setMeta(payload.meta);
          setPagination(payload.meta?.pagination || null);
          setSelected((current) => current
            ? payload.data.find((record) => record.leave_id === current.leave_id) || null
            : null);
          setError("");
          if (!payload.data.length && page > 1) {
            setPage((current) => Math.max(1, current - 1));
          }
        })
        .catch((requestError) => {
          if (requestError.name !== "AbortError") setError(requestError.message);
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 250);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [page, search, statusFilter, typeFilter, refreshKey]);

  const summaryCards = useMemo(() => [
    { label: "All requests", value: summary.total, icon: CalendarDays, tone: "blue" },
    { label: "Pending review", value: summary.pending, icon: Clock3, tone: "orange" },
    { label: "Approved", value: summary.approved, icon: BadgeCheck, tone: "green" },
    { label: "Rejected / Cancelled", value: summary.rejected + summary.cancelled, icon: Ban, tone: "red" },
  ], [summary]);

  function openCreate() {
    setForm(emptyForm);
    setDocumentFile(null);
    setFieldErrors({});
    setCreateOpen(true);
  }

  function closeCreate() {
    if (saving) return;
    setCreateOpen(false);
    setFieldErrors({});
  }

  async function submitRequest(event) {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});
    setError("");
    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => body.append(key, value));
    if (documentFile) body.append("supporting_document", documentFile);

    try {
      const payload = await apiFetch("/leave-requests", {
        method: "POST",
        body,
      }).then(readResponse);
      setNotice(payload.message);
      setCreateOpen(false);
      setForm(emptyForm);
      setDocumentFile(null);
      setPage(1);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4000);
    } catch (requestError) {
      setFieldErrors(
        Object.keys(requestError.fields || {}).length
          ? requestError.fields
          : { general: [requestError.message] },
      );
    } finally {
      setSaving(false);
    }
  }

  function beginAction(mode) {
    setActionMode(mode);
    setActionReason("");
  }

  async function submitAction(event) {
    event.preventDefault();
    if (!selected || !actionMode) return;
    setActionBusy(true);
    setError("");

    try {
      const reviewing = ["Approved", "Rejected"].includes(actionMode);
      const payload = await apiFetch(
        reviewing
          ? `/leave-requests/${selected.leave_id}/review`
          : `/leave-requests/${selected.leave_id}/cancel`,
        {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(
            reviewing
              ? { decision: actionMode, remarks: actionReason || null }
              : { reason: actionReason },
          ),
        },
      ).then(readResponse);
      setNotice(payload.message);
      setSelected(payload.data);
      setActionMode("");
      setActionReason("");
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4000);
    } catch (requestError) {
      const message = Object.values(requestError.fields || {}).flat()[0]
        || requestError.message;
      setError(message);
    } finally {
      setActionBusy(false);
    }
  }

  async function downloadDocument(record) {
    setDownloadingId(record.leave_id);
    setError("");

    try {
      const response = await apiFetch(record.document_url);
      if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.message || "The supporting document could not be downloaded.");
      }
      const blob = await response.blob();
      const disposition = response.headers.get("Content-Disposition") || "";
      const match = disposition.match(/filename="?([^";]+)"?/i);
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = match?.[1] || `${record.request_number}-attachment`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setDownloadingId(null);
    }
  }

  return (
    <section className="leave-page">
      <header className="leave-hero">
        <div>
          <span><BriefcaseBusiness size={14} /> Personnel absence workflow</span>
          <h1>Leave & Official Business</h1>
          <p>Submit, review, and track authorized absences that flow directly into attendance and DTR records.</p>
        </div>
        {meta.can_create && (
          <button type="button" onClick={openCreate}>
            <Plus size={18} /> New request
          </button>
        )}
        <i aria-hidden="true"></i>
      </header>

      {notice && <div className="users-notice success"><CheckCircle2 size={18} />{notice}</div>}
      {error && <div className="users-notice error"><X size={18} />{error}</div>}

      <div className="leave-summary-grid">
        {summaryCards.map(({ label, value, icon: Icon, tone }) => (
          <article key={label}>
            <span className={tone}><Icon size={21} /></span>
            <div><strong>{value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="panel leave-register">
        <div className="leave-register-header">
          <div>
            <span>{meta.can_review ? "Review queue and request register" : "My request history"}</span>
            <h2>Absence requests</h2>
          </div>
          <div className="leave-filters">
            <label>
              <Search size={16} />
              <input
                type="search"
                value={search}
                onChange={(event) => { setSearch(event.target.value); setPage(1); }}
                placeholder="Search request or personnel..."
              />
            </label>
            <select value={typeFilter} onChange={(event) => { setTypeFilter(event.target.value); setPage(1); }}>
              <option value="">All request types</option>
              {options.types.map((type) => <option key={type}>{type}</option>)}
            </select>
            <select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}>
              <option value="">All statuses</option>
              {options.statuses.map((status) => <option key={status}>{status}</option>)}
            </select>
          </div>
        </div>

        <div className="users-table-wrap">
          <table className="users-table leave-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>Personnel</th>
                <th>Type</th>
                <th>Period</th>
                <th>Days</th>
                <th>Status</th>
                <th><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="users-empty">Loading requests…</td></tr>
              ) : records.length ? records.map((record) => (
                <tr key={record.leave_id}>
                  <td>
                    <strong className="leave-request-number">{record.request_number}</strong>
                    <small>{formatDateTime(record.created_at)}</small>
                  </td>
                  <td>
                    <strong>{record.personnel?.full_name || "Unknown personnel"}</strong>
                    <small>
                      {record.personnel?.employee_number || "No employee number"}
                      {" · "}
                      {record.personnel?.department?.code || "No office"}
                    </small>
                  </td>
                  <td><span className="leave-type-chip">{record.leave_type}</span></td>
                  <td>
                    <strong>{formatDate(record.date_from)}</strong>
                    <small>{record.date_from === record.date_to ? "Single duty day" : `to ${formatDate(record.date_to)}`}</small>
                  </td>
                  <td><strong>{record.total_days}</strong><small>scheduled</small></td>
                  <td><span className={`leave-status ${statusClass(record.status)}`}>{record.status}</span></td>
                  <td>
                    <button
                      type="button"
                      className="leave-view-button"
                      onClick={() => { setSelected(record); setActionMode(""); setActionReason(""); }}
                    >
                      <Eye size={15} /> View
                    </button>
                  </td>
                </tr>
              )) : (
                <tr><td colSpan="7" className="users-empty">No requests match the selected filters.</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination
          pagination={pagination}
          onPageChange={setPage}
          disabled={loading}
          itemLabel="requests"
        />
      </div>

      {createOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeCreate();
        }}>
          <div className="user-modal form-modal leave-form-modal" role="dialog" aria-modal="true" aria-labelledby="leave-form-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><FilePlus2 size={21} /></span>
                <div>
                  <h2 id="leave-form-title">New absence request</h2>
                  <p>Only scheduled duty days are counted and sent for approval.</p>
                </div>
              </div>
              <button type="button" onClick={closeCreate} aria-label="Close"><X size={20} /></button>
            </div>
            <form className="user-form" onSubmit={submitRequest}>
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}
              <div className="form-field form-field-full">
                <label htmlFor="leave_type">Request type</label>
                <select
                  id="leave_type"
                  value={form.leave_type}
                  onChange={(event) => setForm((current) => ({ ...current, leave_type: event.target.value }))}
                  required
                >
                  {options.types.map((type) => <option key={type}>{type}</option>)}
                </select>
                {fieldErrors.leave_type && <small className="field-error">{fieldErrors.leave_type[0]}</small>}
              </div>
              <div className="form-field">
                <label htmlFor="leave_date_from">Starts on</label>
                <input
                  id="leave_date_from"
                  type="date"
                  value={form.date_from}
                  onChange={(event) => setForm((current) => ({
                    ...current,
                    date_from: event.target.value,
                    date_to: current.date_to && current.date_to < event.target.value
                      ? event.target.value
                      : current.date_to,
                  }))}
                  required
                />
                {fieldErrors.date_from && <small className="field-error">{fieldErrors.date_from[0]}</small>}
              </div>
              <div className="form-field">
                <label htmlFor="leave_date_to">Ends on</label>
                <input
                  id="leave_date_to"
                  type="date"
                  min={form.date_from || undefined}
                  value={form.date_to}
                  onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))}
                  required
                />
                {fieldErrors.date_to && <small className="field-error">{fieldErrors.date_to[0]}</small>}
              </div>
              <div className="form-field form-field-full">
                <label htmlFor="leave_reason">Reason or official purpose</label>
                <textarea
                  id="leave_reason"
                  rows="4"
                  minLength="10"
                  maxLength="1000"
                  value={form.reason}
                  onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
                  placeholder="Provide enough detail for the approving officer."
                  required
                />
                <small className="form-help">{form.reason.length}/1000 characters</small>
                {fieldErrors.reason && <small className="field-error">{fieldErrors.reason[0]}</small>}
              </div>
              <div className="form-field form-field-full">
                <label htmlFor="leave_document">Supporting document <span>Optional</span></label>
                <input
                  id="leave_document"
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp"
                  onChange={(event) => setDocumentFile(event.target.files?.[0] || null)}
                />
                <small className="form-help">PDF or image, maximum 5 MB. Stored privately.</small>
                {fieldErrors.supporting_document && <small className="field-error">{fieldErrors.supporting_document[0]}</small>}
              </div>
              <div className="leave-form-note form-field-full">
                <ShieldCheck size={18} />
                <span>Approved requests create verified attendance entries and become part of the monthly DTR.</span>
              </div>
              <div className="user-modal-actions">
                <button type="button" className="secondary" onClick={closeCreate}>Cancel</button>
                <button type="submit" disabled={saving}>
                  <Send size={16} /> {saving ? "Submitting…" : "Submit request"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {selected && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !actionBusy) setSelected(null);
        }}>
          <div className="user-modal form-modal leave-detail-modal" role="dialog" aria-modal="true" aria-labelledby="leave-detail-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><FileCheck2 size={21} /></span>
                <div>
                  <h2 id="leave-detail-title">{selected.request_number}</h2>
                  <p>{selected.personnel?.full_name} · {selected.leave_type}</p>
                </div>
              </div>
              <button type="button" onClick={() => setSelected(null)} aria-label="Close"><X size={20} /></button>
            </div>
            <div className="leave-detail-content">
              <div className="leave-detail-overview">
                <div><span>Status</span><strong className={`leave-status ${statusClass(selected.status)}`}>{selected.status}</strong></div>
                <div><span>Scheduled period</span><strong>{formatDate(selected.date_from)} – {formatDate(selected.date_to)}</strong></div>
                <div><span>Counted duty days</span><strong>{selected.total_days}</strong></div>
                <div><span>Office</span><strong>{selected.personnel?.department?.code || "Unassigned"}</strong></div>
              </div>
              <section className="leave-reason-card">
                <span>Reason / official purpose</span>
                <p>{selected.reason}</p>
              </section>
              {selected.review_remarks && (
                <section className="leave-review-card">
                  <span>Review remarks</span>
                  <p>{selected.review_remarks}</p>
                  <small>{selected.reviewed_by || "Reviewer"} · {formatDateTime(selected.reviewed_at)}</small>
                </section>
              )}
              {selected.cancellation_reason && (
                <section className="leave-review-card cancelled">
                  <span>Cancellation reason</span>
                  <p>{selected.cancellation_reason}</p>
                </section>
              )}
              {selected.has_document && (
                <button
                  type="button"
                  className="leave-document-button"
                  onClick={() => downloadDocument(selected)}
                  disabled={downloadingId === selected.leave_id}
                >
                  <Download size={16} />
                  {downloadingId === selected.leave_id ? "Downloading…" : "Download supporting document"}
                </button>
              )}
              <section className="leave-history">
                <div><span>Audit history</span><small>Immutable workflow events</small></div>
                {selected.history.length ? selected.history.map((entry) => (
                  <article key={entry.id}>
                    <i className={statusClass(entry.to_status)}></i>
                    <div>
                      <strong>{entry.to_status}</strong>
                      <p>{entry.remarks || "No remarks provided."}</p>
                      <small>{entry.changed_by || "System"} · {formatDateTime(entry.created_at)}</small>
                    </div>
                  </article>
                )) : <p className="leave-history-empty">No history is available.</p>}
              </section>

              {actionMode && (
                <form className="leave-action-form" onSubmit={submitAction}>
                  <label htmlFor="leave_action_reason">
                    {actionMode === "Approved"
                      ? "Approval remarks (optional)"
                      : actionMode === "Rejected"
                        ? "Reason for rejection"
                        : "Reason for cancellation"}
                  </label>
                  <textarea
                    id="leave_action_reason"
                    rows="3"
                    minLength={actionMode === "Approved" ? undefined : 10}
                    maxLength="1000"
                    value={actionReason}
                    onChange={(event) => setActionReason(event.target.value)}
                    required={actionMode !== "Approved"}
                    autoFocus
                  />
                  <div>
                    <button type="button" className="secondary" onClick={() => setActionMode("")}>Back</button>
                    <button type="submit" className={actionMode === "Approved" ? "approve" : "danger"} disabled={actionBusy}>
                      {actionBusy ? "Saving…" : `Confirm ${actionMode.toLowerCase()}`}
                    </button>
                  </div>
                </form>
              )}
            </div>
            {!actionMode && (
              <div className="leave-detail-actions">
                {selected.can_cancel && (
                  <button type="button" className="danger" onClick={() => beginAction("Cancelled")}>
                    <XCircle size={16} /> Cancel request
                  </button>
                )}
                <span></span>
                {selected.can_review && (
                  <>
                    <button type="button" className="reject" onClick={() => beginAction("Rejected")}>
                      <XCircle size={16} /> Reject
                    </button>
                    <button type="button" className="approve" onClick={() => beginAction("Approved")}>
                      <BadgeCheck size={16} /> Approve
                    </button>
                  </>
                )}
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
