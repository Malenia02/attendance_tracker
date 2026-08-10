import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  ArrowRight,
  BadgeCheck,
  CalendarClock,
  ClipboardCheck,
  ClockAlert,
  FileCheck2,
  FileWarning,
  IdCard,
  Inbox,
  RefreshCw,
  Search,
  ShieldCheck,
  UserRoundCog,
  X,
} from "lucide-react";
import { Link, useSearchParams } from "react-router";
import AttendanceCorrectionRequestModal from "../components/attendance/AttendanceCorrectionRequestModal";
import Pagination from "../components/common/Pagination";
import { apiFetch, getStoredUser } from "../lib/auth";

const QUEUE_ICONS = {
  attendance_verification: ClipboardCheck,
  missing_time_outs: ClockAlert,
  correction_requests: FileWarning,
  leave_requests: CalendarClock,
  returned_dtrs: FileCheck2,
  dtr_cutoffs: CalendarClock,
  expiring_qr_cards: IdCard,
  workforce_gaps: UserRoundCog,
};

const ROLE_QUEUES = {
  Administrator: Object.keys(QUEUE_ICONS),
  HR: Object.keys(QUEUE_ICONS),
  Supervisor: [
    "attendance_verification",
    "missing_time_outs",
    "leave_requests",
    "returned_dtrs",
    "dtr_cutoffs",
  ],
};

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const validationMessage = Object.values(payload.errors || {}).flat()[0];
    throw new Error(validationMessage || payload.message || "The Action Center could not be loaded.");
  }

  return payload;
}

function dateLabel(value) {
  if (!value) return "No date";

  return new Date(`${value}T00:00:00`).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function statusClass(status = "") {
  const value = status.toLowerCase();
  if (value.includes("pending") || value.includes("expire")) return "warning";
  if (value.includes("incomplete") || value.includes("returned") || value.includes("required")) return "danger";
  if (value.includes("present") || value.includes("approved")) return "success";
  return "neutral";
}

export default function ActionCenter() {
  const currentUser = getStoredUser();
  const allowedQueues = ROLE_QUEUES[currentUser?.user_role] || [];
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedQueue = searchParams.get("queue");
  const initialQueue = allowedQueues.includes(requestedQueue)
    ? requestedQueue
    : allowedQueues[0] || "attendance_verification";
  const [queue, setQueue] = useState(initialQueue);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [correctionLoadingId, setCorrectionLoadingId] = useState(null);
  const [correctionModal, setCorrectionModal] = useState(null);
  const [correctionBusy, setCorrectionBusy] = useState(false);
  const [data, setData] = useState({
    scope: "",
    summary: [],
    data: [],
    meta: { pagination: null },
  });

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        queue,
        page: String(page),
        per_page: "15",
      });
      if (search.trim()) params.set("search", search.trim());

      setLoading(true);
      setError("");
      apiFetch(`/action-center?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then((payload) => {
          setData(payload);
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
    }, search ? 250 : 0);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [queue, search, page, refreshKey]);

  const selectedQueue = useMemo(
    () => data.summary.find((item) => item.key === queue),
    [data.summary, queue],
  );
  const totalPending = data.summary.reduce((total, item) => total + Number(item.count || 0), 0);

  function selectQueue(nextQueue) {
    setQueue(nextQueue);
    setPage(1);
    setSearch("");
    setSearchParams({ queue: nextQueue }, { replace: true });
  }

  async function openCorrectionReview(item) {
    setCorrectionModal({
      request: {
        request_id: item.request_id,
        attendance_id: item.attendance_id,
        personnel_id: item.personnel_id,
        attendance_date: item.date,
        missing_field: item.missing_field,
        missing_label: item.missing_label,
        proposed_time: item.proposed_time,
        reason: item.reason,
        status: item.status,
        submitted_by: item.submitted_by,
        personnel: {
          employee_number: item.employee_number,
          full_name: item.full_name,
          department_code: item.department_code,
        },
        attendance: item.attendance || null,
      },
      attendanceUrl: item.action_url,
    });
    setCorrectionLoadingId(item.request_id);
    setError("");

    try {
      const payload = await apiFetch(
        `/attendance/correction-requests/${item.request_id}`,
      ).then(readResponse);

      setCorrectionModal({
        request: payload.data,
        attendanceUrl: item.action_url,
      });
    } catch (requestError) {
      setError(`${requestError.message} The review form is still open using the queue details.`);
    } finally {
      setCorrectionLoadingId(null);
    }
  }

  async function reviewCorrectionRequest(values) {
    if (!correctionModal?.request) return;

    setCorrectionBusy(true);
    setError("");

    try {
      const payload = await apiFetch(
        `/attendance/correction-requests/${correctionModal.request.request_id}/review`,
        {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(values),
        },
      ).then(readResponse);

      setCorrectionModal(null);
      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 5500);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setCorrectionBusy(false);
    }
  }

  return (
    <section className="action-center-page">
      <header className="action-center-hero">
        <div>
          <span><ShieldCheck size={15} /> Secure operations workspace</span>
          <h1>Unified Action Center</h1>
          <p>Review attendance, requests, DTR returns, credential expiry, and workforce setup from one role-scoped queue.</p>
        </div>
        <div className="action-center-hero-total">
          <span><Inbox size={18} /> Open actions</span>
          <strong>{loading && !data.summary.length ? "—" : totalPending.toLocaleString()}</strong>
          <small>{data.scope || "Loading access scope…"}</small>
        </div>
      </header>

      {error && <div className="users-notice error"><X size={18} />{error}</div>}
      {notice && <div className="users-notice success"><BadgeCheck size={18} />{notice}</div>}

      <div className="action-queue-grid" aria-label="Action Center queues">
        {data.summary.map((item) => {
          const Icon = QUEUE_ICONS[item.key] || AlertTriangle;
          return (
            <button
              type="button"
              key={item.key}
              className={queue === item.key ? "active" : ""}
              onClick={() => selectQueue(item.key)}
            >
              <span><Icon size={20} /></span>
              <div><strong>{Number(item.count).toLocaleString()}</strong><small>{item.label}</small></div>
              <ArrowRight size={15} />
            </button>
          );
        })}
      </div>

      <div className="panel action-center-panel">
        <div className="action-center-toolbar">
          <div>
            <span>Current queue</span>
            <h2>{selectedQueue?.label || "Loading actions…"}</h2>
            <p>{selectedQueue?.description}</p>
          </div>
          <label>
            <Search size={17} />
            <input
              value={search}
              onChange={(event) => { setSearch(event.target.value); setPage(1); }}
              placeholder="Search personnel or employee number"
              maxLength="100"
            />
          </label>
        </div>

        <div className="action-center-list" aria-live="polite">
          {loading ? (
            <div className="action-center-state">
              <RefreshCw className="spin" size={24} />
              <strong>Checking secure queues…</strong>
              <span>Only records inside your authorized scope are being loaded.</span>
            </div>
          ) : data.data.length ? data.data.map((item) => (
            <article key={`${queue}-${item.id}`} className="action-center-row">
              <div className="action-personnel-avatar">
                {(item.full_name || "?").split(" ").map((part) => part[0]).slice(0, 2).join("")}
              </div>
              <div className="action-center-record">
                <div>
                  <strong>{item.full_name}</strong>
                  <span>{item.employee_number || "No employee number"} · {item.department_code}</span>
                </div>
                <p>{item.detail}</p>
              </div>
              <div className="action-center-meta">
                <span className={`action-status ${statusClass(item.status)}`}>{item.status}</span>
                <small>{dateLabel(item.date)}</small>
              </div>
              {item.action_type === "review_correction" ? (
                <button
                  type="button"
                  className="action-center-open"
                  disabled={correctionLoadingId !== null}
                  onClick={() => openCorrectionReview(item)}
                >
                  {correctionLoadingId === item.request_id ? "Loading review…" : item.action_label}
                  {correctionLoadingId === item.request_id
                    ? <RefreshCw className="spin" size={15} />
                    : <ArrowRight size={15} />}
                </button>
              ) : (
                <Link to={item.action_url} className="action-center-open">
                  {item.action_label}<ArrowRight size={15} />
                </Link>
              )}
            </article>
          )) : (
            <div className="action-center-state complete">
              <BadgeCheck size={28} />
              <strong>{search ? "No matching actions" : "This queue is clear"}</strong>
              <span>{search ? "Try a different personnel name or employee number." : "There are no outstanding records in this queue."}</span>
            </div>
          )}
        </div>

        <Pagination
          pagination={data.meta?.pagination}
          onPageChange={setPage}
          disabled={loading}
          itemLabel="actions"
        />
      </div>

      {correctionModal && (
        <AttendanceCorrectionRequestModal
          mode="review"
          request={correctionModal.request}
          contextUrl={correctionModal.attendanceUrl}
          busy={correctionBusy}
          onClose={() => {
            if (!correctionBusy) setCorrectionModal(null);
          }}
          onSubmit={reviewCorrectionRequest}
        />
      )}
    </section>
  );
}
