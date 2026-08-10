   import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router";
import {
  AlertTriangle,
  BadgeCheck,
  CalendarDays,
  CheckCircle2,
  Clock3,
  Coffee,
  Fingerprint,
  LogIn,
  LogOut,
  PencilLine,
  Search,
  ShieldCheck,
  Sparkles,
  TimerReset,
  UserCheck,
  Users,
  X,
} from "lucide-react";
import { apiFetch, getStoredUser } from "../lib/auth";
import Pagination from "../components/common/Pagination";
import { formatDuration } from "../lib/duration";
import AttendanceCorrectionModal from "../components/attendance/AttendanceCorrectionModal";
import AttendanceCorrectionRequestModal from "../components/attendance/AttendanceCorrectionRequestModal";

function localDateKey(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function formatTime(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", {
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  }).format(new Date(value));
}

function formatMinutes(minutes) {
  if (!minutes) return "0h 00m";
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m`;
}

function statusClass(status) {
  return status.toLowerCase().replaceAll(" ", "-");
}

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.message || "The request could not be completed.");
  }

  return payload;
}

export default function Attendance() {
  const today = useMemo(() => localDateKey(), []);
  const [searchParams] = useSearchParams();
  const requestedDate = searchParams.get("date");
  const requestedPersonnelId = searchParams.get("personnel");
  const requestedSearch = searchParams.get("search") || "";
  const currentUser = getStoredUser();
  const canVerify = ["Administrator", "HR", "Supervisor"].includes(currentUser?.user_role);
  const canCorrect = ["Administrator", "HR"].includes(currentUser?.user_role);

  const [now, setNow] = useState(new Date());
  const [selectedDate, setSelectedDate] = useState(
    requestedDate && /^\d{4}-\d{2}-\d{2}$/.test(requestedDate) && requestedDate <= today
      ? requestedDate
      : today,
  );
  const [records, setRecords] = useState([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(null);
  const [recentLogs, setRecentLogs] = useState([]);
  const [summary, setSummary] = useState({
    total: 0,
    timed_in: 0,
    completed: 0,
    half_day: 0,
    late: 0,
    incomplete: 0,
    missing_time_out: 0,
    not_started: 0,
  });
  const [options, setOptions] = useState({
    personnel: [],
    status_filters: [],
    current_user_personnel_id: null,
    can_manage_others: false,
    timezone: "Asia/Manila",
  });
  const [selectedPersonnelId, setSelectedPersonnelId] = useState("");
  const [search, setSearch] = useState(requestedSearch);
  const [statusFilter, setStatusFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [actionBusy, setActionBusy] = useState(false);
  const [verifyingId, setVerifyingId] = useState(null);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [holiday, setHoliday] = useState(null);
  const [editingRecord, setEditingRecord] = useState(null);
  const [correctionBusy, setCorrectionBusy] = useState(false);
  const [correctionDismissed, setCorrectionDismissed] = useState(false);
  const [correctionRequests, setCorrectionRequests] = useState([]);
  const [requestModal, setRequestModal] = useState(null);
  const [requestBusy, setRequestBusy] = useState(false);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch("/attendance/options", { signal: controller.signal })
      .then(readResponse)
      .then((payload) => {
        setOptions(payload);
        setSelectedPersonnelId((current) => {
          if (current) return current;
          const requested = payload.personnel.find(
            (person) => String(person.personnel_id) === String(requestedPersonnelId),
          );
          if (requested) return String(requested.personnel_id);
          const preferred = payload.current_user_personnel_id || payload.personnel[0]?.personnel_id;
          return preferred ? String(preferred) : "";
        });
      })
      .catch((error) => {
        if (error.name !== "AbortError") setPageError(error.message);
      });

    return () => controller.abort();
  }, [requestedPersonnelId]);

  useEffect(() => {
    const controller = new AbortController();

    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        date: selectedDate,
        page: String(page),
        per_page: "25",
      });
      if (search.trim()) params.set("search", search.trim());
      if (statusFilter) params.set("status", statusFilter);

      apiFetch(`/attendance?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then((payload) => {
          setRecords(payload.data);
          setRecentLogs(payload.recent_logs);
          setSummary(payload.summary);
          setHoliday(payload.holiday);
          setPagination(payload.meta?.pagination || null);
          if (!payload.data.length && page > 1) setPage((current) => Math.max(1, current - 1));
        })
        .catch((error) => {
          if (error.name !== "AbortError") setPageError(error.message);
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 250);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [selectedDate, search, statusFilter, page, refreshKey]);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch(`/attendance/correction-requests?date=${selectedDate}`, { signal: controller.signal })
      .then(readResponse)
      .then((payload) => setCorrectionRequests(payload.data))
      .catch((error) => {
        if (error.name !== "AbortError") setPageError(error.message);
      });

    return () => controller.abort();
  }, [selectedDate, refreshKey]);

  const filteredRecords = useMemo(() => {
    const query = search.trim().toLowerCase();

    return records.filter((record) => {
      const matchesSearch = !query
        || record.full_name.toLowerCase().includes(query)
        || record.employee_number.toLowerCase().includes(query)
        || record.department?.department_code.toLowerCase().includes(query);
      const matchesStatus = !statusFilter
        || (statusFilter === "Late" ? record.is_late : record.display_status === statusFilter);
      return matchesSearch && matchesStatus;
    });
  }, [records, search, statusFilter]);
  const missingTimeOutRecords = useMemo(
    () => records.filter((record) => record.has_missing_time_out),
    [records],
  );

  const selectedRecord = records.find(
    (record) => String(record.personnel_id) === String(selectedPersonnelId),
  );
  const selectedPendingRequest = correctionRequests.find(
    (request) => request.attendance_id === selectedRecord?.attendance_id
      && request.status === "Pending",
  );
  const selectedLatestRequest = correctionRequests.find(
    (request) => request.attendance_id === selectedRecord?.attendance_id,
  );
  const currentPersonnelId = options.current_user_personnel_id;
  const hasLinkedPersonnel = currentPersonnelId !== null
    && currentPersonnelId !== undefined
    && currentPersonnelId !== "";
  const selectedRecordIsOwn = hasLinkedPersonnel
    && selectedRecord?.personnel_id !== null
    && selectedRecord?.personnel_id !== undefined
    && String(currentPersonnelId) === String(selectedRecord.personnel_id);
  const timeActionRestricted = !hasLinkedPersonnel || !selectedRecordIsOwn;
  const isToday = selectedDate === today;
  const nextAction = selectedRecord?.next_action_label;
  const completed = Boolean(selectedRecord?.attendance_complete);
  const dayClosed = Boolean(selectedRecord?.day_closed);
  const isHalfDay = selectedRecord?.display_status === "Half Day";
  const requestedCorrectionRecord = !correctionDismissed
    && searchParams.get("correction") === "1"
    && requestedPersonnelId
    && canCorrect
    ? records.find((record) => String(record.personnel_id) === String(requestedPersonnelId))
    : null;
  const correctionRecord = editingRecord || requestedCorrectionRecord;

  const timeSteps = [
    { key: "morning_time_in", label: "Morning in", icon: LogIn },
    { key: "morning_time_out", label: "Lunch out", icon: Coffee },
    { key: "afternoon_time_in", label: "Afternoon in", icon: LogIn },
    { key: "afternoon_time_out", label: "Time out", icon: LogOut },
  ];

  async function recordTime() {
    if (!selectedPersonnelId || !isToday || dayClosed || !selectedRecord?.next_action) return;

    if (!selectedRecordIsOwn) {
      setPageError(
        hasLinkedPersonnel
          ? "You may only record attendance for your own personnel account. Use QR Attendance for another personnel member."
          : "Your system account is not linked to a personnel profile. Link it in System Users, or use the authorized QR Attendance kiosk.",
      );
      return;
    }

    setActionBusy(true);
    setPageError("");

    try {
      const response = await apiFetch("/attendance/time-log", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          personnel_id: Number(selectedPersonnelId),
          device_identifier: navigator.userAgent,
        }),
      });
      const payload = await readResponse(response);

      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4000);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setActionBusy(false);
    }
  }

  async function verifyAttendance(record) {
    if (!record.attendance_id) return;
    setVerifyingId(record.attendance_id);
    setPageError("");

    try {
      const payload = await apiFetch(`/attendance/${record.attendance_id}/verify`, {
        method: "PATCH",
      }).then(readResponse);
      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setVerifyingId(null);
    }
  }

  async function saveCorrection(values) {
    setCorrectionBusy(true);
    setPageError("");

    try {
      const payload = await apiFetch("/attendance/correction", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          personnel_id: correctionRecord.personnel_id,
          attendance_date: selectedDate,
          ...values,
        }),
      }).then(readResponse);

      setNotice(payload.message);
      setEditingRecord(null);
      setCorrectionDismissed(true);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 4500);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setCorrectionBusy(false);
    }
  }

  async function submitCorrectionRequest(values) {
    setRequestBusy(true);
    setPageError("");

    try {
      const payload = await apiFetch("/attendance/correction-requests", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          attendance_date: selectedDate,
          ...values,
        }),
      }).then(readResponse);

      setNotice(payload.message);
      setRequestModal(null);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 5000);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setRequestBusy(false);
    }
  }

  async function reviewCorrectionRequest(values) {
    if (!requestModal?.request) return;
    setRequestBusy(true);
    setPageError("");

    try {
      const payload = await apiFetch(
        `/attendance/correction-requests/${requestModal.request.request_id}/review`,
        {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(values),
        },
      ).then(readResponse);

      setNotice(payload.message);
      setRequestModal(null);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 5500);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setRequestBusy(false);
    }
  }

  const summaryCards = [
    { label: "Active Personnel", value: summary.total, icon: Users, tone: "blue" },
    { label: "Timed In", value: summary.timed_in, icon: UserCheck, tone: "green" },
    { label: "Completed", value: summary.completed, icon: CheckCircle2, tone: "violet" },
    { label: "Half Day", value: summary.half_day, icon: Coffee, tone: "cyan" },
    { label: "Late", value: summary.late, icon: Clock3, tone: "red" },
    { label: "Incomplete", value: summary.incomplete, icon: TimerReset, tone: "orange" },
    { label: "Missing Time-out", value: summary.missing_time_out, icon: AlertTriangle, tone: "orange" },
  ];

  return (
    <section className="attendance-page">
      <div className="attendance-hero">
        <div className="attendance-hero-copy">
          <span className="attendance-kicker"><Sparkles size={14} /> Live attendance</span>
          <h1>Time In & Time Out</h1>
          <p>Fast, secure daily attendance for DILG GIP participants and employees.</p>
        </div>
        <div className="live-clock">
          <span className="live-indicator"><i></i>Live</span>
          <strong>{now.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}</strong>
          <small>{now.toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric" })}</small>
        </div>
        <div className="attendance-wave"></div>
      </div>

      {notice && <div className="users-notice success attendance-notice"><BadgeCheck size={18} />{notice}</div>}
      {pageError && <div className="users-notice error attendance-notice"><X size={18} />{pageError}</div>}
      {holiday && (
        <div className="attendance-holiday-banner">
          <CalendarDays size={18} />
          <div><strong>{holiday.holiday_name}</strong><span>{holiday.holiday_type}</span></div>
        </div>
      )}

      <div className="attendance-summary-grid">
        {summaryCards.map(({ label, value, icon: Icon, tone }, index) => (
          <article key={label} style={{ "--delay": `${index * 70}ms` }}>
            <span className={`attendance-summary-icon ${tone}`}><Icon size={21} /></span>
            <div><strong>{value}</strong><span>{label}</span></div>
            <i className={tone}></i>
          </article>
        ))}
      </div>

      {!!missingTimeOutRecords.length && (
        <section className="attendance-exception-panel" aria-labelledby="missing-time-out-title">
          <header>
            <span><AlertTriangle size={20} /></span>
            <div>
              <strong id="missing-time-out-title">Missing time-out queue</strong>
              <small>
                These entries remain incomplete and cannot be verified or included in a certified DTR.
              </small>
            </div>
            <b>{missingTimeOutRecords.length}</b>
          </header>
          <div className="attendance-exception-list">
            {missingTimeOutRecords.map((record) => {
              const pendingRequest = correctionRequests.find(
                (request) => request.attendance_id === record.attendance_id
                  && request.status === "Pending",
              );
              const latestRequest = correctionRequests.find(
                (request) => request.attendance_id === record.attendance_id,
              );
              const isOwnRecord = Number(currentPersonnelId) === Number(record.personnel_id);

              return (
                <article key={record.personnel_id}>
                  <span className="attendance-exception-avatar">
                    {record.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}
                  </span>
                  <div>
                    <strong>{record.full_name}</strong>
                    <small>
                      {record.missing_time_out_entries.map((entry) => entry.label).join(" and ")}
                      {" · "}{record.employee_number}
                    </small>
                    {pendingRequest && <em>Employee explanation submitted · Pending HR review</em>}
                    {!pendingRequest && latestRequest?.status === "Rejected" && (
                      <em className="rejected">
                        Previous request rejected: {latestRequest.review_remarks}
                      </em>
                    )}
                  </div>
                  {pendingRequest && canCorrect ? (
                    <button
                      type="button"
                      onClick={() => setRequestModal({
                        mode: "review",
                        record,
                        request: pendingRequest,
                      })}
                    >
                      <ShieldCheck size={14} />Review request
                    </button>
                  ) : pendingRequest ? (
                    <em>Pending HR review</em>
                  ) : isOwnRecord ? (
                    <button
                      type="button"
                      onClick={() => setRequestModal({ mode: "submit", record })}
                    >
                      <Clock3 size={14} />Submit reason
                    </button>
                  ) : canCorrect ? (
                    <button type="button" onClick={() => setEditingRecord(record)}>
                      <PencilLine size={14} />HR override
                    </button>
                  ) : (
                    <em>Awaiting employee explanation</em>
                  )}
                </article>
              );
            })}
          </div>
          <footer>
            <ShieldCheck size={15} />
            The system never guesses an exit time. HR must enter the documented time and reason.
          </footer>
        </section>
      )}

      <div className="attendance-workspace">
        <div className="attendance-clock-card">
          <div className="attendance-card-heading">
            <div>
              <span>Quick attendance</span>
              <h2>Record a time entry</h2>
            </div>
            <Fingerprint size={27} />
          </div>

          <div className="attendance-personnel-select">
            <label htmlFor="attendance-personnel">Personnel</label>
            <select
              id="attendance-personnel"
              value={selectedPersonnelId}
              onChange={(event) => setSelectedPersonnelId(event.target.value)}
              disabled={!options.can_manage_others}
            >
              {options.personnel.map((person) => (
                <option key={person.personnel_id} value={person.personnel_id}>
                  {person.full_name} — {person.employee_number}
                </option>
              ))}
            </select>
          </div>

          {selectedRecord ? (
            <>
              <div className="selected-personnel-info">
                <span>{selectedRecord.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span>
                <div>
                  <strong>{selectedRecord.full_name}</strong>
                  <small>{selectedRecord.employee_number} · {selectedRecord.personnel_type}</small>
                </div>
                <em className={`attendance-status ${statusClass(selectedRecord.display_status)}`}>
                  {selectedRecord.display_status}
                </em>
                {selectedRecord.is_late && (
                  <em className="late-flag">Late · {formatDuration(selectedRecord.late_minutes)}</em>
                )}
              </div>

              {selectedRecord.has_missing_time_out && (
                <div className="attendance-missing-timeout-alert">
                  <AlertTriangle size={18} />
                  <div>
                    <strong>Missing time-out detected</strong>
                    <span>
                      {selectedRecord.missing_time_out_entries.map((entry) => entry.label).join(" and ")}
                      {" "}must be corrected before this entry can be verified.
                    </span>
                    {selectedPendingRequest && (
                      <em>Your explanation has been submitted and is pending HR review.</em>
                    )}
                    {!selectedPendingRequest && selectedLatestRequest?.status === "Rejected" && (
                      <em className="rejected">
                        Previous request rejected: {selectedLatestRequest.review_remarks}
                      </em>
                    )}
                  </div>
                  {selectedPendingRequest && canCorrect ? (
                    <button
                      type="button"
                      onClick={() => setRequestModal({
                        mode: "review",
                        record: selectedRecord,
                        request: selectedPendingRequest,
                      })}
                    >
                      Review request
                    </button>
                  ) : selectedPendingRequest ? null : selectedRecordIsOwn ? (
                    <button
                      type="button"
                      onClick={() => setRequestModal({ mode: "submit", record: selectedRecord })}
                    >
                      Submit reason
                    </button>
                  ) : canCorrect ? (
                    <button type="button" onClick={() => setEditingRecord(selectedRecord)}>
                      HR override
                    </button>
                  ) : null}
                </div>
              )}

              <div className="time-stepper">
                {timeSteps.map(({ key, label, icon: Icon }, index) => {
                  const value = selectedRecord[key];
                  return (
                    <div className={`time-step ${value ? "done" : ""}`} key={key}>
                      <span><Icon size={16} /></span>
                      <div><small>{label}</small><strong>{formatTime(value)}</strong></div>
                      {index < timeSteps.length - 1 && <i></i>}
                    </div>
                  );
                })}
              </div>

              <div className="work-progress">
                <div>
                  <span>Daily progress</span>
                  <strong>{formatMinutes(selectedRecord.total_work_minutes)}</strong>
                </div>
                <div className="work-progress-track">
                  <i
                    style={{
                      width: `${Math.min(
                        100,
                        (selectedRecord.total_work_minutes
                          / (selectedRecord.schedule?.required_minutes_per_day || 600)) * 100,
                      )}%`,
                    }}
                  ></i>
                </div>
              </div>

              <button
                type="button"
                className={`time-action-button ${dayClosed ? "complete" : ""} ${isHalfDay ? "half-day" : ""} ${timeActionRestricted ? "restricted" : ""}`}
                onClick={recordTime}
                disabled={
                  actionBusy
                  || dayClosed
                  || !isToday
                  || !selectedRecord.next_action
                  || timeActionRestricted
                }
              >
                <span className="action-rings"><i></i><i></i></span>
                {timeActionRestricted
                  ? <ShieldCheck size={24} />
                  : dayClosed
                    ? <CheckCircle2 size={24} />
                    : <Fingerprint size={25} />}
                <div>
                  <strong>
                    {!hasLinkedPersonnel
                      ? "Personnel profile link required"
                      : !selectedRecordIsOwn
                        ? "Protected personnel attendance"
                        : !isToday
                          ? "View only"
                          : actionBusy
                            ? "Recording…"
                            : isHalfDay
                              ? `${selectedRecord.half_day_period} half day recorded`
                              : completed
                                ? "Attendance complete"
                                : nextAction || "Not available right now"}
                  </strong>
                  <small>
                    {!hasLinkedPersonnel
                      ? "Link this user in System Users, or record through QR Attendance"
                      : !selectedRecordIsOwn
                        ? "Administrators cannot clock in or out on behalf of another person"
                        : !isToday
                          ? "Time entries can only be recorded for today"
                          : isHalfDay
                            ? `Completed ${selectedRecord.half_day_period?.toLowerCase()} attendance session`
                            : completed
                              ? "All required entries are saved"
                              : selectedRecord.next_action
                                ? "Tap to use the current server time"
                                : selectedRecord.action_message}
                  </small>
                </div>
              </button>
            </>
          ) : (
            <div className="attendance-no-personnel">Select a personnel record to continue.</div>
          )}
        </div>

        <aside className="recent-time-card">
          <div className="attendance-card-heading">
            <div><span>Activity stream</span><h2>Recent time logs</h2></div>
            <Clock3 size={22} />
          </div>
          <div className="recent-time-list">
            {recentLogs.length ? recentLogs.map((log, index) => (
              <article key={log.time_log_id} style={{ "--delay": `${index * 45}ms` }}>
                <span className={`recent-log-icon ${log.log_type.includes("Out") ? "out" : "in"}`}>
                  {log.log_type.includes("Out") ? <LogOut size={15} /> : <LogIn size={15} />}
                </span>
                <div><strong>{log.full_name}</strong><small>{log.log_type} · {log.source}</small></div>
                <time>{log.time}</time>
              </article>
            )) : (
              <div className="recent-time-empty"><Clock3 size={25} /><span>No time logs for this date.</span></div>
            )}
          </div>
        </aside>
      </div>

      <div className="panel attendance-records-panel">
        <div className="attendance-records-header">
          <div><span>Daily register</span><h2>Attendance records</h2></div>
          <div className="attendance-records-controls">
            <div className="users-search">
              <Search size={17} />
              <input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Search personnel..." />
            </div>
            <select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}>
              <option value="">All statuses</option>
              {options.status_filters.map((status) => <option key={status}>{status}</option>)}
            </select>
            <input
              type="date"
              value={selectedDate}
              max={today}
              onChange={(event) => {
                setLoading(true);
                setPageError("");
                setSelectedDate(event.target.value);
                setPage(1);
              }}
              aria-label="Attendance date"
            />
          </div>
        </div>

        <div className="users-table-wrap">
          <table className="users-table attendance-table">
            <thead>
              <tr>
                <th>Personnel</th>
                <th>Morning</th>
                <th>Afternoon</th>
                <th>Work time</th>
                <th>Late / Undertime</th>
                <th>Status</th>
                <th>Verification</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="users-empty">Loading attendance records…</td></tr>
              ) : filteredRecords.length === 0 ? (
                <tr><td colSpan="7" className="users-empty">No attendance records match your filters.</td></tr>
              ) : filteredRecords.map((record) => (
                <tr key={record.personnel_id}>
                  <td>
                    <div className="user-identity">
                      <span>{record.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span>
                      <div><strong>{record.full_name}</strong><small>{record.employee_number} · {record.department?.department_code || "No office"}</small></div>
                    </div>
                  </td>
                  <td><TimePair first={record.morning_time_in} second={record.morning_time_out} /></td>
                  <td><TimePair first={record.afternoon_time_in} second={record.afternoon_time_out} /></td>
                  <td><strong className="work-time-value">{formatMinutes(record.total_work_minutes)}</strong></td>
                  <td>
                    <div className="attendance-metrics">
                      <span className={record.late_minutes ? "warning" : ""}>Late {formatDuration(record.late_minutes)}</span>
                      <span className={record.undertime_minutes ? "warning" : ""}>Under {formatDuration(record.undertime_minutes)}</span>
                    </div>
                  </td>
                  <td>
                    <span className={`attendance-status ${statusClass(record.display_status)}`}>
                      {record.display_status}
                    </span>
                    {record.has_missing_time_out && (
                      <small className="missing-timeout-row-flag">
                        {record.missing_time_out_entries.map((entry) => entry.label).join(" and ")}
                      </small>
                    )}
                    {record.is_late && <small className="late-row-flag">Late by {formatDuration(record.late_minutes)}</small>}
                    {record.half_day_period && (
                      <small className="half-day-period">{record.half_day_period} session</small>
                    )}
                  </td>
                  <td>
                    <div className="attendance-row-actions">
                      {record.is_verified ? (
                        <span className="verified-badge"><ShieldCheck size={14} />Verified</span>
                      ) : canVerify && record.attendance_id ? (
                        <button
                          type="button"
                          className="verify-button"
                          onClick={() => verifyAttendance(record)}
                          disabled={verifyingId === record.attendance_id}
                        >
                          <BadgeCheck size={14} />{verifyingId === record.attendance_id ? "Verifying…" : "Verify"}
                        </button>
                      ) : <span className="muted-cell">Unverified</span>}
                      {canCorrect && (
                        <button
                          type="button"
                          className="correct-attendance-button"
                          onClick={() => {
                            setSelectedPersonnelId(String(record.personnel_id));
                            setCorrectionDismissed(true);
                            setEditingRecord(record);
                          }}
                        >
                          <PencilLine size={13} /> {record.has_missing_time_out ? "Resolve" : "Correct"}
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          pagination={pagination}
          onPageChange={setPage}
          disabled={loading}
          itemLabel="active personnel"
        />
      </div>

      {requestModal && (
        <AttendanceCorrectionRequestModal
          mode={requestModal.mode}
          record={requestModal.record}
          request={requestModal.request}
          date={selectedDate}
          busy={requestBusy}
          onClose={() => setRequestModal(null)}
          onSubmit={requestModal.mode === "review"
            ? reviewCorrectionRequest
            : submitCorrectionRequest}
        />
      )}

      {correctionRecord && (
        <AttendanceCorrectionModal
          record={correctionRecord}
          date={selectedDate}
          busy={correctionBusy}
          onClose={() => {
            setEditingRecord(null);
            setCorrectionDismissed(true);
          }}
          onSave={saveCorrection}
        />
      )}
    </section>
  );
}

function TimePair({ first, second }) {
  return (
    <div className="time-pair">
      <span><LogIn size={12} />{formatTime(first)}</span>
      <i></i>
      <span><LogOut size={12} />{formatTime(second)}</span>
    </div>
  );
}
