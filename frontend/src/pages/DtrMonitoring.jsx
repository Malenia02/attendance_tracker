import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  AlertCircle,
  Archive,
  BadgeCheck,
  CalendarRange,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  Clock3,
  CheckCheck,
  Download,
  ExternalLink,
  FileCheck2,
  FileClock,
  PencilLine,
  RefreshCw,
  Search,
  ShieldCheck,
  TimerReset,
  Users,
  X,
} from "lucide-react";
import { apiFetch } from "../lib/auth";
import { formatDuration } from "../lib/duration";
import AttendanceCorrectionModal from "../components/attendance/AttendanceCorrectionModal";

function currentMonthKey() {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function formatMinutes(value) {
  const minutes = Number(value) || 0;
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m`;
}

function formatTime(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", {
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  }).format(new Date(value));
}

function statusClass(value) {
  return value.toLowerCase().replaceAll(" ", "-");
}

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || "The request could not be completed.");
  return payload;
}

export default function DtrMonitoring() {
  const navigate = useNavigate();
  const [month, setMonth] = useState(currentMonthKey);
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState({
    personnel: 0,
    ready: 0,
    needs_attention: 0,
    certified: 0,
    late_occurrences: 0,
    half_days: 0,
  });
  const [meta, setMeta] = useState({
    month_label: "",
    can_certify: false,
    can_verify_attendance: false,
    can_correct_attendance: false,
    can_generate: false,
    can_manage_others: false,
    can_request_reopen: false,
    can_approve_reopen: false,
  });
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [readinessFilter, setReadinessFilter] = useState("");
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState(null);
  const [generating, setGenerating] = useState(false);
  const [selectedIds, setSelectedIds] = useState([]);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [exceptionBusy, setExceptionBusy] = useState(false);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch(`/dtr?month=${month}`, { signal: controller.signal })
      .then(readResponse)
      .then((payload) => {
        setRows(payload.data);
        setSummary(payload.summary);
        setMeta({
          month_label: payload.month_label,
          can_certify: payload.can_certify,
          can_verify_attendance: payload.can_verify_attendance,
          can_correct_attendance: payload.can_correct_attendance,
          can_generate: payload.can_generate,
          can_manage_others: payload.can_manage_others,
          can_request_reopen: payload.can_request_reopen,
          can_approve_reopen: payload.can_approve_reopen,
        });
        setSelectedIds((current) => current.filter(
          (id) => payload.data.some(
            (row) => row.personnel_id === id && row.certification.status === "Certified",
          ),
        ));
        setSelected((current) => current
          ? payload.data.find((row) => row.personnel_id === current.personnel_id) || null
          : null);
      })
      .catch((requestError) => {
        if (requestError.name !== "AbortError") setError(requestError.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [month, refreshKey]);

  const filteredRows = useMemo(() => {
    const query = search.trim().toLowerCase();

    return rows.filter((row) => {
      const matchesSearch = !query
        || row.full_name.toLowerCase().includes(query)
        || row.employee_number.toLowerCase().includes(query)
        || row.department?.code.toLowerCase().includes(query);
      const matchesStatus = !statusFilter || row.certification.status === statusFilter;
      const matchesReadiness = !readinessFilter
        || (readinessFilter === "ready" ? row.is_ready : !row.is_ready);

      return matchesSearch && matchesStatus && matchesReadiness;
    });
  }, [rows, search, statusFilter, readinessFilter]);

  const certifiedRows = useMemo(
    () => filteredRows.filter((row) => row.certification.status === "Certified"),
    [filteredRows],
  );

  async function updateStatus(row, status, remarks = null) {
    setBusyId(row.personnel_id);
    setError("");

    try {
      const payload = await apiFetch(`/dtr/${row.personnel_id}/status`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ month, status, remarks }),
      }).then(readResponse);

      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
      return true;
    } catch (requestError) {
      setError(requestError.message);
      return false;
    } finally {
      setBusyId(null);
    }
  }

  async function correctAttendance(row, day, values) {
    setExceptionBusy(true);
    setError("");

    try {
      const payload = await apiFetch("/attendance/correction", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          personnel_id: row.personnel_id,
          attendance_date: day.date,
          ...values,
        }),
      }).then(readResponse);

      setNotice(`${payload.message} DTR readiness recalculated.`);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4500);
      return true;
    } catch (requestError) {
      setError(requestError.message);
      return false;
    } finally {
      setExceptionBusy(false);
    }
  }

  async function requestReopen(row, reason, affectedDates) {
    setBusyId(row.personnel_id);
    setError("");

    try {
      const payload = await apiFetch(`/dtr/${row.personnel_id}/reopen-requests`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          month,
          reason,
          affected_dates: affectedDates,
        }),
      }).then(readResponse);

      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4500);
      return true;
    } catch (requestError) {
      setError(requestError.message);
      return false;
    } finally {
      setBusyId(null);
    }
  }

  async function reviewReopen(row, reopenRequestId, decision, reviewRemarks = null) {
    setBusyId(row.personnel_id);
    setError("");

    try {
      const payload = await apiFetch(`/dtr/reopen-requests/${reopenRequestId}/review`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          decision,
          review_remarks: reviewRemarks,
        }),
      }).then(readResponse);

      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 5000);
      return true;
    } catch (requestError) {
      setError(requestError.message);
      return false;
    } finally {
      setBusyId(null);
    }
  }

  async function verifyAttendanceBulk(attendanceIds) {
    setExceptionBusy(true);
    setError("");

    try {
      const payload = await apiFetch("/attendance/verify-bulk", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ attendance_ids: attendanceIds }),
      }).then(readResponse);

      setNotice(`${payload.message} DTR readiness recalculated.`);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4000);
      return true;
    } catch (requestError) {
      setError(requestError.message);
      return false;
    } finally {
      setExceptionBusy(false);
    }
  }

  function exportCsv() {
    const headers = [
      "Employee No.", "Personnel", "Office", "Expected Days", "Recorded Days",
      "Present", "Half Day", "Late Days", "Late Minutes", "Absent/Missing",
      "Incomplete", "Unverified", "Work Time", "Completion", "DTR Status",
    ];
    const values = filteredRows.map((row) => [
      row.employee_number,
      row.full_name,
      row.department?.code || "",
      row.expected_days,
      row.recorded_days,
      row.present_days,
      row.half_days,
      row.late_days,
      row.late_minutes,
      row.absent_days,
      row.incomplete_days,
      row.unverified_days,
      formatMinutes(row.total_work_minutes),
      `${row.completion_percent}%`,
      row.certification.status,
    ]);
    const escape = (value) => `"${String(value).replaceAll('"', '""')}"`;
    const csv = [headers, ...values].map((line) => line.map(escape).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = `DTR-Monitoring-${month}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  async function generateDtr() {
    const personnelIds = selectedIds.length
      ? selectedIds
      : certifiedRows.map((row) => row.personnel_id);

    if (!personnelIds.length) return;
    setGenerating(true);
    setError("");

    try {
      const response = await apiFetch("/dtr/generate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ month, personnel_ids: personnelIds }),
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.message || "The DTR document could not be generated.");
      }

      const blob = await response.blob();
      const disposition = response.headers.get("Content-Disposition") || "";
      const matchedName = disposition.match(/filename="?([^";]+)"?/i);
      const filename = matchedName?.[1] || `DTR-${month}.docx`;
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
      setNotice(`Generated ${personnelIds.length} personnel DTR${personnelIds.length === 1 ? "" : "s"}.`);
      window.setTimeout(() => setNotice(""), 4000);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setGenerating(false);
    }
  }

  function toggleSelected(personnelId) {
    setSelectedIds((current) => current.includes(personnelId)
      ? current.filter((id) => id !== personnelId)
      : [...current, personnelId]);
  }

  function toggleAllVisible() {
    const visibleIds = certifiedRows.map((row) => row.personnel_id);
    if (!visibleIds.length) return;
    const allVisibleSelected = visibleIds.every((id) => selectedIds.includes(id));

    setSelectedIds((current) => allVisibleSelected
      ? current.filter((id) => !visibleIds.includes(id))
      : [...new Set([...current, ...visibleIds])]);
  }

  const cards = [
    { label: "Active Personnel", value: summary.personnel, icon: Users, tone: "blue" },
    { label: "Ready for DTR", value: summary.ready, icon: ClipboardCheck, tone: "green" },
    { label: "Needs Attention", value: summary.needs_attention, icon: AlertCircle, tone: "orange" },
    { label: "Certified", value: summary.certified, icon: ShieldCheck, tone: "violet" },
    { label: "Late Records", value: summary.late_occurrences, icon: Clock3, tone: "red" },
    { label: "Half Days", value: summary.half_days, icon: FileClock, tone: "cyan" },
  ];

  return (
    <section className="dtr-page">
      <header className="dtr-hero">
        <div>
          <span><CalendarRange size={14} /> Monthly attendance control</span>
          <h1>DTR Monitoring</h1>
          <p>Track completeness, attendance exceptions, verification, and certification in one place.</p>
        </div>
        <div className="dtr-hero-actions">
          <label>
            <small>Reporting month</small>
            <input
              type="month"
              value={month}
              max={currentMonthKey()}
              onChange={(event) => {
                setLoading(true);
                setError("");
                setMonth(event.target.value);
              }}
            />
          </label>
          <button type="button" onClick={exportCsv} disabled={!filteredRows.length}>
            <Download size={17} /> Export CSV
          </button>
          {meta.can_generate && (
            <button
              type="button"
              className="generate"
              onClick={generateDtr}
              disabled={generating || (!selectedIds.length && !certifiedRows.length)}
              title="Only certified DTR records can be generated"
            >
              <FileCheck2 size={17} />
              {generating
                ? "Generating…"
                : selectedIds.length
                  ? `Generate Certified DTR (${selectedIds.length})`
                  : certifiedRows.length
                    ? `Generate Certified DTR (${certifiedRows.length})`
                    : "No Certified DTR"}
            </button>
          )}
        </div>
        <i></i>
      </header>

      {notice && <div className="users-notice success"><BadgeCheck size={18} />{notice}</div>}
      {error && <div className="users-notice error"><X size={18} />{error}</div>}

      <div className="dtr-summary-grid">
        {cards.map(({ label, value, icon: Icon, tone }, index) => (
          <article key={label} style={{ "--delay": `${index * 55}ms` }}>
            <span className={tone}><Icon size={20} /></span>
            <div><strong>{value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="panel dtr-monitor-panel">
        <div className="dtr-panel-header">
          <div>
            <span>Monthly register</span>
            <h2>{meta.month_label || "DTR records"}</h2>
          </div>
          <div className="dtr-filters">
            <label className="dtr-search">
              <Search size={16} />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search personnel..."
              />
            </label>
            <select value={readinessFilter} onChange={(event) => setReadinessFilter(event.target.value)}>
              <option value="">All readiness</option>
              <option value="ready">Ready</option>
              <option value="attention">Needs attention</option>
            </select>
            <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
              <option value="">All DTR statuses</option>
              {["Draft", "Submitted", "Certified", "Returned", "Reopened"].map((status) => (
                <option key={status}>{status}</option>
              ))}
            </select>
          </div>
        </div>

        <div className="users-table-wrap">
          <table className="users-table dtr-table">
            <thead>
              <tr>
                {meta.can_generate && (
                  <th className="dtr-select-column">
                    <input
                      type="checkbox"
                      aria-label="Select all visible certified personnel"
                      checked={certifiedRows.length > 0
                        && certifiedRows.every((row) => selectedIds.includes(row.personnel_id))}
                      disabled={!certifiedRows.length}
                      onChange={toggleAllVisible}
                    />
                  </th>
                )}
                <th>Personnel</th>
                <th>Completion</th>
                <th>Attendance</th>
                <th>Exceptions</th>
                <th>Issues</th>
                <th>DTR Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={meta.can_generate ? 8 : 7} className="users-empty">Calculating monthly DTR records…</td></tr>
              ) : !filteredRows.length ? (
                <tr><td colSpan={meta.can_generate ? 8 : 7} className="users-empty">No DTR records match these filters.</td></tr>
              ) : filteredRows.map((row) => (
                <tr key={row.personnel_id}>
                  {meta.can_generate && (
                    <td className="dtr-select-column">
                      <input
                        type="checkbox"
                        aria-label={`Select ${row.full_name}`}
                        checked={selectedIds.includes(row.personnel_id)}
                        disabled={row.certification.status !== "Certified"}
                        title={row.certification.status === "Certified"
                          ? `Select ${row.full_name} for DTR generation`
                          : "This DTR must be certified before it can be generated"}
                        onChange={() => toggleSelected(row.personnel_id)}
                      />
                    </td>
                  )}
                  <td>
                    <div className="user-identity">
                      <span>{row.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span>
                      <div>
                        <strong>{row.full_name}</strong>
                        <small>{row.employee_number} · {row.department?.code || "No office"}</small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div className="dtr-progress">
                      <div><strong>{row.completion_percent}%</strong><small>{row.recorded_days}/{row.expected_days} days</small></div>
                      <span><i style={{ width: `${row.completion_percent}%` }}></i></span>
                    </div>
                  </td>
                  <td>
                    <div className="dtr-count-pills">
                      <span className="present">{row.present_days} Present</span>
                      <span className="half">{row.half_days} Half</span>
                    </div>
                  </td>
                  <td>
                    <div className="dtr-exceptions">
                      <span className={row.late_days ? "warning" : ""}>{row.late_days} late</span>
                      <span className={row.absent_days ? "danger" : ""}>{row.absent_days} absent</span>
                    </div>
                  </td>
                  <td>
                    {row.is_ready ? (
                      <span className="dtr-ready"><CheckCircle2 size={13} />Ready</span>
                    ) : (
                      <div className="dtr-issues">
                        {!!row.issues.missing && <span>{row.issues.missing} missing</span>}
                        {!!row.issues.incomplete && <span>{row.issues.incomplete} incomplete</span>}
                        {!!row.issues.unverified && <span>{row.issues.unverified} unverified</span>}
                      </div>
                    )}
                  </td>
                  <td>
                    <span className={`dtr-cert-status ${statusClass(row.certification.status)}`}>
                      {row.certification.status}
                    </span>
                  </td>
                  <td>
                    <button className="dtr-view-button" type="button" onClick={() => setSelected(row)}>
                      View <ChevronRight size={14} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <footer className="users-table-footer">
          Showing {filteredRows.length} of {rows.length} personnel
        </footer>
      </div>

      {selected && (
        <DtrDetails
          row={selected}
          monthLabel={meta.month_label}
          canCertify={meta.can_certify}
          canVerify={meta.can_verify_attendance}
          canCorrect={meta.can_correct_attendance}
          canRequestReopen={meta.can_request_reopen}
          canApproveReopen={meta.can_approve_reopen}
          busy={busyId === selected.personnel_id}
          exceptionBusy={exceptionBusy}
          onClose={() => setSelected(null)}
          onStatus={(status, remarks) => updateStatus(selected, status, remarks)}
          onCorrect={(day, values) => correctAttendance(selected, day, values)}
          onVerifyBulk={verifyAttendanceBulk}
          onRequestReopen={(reason, dates) => requestReopen(selected, reason, dates)}
          onReviewReopen={(requestId, decision, remarks) => (
            reviewReopen(selected, requestId, decision, remarks)
          )}
          onReview={(date) => navigate(
            `/attendance?date=${date}&personnel=${selected.personnel_id}&correction=1`,
          )}
        />
      )}
    </section>
  );
}

function DtrDetails({
  row,
  monthLabel,
  canCertify,
  canVerify,
  canCorrect,
  canRequestReopen,
  canApproveReopen,
  busy,
  exceptionBusy,
  onClose,
  onStatus,
  onCorrect,
  onVerifyBulk,
  onRequestReopen,
  onReviewReopen,
  onReview,
}) {
  const [showReturnForm, setShowReturnForm] = useState(false);
  const [showReopenForm, setShowReopenForm] = useState(false);
  const [returnReason, setReturnReason] = useState("");
  const [reopenReason, setReopenReason] = useState("");
  const [affectedDates, setAffectedDates] = useState([]);
  const [rejectionRequestId, setRejectionRequestId] = useState(null);
  const [rejectionReason, setRejectionReason] = useState("");
  const [correctionDay, setCorrectionDay] = useState(null);
  const problemDays = row.daily_records.filter((day) => (
    ["Missing", "Incomplete"].includes(day.status)
    || (day.is_duty_day && !["Missing", "Holiday"].includes(day.status) && !day.is_verified)
  ));
  const verificationOpen = ["Draft", "Returned", "Reopened"].includes(
    row.certification.status,
  );
  const verifiableDays = problemDays.filter((day) => (
    verificationOpen
    && day.attendance_id
    && !day.is_verified
    && !["Missing", "Incomplete"].includes(day.status)
  ));
  const latestHistory = row.certification.history?.slice(0, 4) || [];
  const reopenRequests = row.certification.reopen_requests || [];
  const archivedVersions = row.certification.versions || [];
  const pendingReopen = reopenRequests.find((request) => request.status === "Pending");

  function toggleAffectedDate(date) {
    setAffectedDates((current) => current.includes(date)
      ? current.filter((value) => value !== date)
      : [...current, date].sort());
  }

  async function saveInlineCorrection(values) {
    const correctedDate = correctionDay.date;
    const updated = await onCorrect(correctionDay, values);

    if (updated) {
      setCorrectionDay(
        problemDays.find((day) => day.date !== correctedDate) || null,
      );
    }
  }

  return (
    <div className="dtr-modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className="dtr-detail-drawer" role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()}>
        <header>
          <div>
            <span>
              DTR detail · {monthLabel} · Version {row.certification.version_number}
              {row.certification.is_amended ? " (Amended)" : ""}
            </span>
            <h2>{row.full_name}</h2>
            <p>{row.employee_number} · {row.department?.name || "No assigned office"}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close DTR detail"><X size={19} /></button>
        </header>

        <div className="dtr-detail-metrics">
          <div><small>Work time</small><strong>{formatMinutes(row.total_work_minutes)}</strong></div>
          <div><small>Late total</small><strong>{formatDuration(row.late_minutes)}</strong></div>
          <div><small>Completion</small><strong>{row.completion_percent}%</strong></div>
        </div>

        {row.certification.status === "Returned" && (
          <div className="dtr-return-notice">
            <AlertCircle size={19} />
            <div>
              <strong>Correction required</strong>
              <p>{row.certification.return_reason || "This DTR was returned for attendance correction."}</p>
            </div>
          </div>
        )}

        {row.certification.status === "Reopened" && (
          <div className="dtr-reopen-notice">
            <RefreshCw size={19} />
            <div>
              <strong>Amendment in progress · Version {row.certification.version_number}</strong>
              <p>
                Only the approved dates may be corrected. Submit the amended DTR after the
                changes have been independently verified.
              </p>
            </div>
          </div>
        )}

        {pendingReopen && (
          <div className="dtr-reopen-request">
            <div>
              <span>Pending reopening request</span>
              <strong>{pendingReopen.reason}</strong>
              <small>
                {pendingReopen.requested_by} · {pendingReopen.affected_dates.length} affected
                date{pendingReopen.affected_dates.length === 1 ? "" : "s"}
              </small>
            </div>
            {canApproveReopen && (
              <div className="dtr-reopen-review-actions">
                <button
                  type="button"
                  className="reject-reopen"
                  disabled={busy}
                  onClick={() => setRejectionRequestId(pendingReopen.id)}
                >
                  Reject
                </button>
                <button
                  type="button"
                  className="approve-reopen"
                  disabled={busy}
                  onClick={() => onReviewReopen(pendingReopen.id, "Approved")}
                >
                  Approve reopening
                </button>
              </div>
            )}
          </div>
        )}

        {!row.is_ready && (
          <div className="dtr-correction-summary">
            <div>
              <strong>{problemDays.length} date{problemDays.length === 1 ? "" : "s"} need attention</strong>
              <span>
                {row.issues.missing} missing · {row.issues.incomplete} incomplete · {row.issues.unverified} unverified
              </span>
            </div>
            <div className="dtr-correction-actions">
              {!!verifiableDays.length && canVerify && (
                <button
                  type="button"
                  disabled={exceptionBusy}
                  onClick={() => onVerifyBulk(verifiableDays.map((day) => day.attendance_id))}
                >
                  <CheckCheck size={14} /> Verify complete ({verifiableDays.length})
                </button>
              )}
              {problemDays[0] && canCorrect && (
                <button type="button" disabled={exceptionBusy} onClick={() => setCorrectionDay(problemDays[0])}>
                  Resolve first issue <PencilLine size={14} />
                </button>
              )}
            </div>
          </div>
        )}

        <div className="dtr-detail-list">
          <div className="dtr-detail-list-head">
            <strong>Daily records</strong>
            <span>{row.expected_days} expected duty days</span>
          </div>
          {row.daily_records.length ? row.daily_records.map((day) => (
            <article
              key={day.date}
              className={problemDays.some((problem) => problem.date === day.date) ? "needs-correction" : ""}
            >
              <time><strong>{day.day_number}</strong><small>{day.day}</small></time>
              <div className="dtr-day-times">
                <span>AM {formatTime(day.morning_time_in)} – {formatTime(day.morning_time_out)}</span>
                <span>PM {formatTime(day.afternoon_time_in)} – {formatTime(day.afternoon_time_out)}</span>
              </div>
              <div className="dtr-day-meta">
                <span className={`dtr-day-status ${statusClass(day.status)}`}>{day.status}</span>
                {day.is_authorized_duty_day && <small className="authorized">Authorized Duty Day</small>}
                {day.holiday && <small className="holiday-name">{day.holiday}</small>}
                {!!day.late_minutes && <small>Late {formatDuration(day.late_minutes)}</small>}
              </div>
              <span className={`dtr-verification ${day.is_verified ? "verified" : ""}`}>
                {day.is_verified ? <BadgeCheck size={13} /> : <TimerReset size={13} />}
                {day.is_verified ? "Verified" : "Unverified"}
              </span>
              {verifiableDays.some((candidate) => candidate.date === day.date) && canVerify ? (
                <button
                  type="button"
                  className="dtr-review-day verify"
                  disabled={exceptionBusy}
                  onClick={() => onVerifyBulk([day.attendance_id])}
                  aria-label={`Verify attendance for ${day.date}`}
                >
                  Verify <CheckCheck size={12} />
                </button>
              ) : problemDays.some((problem) => problem.date === day.date) && (
                <button
                  type="button"
                  className="dtr-review-day"
                  onClick={() => canCorrect ? setCorrectionDay(day) : onReview(day.date)}
                  aria-label={`${canCorrect ? "Resolve" : "Review"} attendance for ${day.date}`}
                >
                  {canCorrect ? "Resolve" : "Review"}
                  {canCorrect ? <PencilLine size={12} /> : <ExternalLink size={12} />}
                </button>
              )}
            </article>
          )) : <div className="dtr-no-days">No expected duty days for this month.</div>}
        </div>

        {!!latestHistory.length && (
          <div className="dtr-status-history">
            <strong>Approval history</strong>
            {latestHistory.map((entry) => (
              <div key={entry.id}>
                <i className={statusClass(entry.to_status)}></i>
                <span>
                  <b>{entry.from_status} → {entry.to_status}</b>
                  <small>{entry.changed_by}{entry.remarks ? ` · ${entry.remarks}` : ""}</small>
                </span>
                <time>{new Date(entry.changed_at).toLocaleString("en-PH", {
                  month: "short",
                  day: "numeric",
                  hour: "numeric",
                  minute: "2-digit",
                })}</time>
              </div>
            ))}
          </div>
        )}

        {!!archivedVersions.length && (
          <div className="dtr-version-history">
            <strong><Archive size={13} /> Immutable certified versions</strong>
            {archivedVersions.map((version) => (
              <div key={version.id}>
                <span>Version {version.version_number}</span>
                <small>
                  Certified {new Date(version.certified_at).toLocaleDateString("en-PH")}
                  {" · "}Archived by {version.archived_by}
                </small>
                <code title="Certified snapshot fingerprint">{version.hash_prefix}…</code>
              </div>
            ))}
          </div>
        )}

        {showReturnForm && (
          <div className="dtr-return-form">
            <label htmlFor="dtr-return-reason">Reason for returning this DTR</label>
            <textarea
              id="dtr-return-reason"
              value={returnReason}
              onChange={(event) => setReturnReason(event.target.value)}
              maxLength={255}
              placeholder="Identify the dates or records that require correction..."
              autoFocus
            />
            <div>
              <button type="button" onClick={() => setShowReturnForm(false)}>Cancel</button>
              <button
                type="button"
                className="confirm-return"
                disabled={busy || !returnReason.trim()}
                onClick={async () => {
                  const updated = await onStatus("Returned", returnReason.trim());
                  if (updated) {
                    setShowReturnForm(false);
                    setReturnReason("");
                  }
                }}
              >
                Return for correction
              </button>
            </div>
          </div>
        )}

        {showReopenForm && (
          <div className="dtr-reopen-form">
            <header>
              <div>
                <strong>Request certified DTR reopening</strong>
                <span>
                  Signed Version {row.certification.version_number} remains unchanged until approval.
                </span>
              </div>
              <button
                type="button"
                onClick={() => setShowReopenForm(false)}
                aria-label="Close reopening form"
              >
                <X size={15} />
              </button>
            </header>
            <label htmlFor="dtr-reopen-reason">Reason for amendment</label>
            <textarea
              id="dtr-reopen-reason"
              value={reopenReason}
              onChange={(event) => setReopenReason(event.target.value)}
              maxLength={1000}
              placeholder="Explain what was certified incorrectly and why it must be corrected..."
              autoFocus
            />
            <fieldset>
              <legend>Affected dates ({affectedDates.length} selected)</legend>
              <div>
                {row.daily_records.map((day) => (
                  <label key={day.date}>
                    <input
                      type="checkbox"
                      checked={affectedDates.includes(day.date)}
                      onChange={() => toggleAffectedDate(day.date)}
                    />
                    <span>{day.day} {day.day_number}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <footer>
              <button type="button" onClick={() => setShowReopenForm(false)}>Cancel</button>
              <button
                type="button"
                className="submit-reopen"
                disabled={busy || reopenReason.trim().length < 10 || !affectedDates.length}
                onClick={async () => {
                  const submitted = await onRequestReopen(reopenReason.trim(), affectedDates);
                  if (submitted) {
                    setShowReopenForm(false);
                    setReopenReason("");
                    setAffectedDates([]);
                  }
                }}
              >
                Submit for approval
              </button>
            </footer>
          </div>
        )}

        {rejectionRequestId && (
          <div className="dtr-return-form">
            <label htmlFor="dtr-reopen-rejection">Reason for rejecting the reopening request</label>
            <textarea
              id="dtr-reopen-rejection"
              value={rejectionReason}
              onChange={(event) => setRejectionReason(event.target.value)}
              maxLength={1000}
              placeholder="Explain why this DTR should remain certified..."
              autoFocus
            />
            <div>
              <button type="button" onClick={() => setRejectionRequestId(null)}>Cancel</button>
              <button
                type="button"
                className="confirm-return"
                disabled={busy || !rejectionReason.trim()}
                onClick={async () => {
                  const reviewed = await onReviewReopen(
                    rejectionRequestId,
                    "Rejected",
                    rejectionReason.trim(),
                  );
                  if (reviewed) {
                    setRejectionRequestId(null);
                    setRejectionReason("");
                  }
                }}
              >
                Reject request
              </button>
            </div>
          </div>
        )}

        {correctionDay && (
          <AttendanceCorrectionModal
            key={correctionDay.date}
            record={{
              ...correctionDay,
              full_name: row.full_name,
              display_status: correctionDay.status,
            }}
            date={correctionDay.date}
            busy={exceptionBusy}
            onClose={() => setCorrectionDay(null)}
            onSave={saveInlineCorrection}
          />
        )}

        <footer>
          <div>
            <small>Current status</small>
            <strong className={`dtr-cert-status ${statusClass(row.certification.status)}`}>
              {row.certification.status}
            </strong>
          </div>
          <div className="dtr-detail-actions">
            {["Draft", "Returned", "Reopened"].includes(row.certification.status) && (
              <button
                type="button"
                className="submit"
                disabled={busy || !row.is_ready}
                title={!row.is_ready ? "Resolve missing, incomplete, and unverified records first." : ""}
                onClick={() => onStatus("Submitted")}
              >
                <FileCheck2 size={15} />
                {row.certification.status === "Draft" ? "Submit DTR" : "Submit amended DTR"}
              </button>
            )}
            {row.certification.status === "Submitted" && canCertify && (
              <>
                <button
                  type="button"
                  className="return"
                  disabled={busy}
                  onClick={() => setShowReturnForm(true)}
                >
                  <AlertCircle size={15} /> Return
                </button>
                <button type="button" className="certify" disabled={busy} onClick={() => onStatus("Certified")}>
                  <ShieldCheck size={15} /> Certify
                </button>
              </>
            )}
            {row.certification.status === "Certified" && canRequestReopen && !pendingReopen && (
              <button
                type="button"
                className="reopen"
                disabled={busy}
                onClick={() => setShowReopenForm(true)}
              >
                <RefreshCw size={15} /> Request reopening
              </button>
            )}
          </div>
        </footer>
      </section>
    </div>
  );
}
