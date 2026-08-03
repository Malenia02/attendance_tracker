import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  BadgeCheck,
  Building2,
  CalendarClock,
  CalendarDays,
  ClipboardCheck,
  Clock3,
  FileCheck2,
  FileWarning,
  ListChecks,
  LockKeyhole,
  QrCode,
  ShieldCheck,
  Sparkles,
  TimerReset,
  UserCheck,
  UserRoundCog,
  Users,
  X,
} from "lucide-react";
import { apiFetch, getStoredUser } from "../lib/auth";
import { formatDuration } from "../lib/duration";

const QUEUE_LABELS = {
  attendance_verification: ["Attendance verification", "/attendance", ClipboardCheck],
  correction_requests: ["Correction requests", "/action-center?queue=correction_requests", TimerReset],
  leave_requests: ["Leave requests", "/leave-requests", CalendarDays],
  submitted_dtrs: ["Submitted DTRs", "/dtr", FileCheck2],
  returned_dtrs: ["Returned DTRs", "/dtr", FileWarning],
};

const VIEW_CONTENT = {
  personal: {
    eyebrow: "My attendance workspace",
    title: "Personal attendance",
    description: "Your hours, schedule, leave, and DTR progress in one private view.",
  },
  supervisor: {
    eyebrow: "Department operations",
    title: "Supervisor overview",
    description: "Monitor your assigned office and act on department approvals.",
  },
  hr: {
    eyebrow: "Workforce workflow",
    title: "HR operations",
    description: "Prioritize verification, correction, leave, and DTR queues.",
  },
  administrator: {
    eyebrow: "Operations and security",
    title: "Administrator command center",
    description: "System-wide attendance, workforce, workflow, and access health.",
  },
};

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || "The dashboard could not be loaded.");
  return payload;
}

function formatDate(value, options = { month: "short", day: "numeric", year: "numeric" }) {
  if (!value) return "—";
  return new Date(`${value}T00:00:00`).toLocaleDateString("en-PH", options);
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

function MetricCard({ label, value, helper, icon: Icon, tone = "blue", path }) {
  const navigate = useNavigate();
  const content = (
    <>
      <span className="role-metric-icon"><Icon size={21} /></span>
      <span><small>{label}</small><strong>{value}</strong><em>{helper}</em></span>
      {path && <ArrowRight size={15} />}
    </>
  );

  return path ? (
    <button type="button" className={`role-metric ${tone}`} onClick={() => navigate(path)}>
      {content}
    </button>
  ) : <div className={`role-metric ${tone}`}>{content}</div>;
}

function QueueGrid({ queues, compact = false }) {
  const navigate = useNavigate();

  return (
    <div className={`role-queue-grid ${compact ? "compact" : ""}`}>
      {Object.entries(queues || {}).map(([key, count]) => {
        const [label, path, Icon] = QUEUE_LABELS[key] || [key, "/action-center", ListChecks];
        return (
          <button type="button" key={key} onClick={() => navigate(path)}>
            <span><Icon size={18} /></span>
            <div><strong>{count}</strong><small>{label}</small></div>
            <ArrowRight size={14} />
          </button>
        );
      })}
    </div>
  );
}

function QueuePreview({ items }) {
  const navigate = useNavigate();

  return (
    <article className="role-panel role-preview-panel">
      <header>
        <div><span>Oldest first</span><h2>Items requiring attention</h2></div>
        <button type="button" onClick={() => navigate("/action-center")}>Open Action Center <ArrowRight size={14} /></button>
      </header>
      <div className="role-preview-list">
        {(items || []).map((item) => (
          <button type="button" key={`${item.type}-${item.id}`} onClick={() => navigate(item.path)}>
            <span>{item.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span>
            <div><strong>{item.full_name}</strong><small>{item.type} · {item.detail}</small></div>
            <ArrowRight size={14} />
          </button>
        ))}
        {!items?.length && (
          <div className="role-empty-state"><BadgeCheck size={27} /><strong>Queues are clear</strong><small>No pending items need attention.</small></div>
        )}
      </div>
    </article>
  );
}

function PersonalDashboard({ data }) {
  const navigate = useNavigate();

  if (!data.profile?.linked) {
    return (
      <div className="role-link-warning">
        <AlertTriangle size={24} />
        <div><strong>Your account is not linked to a personnel record.</strong><p>Ask an administrator to link your account before using personal attendance features.</p></div>
      </div>
    );
  }

  const today = data.today_attendance;
  const schedule = data.schedule;

  return (
    <>
      <div className="role-metric-grid">
        <MetricCard label="Hours worked" value={formatDuration(data.metrics.work_minutes)} helper={data.period.month_label} icon={Clock3} tone="blue" path="/attendance" />
        <MetricCard label="Days present" value={data.metrics.days_present} helper="Present and half days" icon={UserCheck} tone="green" path="/attendance" />
        <MetricCard label="Late" value={formatDuration(data.metrics.late_minutes)} helper="This month" icon={CalendarClock} tone="orange" path="/attendance" />
        <MetricCard label="Undertime" value={formatDuration(data.metrics.undertime_minutes)} helper="This month" icon={TimerReset} tone="violet" path="/attendance" />
      </div>

      <div className="role-content-grid personal-grid">
        <article className="role-panel today-card">
          <header><div><span>Today</span><h2>{today.status}</h2></div><Activity size={20} /></header>
          <div className="personal-time-grid">
            <span><small>Morning in</small><strong>{today.morning_in || "—"}</strong></span>
            <span><small>Morning out</small><strong>{today.morning_out || "—"}</strong></span>
            <span><small>Afternoon in</small><strong>{today.afternoon_in || "—"}</strong></span>
            <span><small>Afternoon out</small><strong>{today.afternoon_out || "—"}</strong></span>
          </div>
          <button type="button" className="role-primary-action" onClick={() => navigate("/attendance")}>Open attendance <ArrowRight size={14} /></button>
        </article>

        <article className="role-panel schedule-card">
          <header><div><span>Current assignment</span><h2>Work schedule</h2></div><CalendarClock size={20} /></header>
          {schedule.assigned ? (
            <div className="personal-schedule">
              <strong>{schedule.name}</strong>
              <p>{schedule.working_days.join(" · ")}</p>
              <span><small>Morning</small>{schedule.morning || "Not configured"}</span>
              <span><small>Afternoon</small>{schedule.afternoon || "Not configured"}</span>
              <em>{formatDuration(schedule.required_minutes)} required per duty day</em>
            </div>
          ) : <div className="role-empty-state"><CalendarClock size={25} /><strong>No active schedule</strong><small>Contact HR or your administrator.</small></div>}
        </article>

        <article className="role-panel leave-dtr-card">
          <header><div><span>Requests and records</span><h2>Leave and DTR</h2></div><FileCheck2 size={20} /></header>
          <div className="personal-workflow-summary">
            <button type="button" onClick={() => navigate("/leave-requests")}><strong>{data.leave.pending}</strong><small>Pending leave</small></button>
            <button type="button" onClick={() => navigate("/leave-requests")}><strong>{data.leave.approved}</strong><small>Approved leave</small></button>
            <button type="button" onClick={() => navigate("/dtr")}><strong>{data.dtr.status}</strong><small>{data.period.month_label} DTR</small></button>
          </div>
        </article>

        <article className="role-panel personal-history-card">
          <header><div><span>{data.period.month_label}</span><h2>Recent attendance</h2></div><button type="button" onClick={() => navigate("/attendance")}>View all <ArrowRight size={14} /></button></header>
          <div className="personal-history-list">
            {data.recent_attendance.map((record) => (
              <div key={record.id}>
                <time>{formatDate(record.date, { month: "short", day: "2-digit" })}</time>
                <span><strong>{record.status}</strong><small>{formatDuration(record.work_minutes)} worked</small></span>
                <span className="history-flags">
                  {!!record.late_minutes && <em>{formatDuration(record.late_minutes)} late</em>}
                  {!!record.undertime_minutes && <em>{formatDuration(record.undertime_minutes)} under</em>}
                  {record.verified && <BadgeCheck size={15} />}
                </span>
              </div>
            ))}
            {!data.recent_attendance.length && <div className="role-empty-state"><Clock3 size={25} /><strong>No attendance yet</strong><small>Your monthly records will appear here.</small></div>}
          </div>
        </article>
      </div>
    </>
  );
}

function SupervisorDashboard({ data }) {
  if (!data.department?.linked) {
    return <div className="role-link-warning"><AlertTriangle size={24} /><div><strong>No department assignment</strong><p>A supervisor must be linked to personnel in an office before department data can be displayed.</p></div></div>;
  }

  return (
    <>
      <div className="role-metric-grid">
        <MetricCard label="Active personnel" value={data.metrics.active_personnel} helper={data.department.code} icon={Users} tone="blue" />
        <MetricCard label="Present today" value={data.metrics.present_today} helper="Present and half day" icon={UserCheck} tone="green" path="/attendance" />
        <MetricCard label="Late today" value={data.metrics.late_today} helper="Department arrivals" icon={Clock3} tone="orange" path="/attendance" />
        <MetricCard label="Incomplete" value={data.metrics.incomplete_today} helper="Needs follow-up" icon={AlertTriangle} tone="violet" path="/attendance" />
      </div>
      <div className="role-content-grid supervisor-grid">
        <article className="role-panel supervisor-queue-panel">
          <header><div><span>My department</span><h2>Pending approvals</h2></div><ListChecks size={20} /></header>
          <QueueGrid queues={data.queues} compact />
        </article>
        <article className="role-panel status-panel">
          <header><div><span>Today</span><h2>Attendance distribution</h2></div><Activity size={20} /></header>
          <div className="status-breakdown">
            {Object.entries(data.attendance_statuses || {}).map(([status, total]) => (
              <div key={status}><span>{status}</span><i><b style={{ width: `${Math.min(100, (total / Math.max(1, data.metrics.active_personnel)) * 100)}%` }}></b></i><strong>{total}</strong></div>
            ))}
            {!Object.keys(data.attendance_statuses || {}).length && <div className="role-empty-state"><Activity size={25} /><strong>No records today</strong><small>Attendance will appear after personnel time in.</small></div>}
          </div>
        </article>
        <article className="role-panel supervisor-leave-panel">
          <header><div><span>Oldest first</span><h2>Pending leave requests</h2></div><CalendarDays size={20} /></header>
          <div className="supervisor-leave-list">
            {data.pending_leave.map((leave) => (
              <div key={leave.id}><span>{leave.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span><div><strong>{leave.full_name}</strong><small>{leave.type} · {formatDate(leave.date_from)} – {formatDate(leave.date_to)}</small></div></div>
            ))}
            {!data.pending_leave.length && <div className="role-empty-state"><BadgeCheck size={25} /><strong>No pending leave</strong><small>Your department queue is clear.</small></div>}
          </div>
        </article>
      </div>
    </>
  );
}

function HrDashboard({ data }) {
  const total = Object.values(data.queues || {}).reduce((sum, value) => sum + Number(value || 0), 0);
  return (
    <>
      <div className="role-summary-strip"><span><ListChecks size={20} /></span><div><small>Open workflow items</small><strong>{total}</strong><p>Across verification, corrections, leave, and DTR review.</p></div></div>
      <QueueGrid queues={data.queues} />
      <QueuePreview items={data.queue_preview} />
    </>
  );
}

function AdministratorDashboard({ data }) {
  const navigate = useNavigate();

  return (
    <>
      <div className="admin-section-heading"><div><span>Live operations</span><h2>System-wide overview</h2></div><Activity size={21} /></div>
      <div className="role-metric-grid admin-metrics">
        <MetricCard label="Active personnel" value={data.operations.active_personnel} helper="System-wide" icon={Users} tone="blue" path="/personnel" />
        <MetricCard label="Active offices" value={data.operations.active_departments} helper="Configured departments" icon={Building2} tone="green" path="/departments" />
        <MetricCard label="Present today" value={data.operations.present_today} helper={`${data.operations.attendance_recorded_today} records`} icon={UserCheck} tone="green" path="/attendance" />
        <MetricCard label="Late today" value={data.operations.late_today} helper="Requires monitoring" icon={Clock3} tone="orange" path="/attendance" />
        <MetricCard label="Incomplete" value={data.operations.incomplete_today} helper="Attendance exceptions" icon={AlertTriangle} tone="violet" path="/attendance" />
        <MetricCard label="Unassigned" value={data.operations.unassigned_personnel} helper="No department" icon={Building2} tone="orange" path="/personnel" />
        <MetricCard label="No schedule" value={data.operations.without_schedule} helper="Active personnel" icon={CalendarClock} tone="violet" path="/schedules" />
      </div>

      <div className="admin-dashboard-grid">
        <article className="role-panel admin-security-panel">
          <header><div><span>Access protection</span><h2>Security overview</h2></div><ShieldCheck size={21} /></header>
          <div className="security-metric-grid">
            <button type="button" onClick={() => navigate("/system-users")}><UserRoundCog size={19} /><strong>{data.security.active_users}</strong><small>Active users</small></button>
            <button type="button" onClick={() => navigate("/system-users")}><LockKeyhole size={19} /><strong>{data.security.locked_users}</strong><small>Locked users</small></button>
            <button type="button" onClick={() => navigate("/system-users")}><X size={19} /><strong>{data.security.inactive_users}</strong><small>Inactive users</small></button>
            <button type="button" onClick={() => navigate("/activity-logs")}><ShieldCheck size={19} /><strong>{data.security.security_events_24h}</strong><small>Security events · 24h</small></button>
          </div>
          <p className="security-footnote"><BadgeCheck size={15} />Dashboard data is server-scoped to your Administrator session.</p>
        </article>
        <article className="role-panel admin-queue-panel">
          <header><div><span>Operations backlog</span><h2>Workflow queues</h2></div><ListChecks size={21} /></header>
          <QueueGrid queues={data.queues} compact />
        </article>
      </div>
      <QueuePreview items={data.queue_preview} />
    </>
  );
}

export default function Dashboard() {
  const navigate = useNavigate();
  const currentUser = getStoredUser();
  const displayName = currentUser?.personnel?.full_name || currentUser?.username || "System User";
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [now, setNow] = useState(new Date());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    apiFetch("/dashboard", { signal: controller.signal })
      .then(readResponse)
      .then(setData)
      .catch((requestError) => {
        if (requestError.name !== "AbortError") setError(requestError.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, []);

  if (loading) {
    return <section className="ops-dashboard"><div className="dashboard-loading"><span><Activity size={25} /></span><strong>Preparing your secured dashboard…</strong><small>Loading your role-specific workspace</small></div></section>;
  }

  if (error || !data) {
    return <section className="ops-dashboard"><div className="users-notice error"><X size={18} />{error || "Dashboard data is unavailable."}</div></section>;
  }

  const view = VIEW_CONTENT[data.view] || VIEW_CONTENT.personal;
  const quickActions = data.view === "personal"
    ? [["Attendance", Clock3, "/attendance"], ["QR attendance", QrCode, "/qr-attendance"], ["Leave", CalendarDays, "/leave-requests"], ["My DTR", FileCheck2, "/dtr"]]
    : data.view === "supervisor"
      ? [["Action Center", ListChecks, "/action-center"], ["Attendance", Clock3, "/attendance"], ["Leave approvals", CalendarDays, "/leave-requests"], ["DTR", FileCheck2, "/dtr"]]
      : data.view === "hr"
        ? [["Action Center", ListChecks, "/action-center"], ["Verify attendance", ClipboardCheck, "/attendance"], ["Leave queue", CalendarDays, "/leave-requests"], ["DTR queue", FileCheck2, "/dtr"]]
        : [["Action Center", ListChecks, "/action-center"], ["Personnel", Users, "/personnel"], ["System users", UserRoundCog, "/system-users"], ["Activity logs", ShieldCheck, "/activity-logs"]];

  return (
    <section className={`ops-dashboard role-dashboard role-${data.view}`}>
      <header className="ops-dashboard-hero role-dashboard-hero">
        <div>
          <span className="ops-eyebrow"><Sparkles size={14} /> {view.eyebrow}</span>
          <h1>{greeting()}, {displayName.split(" ")[0]}</h1>
          <p>{view.description}</p>
          <div className="ops-hero-meta"><span><ShieldCheck size={14} /> {data.role} access</span><span><BadgeCheck size={14} /> {data.scope}</span></div>
        </div>
        <div className="ops-live-clock"><small>{data.period.today_label}</small><strong>{now.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}</strong><span>{view.title}</span></div>
        <i></i>
      </header>

      <nav className="ops-quick-actions" aria-label="Role quick actions">
        {quickActions.map(([label, Icon, path]) => (
          <button type="button" key={label} onClick={() => navigate(path)}><span><Icon size={17} /></span><b>{label}</b><ArrowRight size={14} /></button>
        ))}
      </nav>

      {data.view === "personal" && <PersonalDashboard data={data} />}
      {data.view === "supervisor" && <SupervisorDashboard data={data} />}
      {data.view === "hr" && <HrDashboard data={data} />}
      {data.view === "administrator" && <AdministratorDashboard data={data} />}
    </section>
  );
}
