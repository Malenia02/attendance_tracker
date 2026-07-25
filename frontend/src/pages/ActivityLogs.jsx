import { useEffect, useMemo, useState } from "react";
import {
  Activity,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Eye,
  FileClock,
  Fingerprint,
  MonitorSmartphone,
  RefreshCw,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  UserRound,
  X,
} from "lucide-react";
import { apiFetch } from "../lib/auth";

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || "The activity logs could not be loaded.");
  return payload;
}

function formatDateTime(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
  }).format(new Date(value.replace(" ", "T") + (value.includes("Z") ? "" : "+08:00")));
}

function actionLabel(value = "") {
  return value.replaceAll("_", " ").toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function deviceLabel(userAgent) {
  if (!userAgent) return "Not recorded";
  const device = /Android|iPhone|iPad/i.test(userAgent) ? "Mobile device" : "Desktop browser";
  const browser = /Edg/i.test(userAgent)
    ? "Edge"
    : /Chrome/i.test(userAgent) ? "Chrome" : /Firefox/i.test(userAgent) ? "Firefox" : /Safari/i.test(userAgent) ? "Safari" : "Browser";
  return `${device} · ${browser}`;
}

function displayValue(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
}

export default function ActivityLogs() {
  const [logs, setLogs] = useState([]);
  const [summary, setSummary] = useState({ total: 0, today: 0, system: 0, attendance: 0 });
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
  const [search, setSearch] = useState("");
  const [source, setSource] = useState("");
  const [userId, setUserId] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedLog, setSelectedLog] = useState(null);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      const params = new URLSearchParams({ page: String(page), per_page: "20" });
      if (search.trim()) params.set("search", search.trim());
      if (source) params.set("source", source);
      if (userId) params.set("user_id", userId);
      if (dateFrom) params.set("date_from", dateFrom);
      if (dateTo) params.set("date_to", dateTo);

      try {
        const payload = await apiFetch(`/activity-logs?${params}`, { signal: controller.signal })
          .then(readResponse);
        setLogs(payload.data);
        setSummary(payload.summary);
        setUsers(payload.users);
        setMeta(payload.meta);
        setError("");
      } catch (requestError) {
        if (requestError.name !== "AbortError") setError(requestError.message);
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }, 250);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [search, source, userId, dateFrom, dateTo, page, refreshKey]);

  const cards = useMemo(() => [
    { label: "All events", value: summary.total, icon: Activity, tone: "blue" },
    { label: "Today", value: summary.today, icon: Clock3, tone: "green" },
    { label: "System actions", value: summary.system, icon: ShieldCheck, tone: "purple" },
    { label: "Attendance changes", value: summary.attendance, icon: FileClock, tone: "orange" },
  ], [summary]);

  function clearFilters() {
    setSearch("");
    setSource("");
    setUserId("");
    setDateFrom("");
    setDateTo("");
    setPage(1);
  }

  function updateFilter(setter, value) {
    setter(value);
    setPage(1);
  }

  return (
    <section className="activity-page">
      <div className="activity-hero">
        <div>
          <span><Fingerprint size={14} /> Administrator audit trail</span>
          <h1>Activity Logs</h1>
          <p>Review system changes, attendance revisions, operators, devices, and access information.</p>
        </div>
        <button type="button" onClick={() => {
          setLoading(true);
          setRefreshKey((key) => key + 1);
        }}><RefreshCw size={17} />Refresh logs</button>
      </div>

      {error && <div className="users-notice error"><X size={18} />{error}</div>}

      <div className="activity-summary-grid">
        {cards.map(({ label, value, icon: Icon, tone }) => (
          <article key={label}>
            <span className={tone}><Icon size={20} /></span>
            <div><strong>{loading ? "—" : value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="panel activity-panel">
        <div className="activity-filter-heading">
          <div><SlidersHorizontal size={17} /><span><strong>Audit filters</strong><small>Narrow activity by source, operator, or date.</small></span></div>
          <button type="button" onClick={clearFilters}>Clear filters</button>
        </div>
        <div className="activity-filters">
          <label className="activity-search"><Search size={16} /><input value={search} onChange={(event) => updateFilter(setSearch, event.target.value)} placeholder="Search action, user, IP, or description…" /></label>
          <select value={source} onChange={(event) => updateFilter(setSource, event.target.value)}>
            <option value="">All sources</option>
            <option>System</option>
            <option>Attendance</option>
          </select>
          <select value={userId} onChange={(event) => updateFilter(setUserId, event.target.value)}>
            <option value="">All operators</option>
            {users.map((user) => <option key={user.user_id} value={user.user_id}>{user.full_name || user.username}</option>)}
          </select>
          <label className="activity-date"><CalendarDays size={14} /><input type="date" value={dateFrom} onChange={(event) => updateFilter(setDateFrom, event.target.value)} aria-label="Start date" /></label>
          <label className="activity-date"><CalendarDays size={14} /><input type="date" min={dateFrom} value={dateTo} onChange={(event) => updateFilter(setDateTo, event.target.value)} aria-label="End date" /></label>
        </div>

        <div className="users-table-wrap">
          <table className="users-table activity-table">
            <thead><tr><th>Date & time</th><th>Operator</th><th>Action</th><th>Description</th><th>Source</th><th>IP address</th><th><span className="sr-only">Details</span></th></tr></thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="users-empty">Loading audit activity…</td></tr>
              ) : logs.length ? logs.map((log) => (
                <tr key={log.log_key}>
                  <td><time className="activity-time">{formatDateTime(log.created_at)}</time></td>
                  <td><div className="activity-actor"><span><UserRound size={14} /></span><div><strong>{log.full_name || log.username || "System"}</strong><small>{log.username || "Automated activity"}</small></div></div></td>
                  <td><span className="activity-action">{actionLabel(log.action)}</span></td>
                  <td><p className="activity-description">{log.description}</p></td>
                  <td><span className={`activity-source ${log.source.toLowerCase()}`}>{log.source}</span></td>
                  <td><code>{log.ip_address || "—"}</code></td>
                  <td><button type="button" className="activity-view-button" onClick={() => setSelectedLog(log)} aria-label="View activity details"><Eye size={15} /></button></td>
                </tr>
              )) : <tr><td colSpan="7" className="users-empty">No activity matches the selected filters.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="activity-pagination">
          <span>{meta.total ? `Showing ${meta.from}–${meta.to} of ${meta.total}` : "No results"}</span>
          <div>
            <button type="button" onClick={() => setPage((value) => value - 1)} disabled={meta.current_page <= 1}><ChevronLeft size={16} />Previous</button>
            <strong>Page {meta.current_page} of {meta.last_page}</strong>
            <button type="button" onClick={() => setPage((value) => value + 1)} disabled={meta.current_page >= meta.last_page}>Next<ChevronRight size={16} /></button>
          </div>
        </div>
      </div>

      {selectedLog && (
        <div className="modal-backdrop activity-modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setSelectedLog(null);
        }}>
          <div className="user-modal activity-modal" role="dialog" aria-modal="true" aria-labelledby="activity-detail-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><Fingerprint size={21} /></span>
                <div><h2 id="activity-detail-title">Activity details</h2><p>{formatDateTime(selectedLog.created_at)}</p></div>
              </div>
              <button type="button" onClick={() => setSelectedLog(null)} aria-label="Close"><X size={20} /></button>
            </div>

            <div className="activity-detail-body">
              <div className="activity-detail-banner">
                <span className={`activity-source ${selectedLog.source.toLowerCase()}`}>{selectedLog.source}</span>
                <h3>{actionLabel(selectedLog.action)}</h3>
                <p>{selectedLog.description}</p>
              </div>
              <div className="activity-detail-grid">
                <div><small>Operator</small><strong>{selectedLog.full_name || selectedLog.username || "System"}</strong><span>{selectedLog.username || "Automated activity"}</span></div>
                <div><small>Entity</small><strong>{selectedLog.entity_type || "—"}</strong><span>{selectedLog.entity_id ? `Record #${selectedLog.entity_id}` : "No record ID"}</span></div>
                <div><small>IP address</small><strong>{selectedLog.ip_address || "Not recorded"}</strong><span>Request origin</span></div>
                <div><small>Device</small><strong>{deviceLabel(selectedLog.user_agent)}</strong><span title={selectedLog.user_agent || ""}>{selectedLog.user_agent ? "User agent recorded" : "No user agent"}</span></div>
              </div>

              {selectedLog.reason && <div className="activity-reason"><strong>Reason</strong><p>{selectedLog.reason}</p></div>}
              {(selectedLog.old_values || selectedLog.new_values) && (
                <div className="activity-changes">
                  <div><MonitorSmartphone size={16} /><h3>Recorded changes</h3></div>
                  <table>
                    <thead><tr><th>Field</th><th>Previous value</th><th>New value</th></tr></thead>
                    <tbody>
                      {[...new Set([
                        ...Object.keys(selectedLog.old_values || {}),
                        ...Object.keys(selectedLog.new_values || {}),
                      ])].map((field) => (
                        <tr key={field}>
                          <td>{actionLabel(field)}</td>
                          <td>{displayValue(selectedLog.old_values?.[field])}</td>
                          <td>{displayValue(selectedLog.new_values?.[field])}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
