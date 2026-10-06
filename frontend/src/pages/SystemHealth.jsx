import { useEffect, useState } from "react";
import {
  Activity,
  CalendarClock,
  CheckCircle2,
  CircleAlert,
  Clock3,
  Database,
  DatabaseBackup,
  FolderCheck,
  GitCommitHorizontal,
  RefreshCw,
  Server,
  ShieldCheck,
  TriangleAlert,
  XCircle,
} from "lucide-react";
import { apiFetch } from "../lib/auth";

const CHECK_ICONS = {
  api: Server,
  database: Database,
  scheduler: CalendarClock,
  backup: DatabaseBackup,
  secure_proxy: ShieldCheck,
  storage: FolderCheck,
};

const STATUS_COPY = {
  healthy: {
    title: "All monitored systems are healthy",
    description: "Core attendance services are responding within their expected limits.",
  },
  warning: {
    title: "Some checks need attention",
    description: "Core services are available, but at least one operational safeguard needs review.",
  },
  critical: {
    title: "A critical system check failed",
    description: "Review the affected service before relying on the system for office attendance.",
  },
};

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.message || "System health could not be loaded.");
  }

  return payload;
}

function formatDateTime(value) {
  if (!value) return "No successful heartbeat yet";

  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "medium",
    timeZone: "Asia/Manila",
  }).format(new Date(value));
}

function relativeAge(value, checkedAt) {
  if (!value) return "Awaiting first report";

  const seconds = Math.max(0, Math.floor((new Date(checkedAt) - new Date(value)) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

function StatusIcon({ status, size = 18 }) {
  if (status === "healthy") return <CheckCircle2 size={size} />;
  if (status === "critical") return <XCircle size={size} />;
  return <TriangleAlert size={size} />;
}

export default function SystemHealth() {
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);

  function refreshChecks() {
    setLoading(true);
    setError("");
    setRefreshKey((key) => key + 1);
  }

  useEffect(() => {
    const controller = new AbortController();

    apiFetch("/system-health", { signal: controller.signal })
      .then(readResponse)
      .then(setHealth)
      .catch((requestError) => {
        if (requestError.name !== "AbortError") setError(requestError.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [refreshKey]);

  useEffect(() => {
    const interval = window.setInterval(() => {
      setLoading(true);
      setError("");
      setRefreshKey((key) => key + 1);
    }, 60_000);
    return () => window.clearInterval(interval);
  }, []);

  const overall = health?.overall_status || "warning";
  const statusCopy = STATUS_COPY[overall] || STATUS_COPY.warning;
  const checkedLabel = health?.checked_at ? formatDateTime(health.checked_at) : "Not checked yet";

  return (
    <section className="system-health-page">
      <header className={`system-health-hero ${overall}`}>
        <div>
          <span className="system-health-kicker"><Activity size={15} /> Operations monitoring</span>
          <h1>System Health</h1>
          <p>Administrator-only visibility into the API, database, scheduler, secure proxy, runtime storage, and verified backups.</p>
        </div>
        <div className="system-health-overall">
          <span><StatusIcon status={overall} size={24} /></span>
          <div>
            <small>Current status</small>
            <strong>{loading && !health ? "Checking…" : statusCopy.title}</strong>
            <em>{health?.environment || "environment"}{health?.release ? ` · ${health.release}` : ""}</em>
          </div>
        </div>
      </header>

      {error && (
        <div className="system-health-error" role="alert">
          <CircleAlert size={19} />
          <div><strong>Health data could not be loaded</strong><span>{error}</span></div>
          <button type="button" onClick={refreshChecks}>Try again</button>
        </div>
      )}

      {health && (
        <>
          <section className="system-health-summary" aria-label="Health summary">
            <article className="healthy"><CheckCircle2 size={20} /><div><strong>{health.summary.healthy}</strong><span>Healthy checks</span></div></article>
            <article className="warning"><TriangleAlert size={20} /><div><strong>{health.summary.warning}</strong><span>Need attention</span></div></article>
            <article className="critical"><XCircle size={20} /><div><strong>{health.summary.critical}</strong><span>Critical checks</span></div></article>
            <article className="checked"><Clock3 size={20} /><div><strong>{checkedLabel}</strong><span>Last refreshed</span></div></article>
          </section>

          <section className="system-health-panel">
            <div className="system-health-panel-heading">
              <div>
                <span>Live safeguards</span>
                <h2>{statusCopy.title}</h2>
                <p>{statusCopy.description}</p>
              </div>
              <button type="button" onClick={refreshChecks} disabled={loading}>
                <RefreshCw size={16} className={loading ? "spinning" : ""} />
                {loading ? "Checking…" : "Refresh checks"}
              </button>
            </div>

            <div className="system-health-grid">
              {health.checks.map((check) => {
                const Icon = CHECK_ICONS[check.key] || Activity;
                const detailEntries = Object.entries(check.details || {});

                return (
                  <article className={`system-health-check ${check.status}`} key={check.key}>
                    <div className="system-health-check-icon"><Icon size={21} /></div>
                    <div className="system-health-check-copy">
                      <div>
                        <h3>{check.label}</h3>
                        <span className={`system-health-badge ${check.status}`}>
                          <StatusIcon status={check.status} size={14} />{check.status}
                        </span>
                      </div>
                      <p>{check.message}</p>
                      {check.last_success_at && (
                        <small title={formatDateTime(check.last_success_at)}>
                          Last success: {relativeAge(check.last_success_at, health.checked_at)}
                        </small>
                      )}
                      {!!detailEntries.length && (
                        <dl>
                          {detailEntries.map(([key, value]) => (
                            <div key={key}>
                              <dt>{key.replaceAll("_", " ")}</dt>
                              <dd>{key === "commit_sha" ? <><GitCommitHorizontal size={12} />{value}</> : String(value)}</dd>
                            </div>
                          ))}
                        </dl>
                      )}
                    </div>
                  </article>
                );
              })}
            </div>
          </section>

          <aside className="system-health-security-note">
            <ShieldCheck size={22} />
            <div>
              <strong>Security-safe diagnostics</strong>
              <span>This page reports service state and sanitized timing only. Database hosts, usernames, passwords, signing secrets, and storage credentials are never returned to the browser.</span>
            </div>
          </aside>
        </>
      )}
    </section>
  );
}
