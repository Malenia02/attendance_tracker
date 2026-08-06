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
        .then(setData)
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
  }, [queue, search, page]);

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
              <Link to={item.action_url} className="action-center-open">
                {item.action_label}<ArrowRight size={15} />
              </Link>
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
    </section>
  );
}
